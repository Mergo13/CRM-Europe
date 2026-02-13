<?php
/**
 * pages/mahnung_speichern.php
 *
 * Creates a Mahnung (Zahlungserinnerung / Mahnfolge entry) for a given rechnungsnummer.
 * - Generates a PDF using FPDF and stores it under /pdf/
 * - Inserts a row into the `mahnungen` table (matching your CSV schema)
 * - Optionally sends the PDF by email (uses config/smtp.php -> getMailer())
 * - Returns JSON { success: bool, mahnung_id, pdf, total_due } or error details
 *
 * Notes:
 * - Expects DB config in ../config/db.php providing $dsn, $db_user, $db_pass
 * - Expects vendor autoload and FPDF in ../vendor/...
 * - Produces robust PDF URL using DOCUMENT_ROOT when possible to avoid repeated "pages/" segments
 */
$GLOBALS['PDF_UNICODE'] = false;
header('Content-Type: application/json; charset=utf-8');

// Allow both manual (web) POST and automated (CLI/cron) usage.
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$isCli = (PHP_SAPI === 'cli');
if (!$isCli && strtoupper($method) !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed', 'message' => 'Bitte per POST aufrufen.']);
    exit;
}

// avoid accidental output before JSON
ob_start();

// simple logging helper
$logFile = __DIR__ . '/../logs/mahnung_speichern.log';
function log_msg($txt) {
    global $logFile;
    @file_put_contents($logFile, date('Y-m-d H:i:s') . ' ' . $txt . PHP_EOL, FILE_APPEND | LOCK_EX);
}

try {
    // ---- prerequisites ----
    // Load DB config
    $dbConfigPath = __DIR__ . '/../config/db.php';
    if (!file_exists($dbConfigPath)) {
        throw new Exception("Missing DB config: {$dbConfigPath}");
    }
    require_once $dbConfigPath;

    // vendor autoload and FPDF
    $autoload = __DIR__ . '/../vendor/autoload.php';
    $fpdfLib = __DIR__ . '/../vendor/setasign/fpdf/fpdf.php';
    if (!file_exists($autoload) && !file_exists($fpdfLib)) {
        throw new Exception("Missing vendor libraries. Run composer install or ensure FPDF is present.");
    }
    if (file_exists($autoload)) require_once $autoload;
    if (file_exists($fpdfLib)) require_once $fpdfLib;

    // connect to DB (expects $dsn, $db_user, $db_pass)
    if (!isset($dsn) || !isset($db_user) || !isset($db_pass)) {
        throw new Exception("DB configuration variables missing in config/db.php. Expecting \$dsn, \$db_user, \$db_pass.");
    }
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // helpers for pdf paths
    require_once __DIR__ . '/helpers.php';

    // ---- read / validate inputs ----
    $rechnungsnummer = trim($_POST['rechnungsnummer'] ?? '');
    if ($rechnungsnummer === '') throw new Exception("Rechnungsnummer fehlt (POST rechnungsnummer)");

    $stufe = intval($_POST['stufe'] ?? 0); // 0 = Zahlungserinnerung, 1..3 Mahnungen
    $due_days = intval($_POST['due_days'] ?? 7);
    // Optional overrides / additional fields from form
    $created_by = trim($_POST['created_by'] ?? '');
    $custom_datum = trim($_POST['datum'] ?? '');
    $net_amount_override = isset($_POST['net_amount']) && $_POST['net_amount'] !== '' ? floatval($_POST['net_amount']) : null;
    $ust_percent_input = isset($_POST['ust_percent']) && $_POST['ust_percent'] !== '' ? floatval($_POST['ust_percent']) : null;
    $interest_percent_input = isset($_POST['interest_percent']) && $_POST['interest_percent'] !== '' ? floatval($_POST['interest_percent']) : null;
    $stage_fee_input = isset($_POST['stage_fee']) && $_POST['stage_fee'] !== '' ? floatval($_POST['stage_fee']) : null;
    $days_overdue_input = isset($_POST['days_overdue']) && $_POST['days_overdue'] !== '' ? intval($_POST['days_overdue']) : null;
    $email_result_input = isset($_POST['email_result']) ? trim($_POST['email_result']) : null;
    $db_text = trim($_POST['text'] ?? '');

    // Backward-compat legacy param names
    $interest_input = isset($_POST['interest']) ? trim($_POST['interest']) : '';
    $mahn_input = isset($_POST['mahngebuehr']) ? trim($_POST['mahngebuehr']) : '';

    $send_now = isset($_POST['send_now']) && $_POST['send_now'] === '1';
    $auto_cron = isset($_POST['auto_cron']) && $_POST['auto_cron'] === '1';
    $note = trim($_POST['note'] ?? '');

    // ---- look up invoice + client ----
    $sql = "SELECT r.*, c.name AS client_name, c.email AS client_email, c.firma AS firma
            FROM rechnungen r
            LEFT JOIN clients c ON c.id = r.client_id
            WHERE r.rechnungsnummer = ? LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$rechnungsnummer]);
    $rechnung = $stmt->fetch();
    if (!$rechnung) {
        throw new Exception("Rechnung nicht gefunden: " . $rechnungsnummer);
    }

    $rechnung_id = $rechnung['id'];
    $client_email = $rechnung['client_email'] ?? null;
    $firma = $rechnung['firma'] ?? null;

    // ---- prevent duplicate reminders for the same invoice and stage ----
    try {
        $chk = $pdo->prepare("SELECT id FROM mahnungen WHERE rechnung_id = :rid AND stufe = :stufe LIMIT 1");
        $chk->execute([':rid' => $rechnung_id, ':stufe' => $stufe]);
        $existing = $chk->fetchColumn();
        if ($existing) {
            // Do not create a second Zahlungserinnerung/Mahnung for the same stage
            echo json_encode([
                'success' => false,
                'error' => 'duplicate_stage',
                'message' => 'Für diese Rechnung existiert bereits eine Erinnerung/Mahnung in dieser Stufe.',
                'existing_id' => (int)$existing
            ]);
            // Flush any buffered non‑JSON output
            @ob_end_clean();
            return; // stop further processing
        }
    } catch (Throwable $e) {
        // if check fails, continue but better be safe and block to avoid duplicates due to race conditions
    }

    // ---- compute base amounts ----
    $base_amount = 0.0;
    if (isset($rechnung['gesamt']) && is_numeric($rechnung['gesamt']) && $rechnung['gesamt'] !== '') {
        $base_amount = floatval($rechnung['gesamt']);
    } elseif (isset($rechnung['total']) && is_numeric($rechnung['total']) && $rechnung['total'] !== '') {
        $base_amount = floatval($rechnung['total']);
    } elseif (isset($rechnung['betrag']) && is_numeric($rechnung['betrag']) && $rechnung['betrag'] !== '') {
        $base_amount = floatval($rechnung['betrag']);
    }

    // heuristic: if firma present -> treat as business (B2B / e.U. typical)
    $isB2B = ($firma && trim($firma) !== '');

    // Load app-config for interest/fees behavior
    $cfg = [];
    $configFile = __DIR__ . '/../config/app-config.php';
    if (file_exists($configFile)) {
        require_once $configFile;
        if (class_exists('CRMConfig') && property_exists('CRMConfig','mahnung')) {
            $cfg = (array) CRMConfig::$mahnung;
        }
    }
    $interest_enabled = (bool)($cfg['interest_enabled'] ?? false);

    // defaults (can be overridden by POST)
    $interest_percent = ($interest_input === '') ? ($isB2B ? 10.73 : 4.00) : floatval($interest_input);

    // Stage fees from config (defaults: 0 for Erinnerung, config for others)
    $stage_fee_amount = 0.0;
    if ($stufe === 1) {
        $stage_fee_amount = (float)($cfg['fee_mahnung1'] ?? 0.0);
    } elseif ($stufe >= 2) {
        $stage_fee_amount = (float)($cfg['fee_letzte'] ?? 0.0);
    }

    // Apply explicit overrides from new fields when present
    if ($interest_percent_input !== null) { $interest_percent = $interest_percent_input; }
    if ($stage_fee_input !== null) { $stage_fee_amount = $stage_fee_input; }
    if ($net_amount_override !== null) { $base_amount = $net_amount_override; }

    // Interest is optional and never applied for Zahlungserinnerung (stufe 0)
    $apply_interest = $interest_enabled && ($stufe === 1 || $stufe >= 2);
    if (!$apply_interest) { $interest_percent = 0.0; }

    // Calculate amounts
    $interest_amount = $apply_interest ? round(($interest_percent / 100.0) * $base_amount, 2) : 0.0;
    $stage_fee_amount = round($stage_fee_amount, 2);

    // Optional VAT on net amount
    $ust_percent = ($ust_percent_input !== null) ? $ust_percent_input : null;
    $ust_amount = ($ust_percent !== null) ? round(($ust_percent / 100.0) * $base_amount, 2) : null;

    // Total due calculation per requirement
    $total_due = $base_amount + ($ust_amount ?? 0.0) + ($apply_interest ? $interest_amount : 0.0) + $stage_fee_amount;
    $total_due = round($total_due, 2);

    // days_overdue from faelligkeit if present (can be overridden by POST)
    $days_overdue = null;
    if (!empty($rechnung['faelligkeit'])) {
        $due_date = @date_create($rechnung['faelligkeit']);
        if ($due_date) {
            $now = new DateTime('now');
            if ($now > $due_date) {
                $days_overdue = intval($now->diff($due_date)->format('%a'));
            } else {
                $days_overdue = 0;
            }
        }
    }
    if ($days_overdue_input !== null) { $days_overdue = $days_overdue_input; }

    // Legacy inline PDF generation removed. PDFs are exclusively generated by /pages/mahnung_pdf.php.
    // Continue to persist data and return the generator URL in the response.

    // ---- insert mahnung into DB ----
    // We no longer generate the PDF here; set pdf_path after insert based on standard path
    $pdfPathForDb = '';

    // Prefer custom date from form; otherwise invoice date; fallback to today
    $datumForDb = ($custom_datum !== '') ? $custom_datum : (($rechnung['datum'] ?? null) ?: date('Y-m-d'));

    $insertSql = "INSERT INTO mahnungen
        (rechnung_id, stufe, created_at, created_by, datum, text, sent_email, email_result, note, pdf_path, net_amount, ust_percent, ust_amount, interest_percent, interest_amount, stage_fee, total_due, days_overdue)
        VALUES
        (:rechnung_id, :stufe, NOW(), :created_by, :datum, :text, :sent_email, :email_result, :note, :pdf_path, :net_amount, :ust_percent, :ust_amount, :interest_percent, :interest_amount, :stage_fee, :total_due, :days_overdue)";

    $stmt = $pdo->prepare($insertSql);
    $stmt->execute([
        ':rechnung_id' => $rechnung_id,
        ':stufe' => $stufe,
        ':created_by' => ($created_by !== '' ? $created_by : null),
        ':datum' => $datumForDb,
        ':text' => $db_text,
        ':sent_email' => $send_now ? 1 : 0,
        ':email_result' => ($email_result_input !== null && $email_result_input !== '' ? $email_result_input : null),
        ':note' => $note . ($auto_cron ? ' [auto_cron]' : ''),
        ':pdf_path' => $pdfPathForDb,
        ':net_amount' => $base_amount,
        ':ust_percent' => $ust_percent,
        ':ust_amount' => $ust_amount,
        ':interest_percent' => $interest_percent,
        ':interest_amount' => $interest_amount,
        ':stage_fee' => $stage_fee_amount,
        ':total_due' => $total_due,
        ':days_overdue' => $days_overdue
    ]);

    $mahnung_id = $pdo->lastInsertId();

    // update rechnungen.mahn_stufe
    $update = $pdo->prepare("UPDATE rechnungen SET mahn_stufe = :stufe WHERE id = :id");
    $update->execute([':stufe' => $stufe, ':id' => $rechnung_id]);

    // ---- compute generator URL and set pdf_path metadata ----
    $pdfUrl = '/pages/document_pdf.php?type=mahnung&id=' . $mahnung_id . '&force=1';
    // update pdf_path to the standard location (metadata only; actual file written by generator)
    try {
        $stdWeb = pdf_web_path('mahnung', (int)$mahnung_id, (string)$stufe, (string)$datumForDb);
        $pdo->prepare('UPDATE mahnungen SET pdf_path = ? WHERE id = ?')->execute([$stdWeb, $mahnung_id]);
    } catch (Throwable $e) { /* ignore */ }

    // ---- generate the PDF now using the shared base template and the common body ----
    try {
        if (!defined('FPDF_FONTPATH')) { define('FPDF_FONTPATH', __DIR__ . '/..'); }
        require_once __DIR__ . '/base_template.php';
        $pdf = pdf_base_create($pdo, ['title' => 'Mahnung']);
        // Ensure mahnung_body.php sees the ID variable
        $mahnung_id_local = (int)$mahnung_id;
        $mahnung_id = $mahnung_id_local;
        $__body = __DIR__ . '/mahnung_body.php';
        if (is_file($__body)) { include $__body; }
        // Save to the canonical path that matches mahnung_pdf/document_pdf
        $pdfFile = pdf_file_path('mahnung', $mahnung_id_local, (string)$stufe, (string)$datumForDb);
        $pdf->Output('F', $pdfFile);
    } catch (Throwable $e) {
        // Non-fatal: keep API response success so UI can still open the generator URL
        log_msg('PDF generation failed in mahnung_speichern: ' . $e->getMessage());
    }

    // ---- optionally send email now (do not abort on email errors) ----
    if ($send_now && $client_email) {
        try {
            // config/smtp.php must define getMailer() and use PHPMailer
            $smtpPath = __DIR__ . '/../config/smtp.php';
            if (!file_exists($smtpPath)) {
                log_msg("SMTP config not found: {$smtpPath}. Skipping email send.");
            } else {
                require_once $smtpPath;
                if (!function_exists('getMailer')) {
                    log_msg("getMailer() missing in config/smtp.php - cannot send email.");
                } else {
                    $mail = getMailer();
                    $mail->addAddress($client_email);
                    $mail->Subject = "Mahnung / Zahlungserinnerung: Rechnung " . $rechnungsnummer;
                    $mail->Body = "Sehr geehrte Damen und Herren,\n\nhiermit übermitteln wir Ihnen einen Hinweis zur offenen Rechnung {$rechnungsnummer} (Stufe {$stufe}). Der derzeit fällige Betrag beträgt " . number_format($total_due, 2) . " €.\n\nSie können die Mahnung unter folgendem Link abrufen:\n" . $pdfUrl . "\n\nMit freundlichen Grüßen\n";
                    $mail->AltBody = strip_tags($mail->Body);
                    $mail->send();
                    // mark sent
                    $pdo->prepare("UPDATE mahnungen SET sent_email = 1, email_result = :res WHERE id = :id")
                        ->execute([':res' => 'sent', ':id' => $mahnung_id]);
                }
            }
        } catch (Exception $e) {
            // write error, but continue
            $errtxt = 'email error: ' . $e->getMessage();
            log_msg("Email send failed for mahnung_id {$mahnung_id}: " . $errtxt);
            $pdo->prepare("UPDATE mahnungen SET sent_email = 0, email_result = :res WHERE id = :id")
                ->execute([':res' => $errtxt, ':id' => $mahnung_id]);
        }
    }

    // ---- success response ----
    ob_end_clean();
    echo json_encode([
        'success' => true,
        'mahnung_id' => $mahnung_id,
        'pdf_url' => $pdfUrl,
        'pdf' => $pdfUrl,
        'pdf_path_db' => $pdfPathForDb,
        'total_due' => $total_due
    ], JSON_PRETTY_PRINT);
    exit;

} catch (Exception $e) {
    // log and return error JSON
    ob_end_clean();
    $msg = $e->getMessage();
    log_msg("ERROR: " . $msg . " Trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $msg,
        // remove trace in production; keep for debugging if needed
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
    exit;
}
