<?php
// pages/api/send_to_mahnung.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

// Ensure user is authenticated
$userId = check_remember_me($pdo);
if (!$userId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'unauthenticated']);
    exit;
}

// Accept POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

// Input validation
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$invoiceId = isset($payload['invoice_id']) ? (int)$payload['invoice_id'] : 0;
$rechnungsnummer = isset($payload['rechnungsnummer']) ? trim((string)$payload['rechnungsnummer']) : '';
$stufe = isset($payload['stufe']) ? (int)$payload['stufe'] : 0;
$dueDays = isset($payload['due_days']) ? (int)$payload['due_days'] : 7;
$sendNow = !empty($payload['send_now']) ? '1' : '0';
$text = isset($payload['text']) ? trim((string)$payload['text']) : '';

// If either rechnungsnummer is provided, or invoice_id can be resolved to one, delegate to mahnung_speichern.php
try {
    if ($rechnungsnummer === '' && $invoiceId > 0) {
        $st = $pdo->prepare('SELECT rechnungsnummer FROM rechnungen WHERE id = ? LIMIT 1');
        $st->execute([$invoiceId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['rechnungsnummer'])) {
            $rechnungsnummer = (string)$row['rechnungsnummer'];
        }
    }

    if ($rechnungsnummer !== '') {
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = $host ? ($scheme . '://' . $host) : '';
        $endpoint = $baseUrl ? ($baseUrl . '/pages/mahnung_speichern.php') : null;

        $postData = http_build_query([
            'rechnungsnummer' => $rechnungsnummer,
            'stufe' => $stufe,
            'due_days' => $dueDays,
            'send_now' => $sendNow,
            'note' => $text,
        ]);

        $response = false;
        if ($endpoint) {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => $postData,
                    'timeout' => 30
                ]
            ]);
            $response = @file_get_contents($endpoint, false, $ctx);
        }

        if ($response === false) {
            // Fallback to CLI runner
            $cmd = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../scripts/run_mahnung_save.php') . ' ' . escapeshellarg($rechnungsnummer) . ' ' . (int)$stufe . ' ' . (int)$dueDays;
            @exec($cmd, $outLines, $code);
            if ($code === 0) {
                $response = implode("\n", (array)$outLines);
            }
        }

        if ($response === false) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'mahnung_save_unreachable']);
            exit;
        }

        $decoded = json_decode($response, true);
        if (is_array($decoded)) {
            echo json_encode($decoded);
        } else {
            echo json_encode(['success' => true, 'raw' => $response]);
        }
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server', 'message' => $e->getMessage()]);
    exit;
}

// If we get here, neither invoice_id nor rechnungsnummer usable
http_response_code(400);
echo json_encode(['success' => false, 'error' => 'missing_rechnungsnummer_or_invoice_id']);
exit;

// Detect mahnungen table invoice FK column name
function detect_col(PDO $pdo, string $table, array $cands) {
    foreach ($cands as $c) {
        try {
            $st = $pdo->prepare("
                SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1
            ");
            $st->execute([':t' => $table, ':c' => $c]);
            if ($st->fetchColumn()) return $c;
        } catch (Throwable $e) { continue; }
    }
    return null;
}

$mahnTable = 'mahnungen';
$invoiceFkCol = detect_col($pdo, $mahnTable, ['rechnung_id','invoice_id','rechnungs_id']);
$stufeCol     = detect_col($pdo, $mahnTable, ['stufe','mahn_stufe','level']);
$dateCol      = detect_col($pdo, $mahnTable, ['date','datum','created_at']);

// Build insert dynamically
$cols = [];
$vals = [];
$params = [];

// always add date if available, else let DB default if exists
if ($dateCol) {
    $cols[] = $dateCol;
    $vals[] = 'NOW()';
}

// invoice fk column (must exist or we'll try to insert without it which is probably wrong)
if ($invoiceFkCol) {
    $cols[] = $invoiceFkCol;
    $vals[] = ':invoice_id';
    $params[':invoice_id'] = $invoiceId;
} else {
    // if no invoice FK column in mahnungen table, return error
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'mahnungen_table_missing_invoice_fk']);
    exit;
}

// stufe column (set to 0)
if ($stufeCol) {
    $cols[] = $stufeCol;
    $vals[] = ':stufe';
    $params[':stufe'] = 0;
}

// text column optional
$textCol = detect_col($pdo, $mahnTable, ['text','inhalt','message','bemerkung']);
if ($textCol) {
    $cols[] = $textCol;
    $vals[] = ':text';
    $params[':text'] = $text;
}

// Attempt insert
$colSql = implode(', ', array_map(function($c){ return "`$c`"; }, $cols));
$valSql = implode(', ', $vals);

$sql = "INSERT INTO `{$mahnTable}` ({$colSql}) VALUES ({$valSql})";
try {
    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        // bind ints as int, strings as str
        if (is_int($v)) $st->bindValue($k, $v, PDO::PARAM_INT);
        else $st->bindValue($k, $v, PDO::PARAM_STR);
    }
    $st->execute();
    $insertId = (int)$pdo->lastInsertId();

    // Optional: mark invoice as 'mahnung_sent' or increase a flag (best-effort)
    // detect invoice status column
    $invTable = 'rechnungen';
    $invStatusCol = detect_col($pdo, $invTable, ['status','state']);
    if ($invStatusCol) {
        try {
            $upd = $pdo->prepare("UPDATE `{$invTable}` SET `{$invStatusCol}` = :s WHERE id = :id");
            $upd->execute([':s' => 'mahnung_sent', ':id' => $invoiceId]);
        } catch (Throwable $e) {
            // ignore update errors
        }
    }

    echo json_encode(['success' => true, 'id' => $insertId]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'insert_failed', 'details' => $e->getMessage()]);
    exit;
}
