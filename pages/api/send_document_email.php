<?php
// pages/api/send_document_email.php
// Unified email sender for: rechnung | angebot | lieferschein
// Uses config/smtp.php -> getMailer() (PHPMailer) and DB to resolve recipients

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Simple mail log helper
function mail_log(array $data): void {
    $logDir = __DIR__ . '/../../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . json_encode($data, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    @file_put_contents($logDir . '/mail.log', $line, FILE_APPEND);
}

try {
    require_once __DIR__ . '/../../config/db.php';
    require_once __DIR__ . '/../helpers.php';
    global $pdo;
    $smtpPath = __DIR__ . '/../../config/smtp.php';
    if (!file_exists($smtpPath)) {
        throw new RuntimeException('smtp_config_missing');
    }
    require_once $smtpPath; // defines getMailer()
    if (!function_exists('getMailer')) {
        throw new RuntimeException('getMailer_missing');
    }

    // Accept JSON and form-encoded
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true) ?: [];

    $docType = strtolower(trim((string)($json['doc_type'] ?? $_POST['doc_type'] ?? '')));
    // Accept recipient from field named "email" (preferred) or legacy "to"
    $toOverride = trim((string)($json['email'] ?? $_POST['email'] ?? $json['to'] ?? $_POST['to'] ?? ''));
    $attachPdf = (string)($json['attach_pdf'] ?? $_POST['attach_pdf'] ?? '1') === '1';

    // Collect document IDs from various possible parameter names used by different pages
    $ids = $json['ids']
        ?? $json['rechnung_ids']
        ?? $json['invoice_ids']
        ?? $json['angebot_ids']
        ?? $json['offer_ids']
        ?? $json['lieferschein_ids']
        ?? ($_POST['ids'] ?? null)
        ?? ($_POST['rechnung_ids'] ?? null)
        ?? ($_POST['invoice_ids'] ?? null)
        ?? ($_POST['angebot_ids'] ?? null)
        ?? ($_POST['offer_ids'] ?? null)
        ?? ($_POST['lieferschein_ids'] ?? null)
        ?? ($_POST['id'] ?? null)
        ?? ($_GET['ids'] ?? null)
        ?? ($_GET['id'] ?? []);

    // Normalize to array of strings
    if (is_string($ids)) {
        $ids = array_filter(array_map('trim', explode(',', $ids)));
    } elseif (is_numeric($ids)) {
        $ids = [(string)$ids];
    }

    if (!is_array($ids) || count($ids) === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'missing_ids', 'hint' => 'Provide ids as array under ids | rechnung_ids | angebot_ids | lieferschein_ids']);
        exit;
    }

    $allowed = ['rechnung','angebot','lieferschein','mahnung'];
    if (!in_array($docType, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_doc_type']);
        exit;
    }

    $map = [
        'rechnung' => [
            'table' => 'rechnungen',
            'alias' => 't',
            'id_col' => 'id',
            'number_field' => 'rechnungsnummer',
            'join' => 'LEFT JOIN clients c ON c.id = t.client_id',
            'select' => 'c.email AS client_email, c.name AS client_name, c.firma AS firma',
            'subject' => function(array $row): string { return 'Ihre Rechnung ' . ($row['rechnungsnummer'] ?? $row['id']); },
            'pdf' => function(array $row): ?string {
                $nr = $row['rechnungsnummer'] ?? $row['id'] ?? null;
                if (!$nr) return null;
                $candidates = [
                    __DIR__ . '/../../pdf/rechnung/rechnung_' . $nr . '.pdf',
                    __DIR__ . '/../../pdf/rechnungen/rechnung_' . $nr . '.pdf',
                    __DIR__ . '/../../pdf/rechnungen/' . $nr . '.pdf',
                ];
                foreach ($candidates as $p) if (is_file($p)) return $p;
                return null;
            },
        ],
        'mahnung' => [
            'table' => 'mahnungen',
            'alias' => 't',
            'id_col' => 'id',
            'number_field' => 'id',
            'join' => 'LEFT JOIN rechnungen r ON r.id = t.rechnung_id LEFT JOIN clients c ON c.id = r.client_id',
            'select' => 't.stufe AS stufe, t.datum AS datum, r.rechnungsnummer AS rechnungsnummer, c.email AS client_email, c.name AS client_name, c.firma AS firma',
            'subject' => function(array $row): string {
                $stufe = isset($row['stufe']) ? (int)$row['stufe'] : 0;
                $rn = $row['rechnungsnummer'] ?? $row['rechnung_nummer'] ?? $row['id'];
                if ($stufe === 0) return 'Zahlungserinnerung zu Ihrer Rechnung ' . $rn;
                if ($stufe === 1) return '1. Mahnung zu Ihrer Rechnung ' . $rn;
                if ($stufe === 2) return 'Letzte Mahnung zu Ihrer Rechnung ' . $rn;
                return 'Hinweis zu Ihrer Rechnung ' . $rn;
            },
            'pdf' => function(array $row): ?string {
                // Try stored pdf_path (web path) first
                $web = $row['pdf_path'] ?? '';
                if ($web && is_string($web)) {
                    $p = __DIR__ . '/../../' . ltrim($web, '/');
                    if (is_file($p)) return $p;
                }
                // Fallback: compute by convention using helpers
                $id = $row['id'] ?? null;
                if (!$id) return null;
                $stufe = (string)($row['stufe'] ?? '');
                $datum = (string)($row['datum'] ?? ($row['created_at'] ?? date('Y-m-d')));
                $path = pdf_file_path('mahnung', (int)$id, $stufe, $datum);
                return is_file($path) ? $path : null;
            },
        ],
        'angebot' => [
            'table' => 'angebote',
            'alias' => 't',
            'id_col' => 'id',
            'number_field' => 'angebotsnummer',
            'join' => 'LEFT JOIN clients c ON c.id = t.client_id',
            'select' => 'c.email AS client_email, c.name AS client_name, c.firma AS firma',
            'subject' => function(array $row): string { return 'Ihr Angebot ' . ($row['angebotsnummer'] ?? $row['id']); },
            'pdf' => function(array $row): ?string {
                $nr = $row['angebotsnummer'] ?? $row['id'] ?? null;
                if (!$nr) return null;
                $candidates = [
                    __DIR__ . '/../../pdf/angebote/angebot_' . $nr . '.pdf',
                    __DIR__ . '/../../pdf/angeboten/angebot_' . $nr . '.pdf',
                    __DIR__ . '/../../pdf/angebote/' . $nr . '.pdf',
                ];
                foreach ($candidates as $p) if (is_file($p)) return $p;
                return null;
            },
        ],
        'lieferschein' => [
            'table' => 'lieferscheine',
            'alias' => 't',
            'id_col' => 'id',
            'number_field' => 'lieferschein_nummer',
            'join' => 'LEFT JOIN clients c ON c.id = t.client_id',
            'select' => 'c.email AS client_email, c.name AS client_name, c.firma AS firma',
            'subject' => function(array $row): string { return 'Ihr Lieferschein ' . ($row['lieferschein_nummer'] ?? $row['id']); },
            'pdf' => function(array $row): ?string {
                $nr = $row['lieferschein_nummer'] ?? $row['id'] ?? null;
                if (!$nr) return null;
                $candidates = [
                    __DIR__ . '/../../pdf/lieferscheine/lieferschein_' . $nr . '.pdf',
                    __DIR__ . '/../../pdf/lieferscheine/' . $nr . '.pdf',
                ];
                foreach ($candidates as $p) if (is_file($p)) return $p;
                return null;
            },
        ],
    ];

    $cfg = $map[$docType];

    // Build SQL
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $select = $cfg['alias'] . '.*';
    if (!empty($cfg['select'])) {
        $select .= ', ' . $cfg['select'];
    }
    $sql = 'SELECT ' . $select . ' FROM `' . $cfg['table'] . '` ' . $cfg['alias'] . ' ' . ($cfg['join'] ?? '') . ' WHERE ' . $cfg['alias'] . '.' . $cfg['id_col'] . ' IN (' . $placeholders . ')';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($ids));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $byId = [];
    foreach ($rows as $r) {
        $byId[(string)($r[$cfg['id_col']] ?? '')] = $r;
    }

    $sent = 0; $failed = [];

    foreach ($ids as $id) {
        $key = (string)$id;
        $row = $byId[$key] ?? null;
        if (!$row) { $failed[] = ['id'=>$id,'error'=>'not_found']; continue; }

        // Resolve recipient
        $to = $toOverride;
        if ($to === '') {
            $to = (string)($row['client_email'] ?? $row['email'] ?? '');
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $failed[] = ['id'=>$id, 'error'=>'no_valid_email'];
            continue;
        }

        // Prepare email
        try {
            $mail = getMailer();

            // Enable verbose SMTP debug if APP_DEBUG=1
            $smtpDebug = '';
            if (getenv('APP_DEBUG') === '1') {
                $mail->SMTPDebug = 2; // show client/server exchange
                $mail->Debugoutput = function($str, $level) use (&$smtpDebug) {
                    $smtpDebug .= '[' . $level . '] ' . $str . "\n";
                };
            }

            $mail->addAddress($to);
            $mail->Subject = ($cfg['subject'])($row);
            $mail->isHTML(true);
            $mail->Body = 'Guten Tag,<br>anbei finden Sie Ihr Dokument.';
            $mail->AltBody = 'Guten Tag, anbei finden Sie Ihr Dokument.';

            if ($attachPdf) {
                $pdfPath = ($cfg['pdf'])($row);
                if ($pdfPath && is_file($pdfPath)) {
                    $mail->addAttachment($pdfPath);
                }
            }

            $mail->send();
            $sent++;
            mail_log(['event'=>'mail_sent','doc_type'=>$docType,'id'=>$id,'to'=>$to]);
        } catch (\Throwable $e) {
            $fail = ['id'=>$id, 'error'=>'send_failed', 'detail'=>$e->getMessage()];
            if (!empty($smtpDebug)) { $fail['smtp_debug'] = $smtpDebug; }
            $failed[] = $fail;
            mail_log(['event'=>'mail_failed','doc_type'=>$docType,'id'=>$id,'to'=>$to,'error'=>$e->getMessage()]);
        }
    }

    echo json_encode([
        'success' => $sent > 0 && count($failed) === 0,
        'sent'    => $sent,
        'failed'  => $failed,
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false, 'error'=>'server', 'detail'=>$e->getMessage()]);
}
