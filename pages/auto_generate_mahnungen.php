<?php
// file: pages/auto_generate_mahnungen.php
// Run from CLI (cron) or web (but prefer CLI). If run via web, consider protecting with a secret.

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../vendor/autoload.php';
// Ensure FPDF can find DejaVuSans font definition files located at project root
if (!defined('FPDF_FONTPATH')) { define('FPDF_FONTPATH', __DIR__ . '/../'); }
require_once __DIR__ . '/../vendor/setasign/fpdf/fpdf.php';
require_once __DIR__ . '/../includes/pdf_branding.php';
require_once __DIR__ . '/base_template.php';
// Optional: mailer config (if defined)
if (file_exists(__DIR__ . '/../config/smtp.php')) { require_once __DIR__ . '/../config/smtp.php'; }
require_once __DIR__ . '/../config/app-config.php';

// CONFIG: whether the cron should attempt to send emails automatically
if (!defined('AUTO_SEND_EMAIL')) {
    define('AUTO_SEND_EMAIL', true);
}

// Logging helper
function cron_log($msg) {
    $line = '['.date('Y-m-d H:i:s').'] '.$msg."\n";
    $logFile = __DIR__ . '/../logs/mahnung_cron.log';
    @file_put_contents($logFile, $line, FILE_APPEND);
}

// Use PDO from config/db.php
$pdo = $GLOBALS['pdo'] ?? (isset($pdo) ? $pdo : null);
if (!$pdo) {
    throw new RuntimeException('PDO is not initialized. Ensure config/db.php is included correctly.');
}

$now = new DateTime('now');

// fetch invoices not paid
$sql = "SELECT r.*, c.name AS client_name, c.email AS client_email, c.firma
        FROM rechnungen r
        LEFT JOIN clients c ON c.id = r.client_id
        WHERE (r.status IS NULL OR LOWER(r.status) NOT IN ('bezahlt','paid'))";

$stmt = $pdo->query($sql);
$invoices = $stmt->fetchAll();

function getLatestStage(PDO $pdo, $rechnung_id) {
    $s = $pdo->prepare("SELECT stufe, created_at FROM mahnungen WHERE rechnung_id = ? ORDER BY created_at DESC LIMIT 1");
    $s->execute([$rechnung_id]);
    return $s->fetch();
}

function getDueDate(array $inv): ?DateTime {
    $cands = ['faelligkeit','fälligkeit','due_date','due','faellig_am'];
    foreach ($cands as $key) {
        if (!empty($inv[$key])) {
            try { return new DateTime($inv[$key]); } catch (Exception $e) { /* ignore */ }
        }
    }
    // fallback to invoice date
    $dcands = ['datum','date','created_at'];
    foreach ($dcands as $key) {
        if (!empty($inv[$key])) {
            try { return new DateTime($inv[$key]); } catch (Exception $e) { /* ignore */ }
        }
    }
    return null;
}

function threshold_for_stage(int $stage): int {
    // Map config thresholds (days after due date) using new canonical keys
    $cfg = CRMConfig::$mahnung ?? [];
    switch ($stage) {
        case 0: return (int)($cfg['zahlungserinnerung_days'] ?? ($cfg['erinnerung_days'] ?? 5));
        case 1: return (int)($cfg['mahnung1_days'] ?? 14);
        case 2: return (int)($cfg['letzte_mahnung_days'] ?? ($cfg['letzte_days'] ?? 30));
        default: return 99999;
    }
}

function createMahnungRecord(PDO $pdo, array $rechnung, int $stufe, bool $sendEmail=false) {
    // Guard: prevent duplicate reminders for the same invoice and stage
    try {
        $chk = $pdo->prepare("SELECT id FROM mahnungen WHERE rechnung_id = :rid AND stufe = :stufe LIMIT 1");
        $chk->execute([':rid' => $rechnung['id'], ':stufe' => $stufe]);
        $existingId = $chk->fetchColumn();
        if ($existingId) {
            return (int)$existingId; // do not create a duplicate
        }
    } catch (Throwable $e) { /* ignore and continue; insert below will still fail if unique constraint exists */ }
    // Calculate base amount strictly from invoice — no fees, no interest
    $base_amount = 0.0;
    if (isset($rechnung['gesamt']) && is_numeric($rechnung['gesamt']) && $rechnung['gesamt'] !== '') $base_amount = (float)$rechnung['gesamt'];
    elseif (isset($rechnung['total']) && is_numeric($rechnung['total']) && $rechnung['total'] !== '') $base_amount = (float)$rechnung['total'];
    elseif (isset($rechnung['betrag']) && is_numeric($rechnung['betrag']) && $rechnung['betrag'] !== '') $base_amount = (float)$rechnung['betrag'];

    // Monetary additions disabled by requirement
    $interest_percent = 0.0;
    $stage_fee_amount = 0.0;
    $interest_amount = 0.0;
    $total_due = round($base_amount, 2);

    // Prepare legacy PDF path (kept for compatibility with existing storage paths)
    $pdfDir = realpath(__DIR__ . '/../pdf/Mahnungen') ?: (__DIR__ . '/../pdf/Mahnungen');
    if (!is_dir($pdfDir)) @mkdir($pdfDir,0755,true);
    $safeInvoice = preg_replace('/[^A-Za-z0-9_-]/','_', (string)($rechnung['rechnungsnummer'] ?? $rechnung['nummer'] ?? $rechnung['id']));
    $pdfFilename = "mahnung_{$safeInvoice}_stufe{$stufe}_".time().".pdf";
    $pdfFullPath = $pdfDir . DIRECTORY_SEPARATOR . $pdfFilename;
    $pdfPathForDb = 'pdf/Mahnungen/' . $pdfFilename;

    // insert into table (keep amount columns for backward compat but fill with invoice amount and zeros)
    $insert = $pdo->prepare("INSERT INTO mahnungen (rechnung_id, stufe, created_at, datum, text, sent_email, email_result, note, pdf_path, net_amount, interest_percent, interest_amount, stage_fee, total_due, days_overdue)
                             VALUES (:rechnung_id, :stufe, NOW(), :datum, :text, :sent_email, :email_result, :note, :pdf_path, :net_amount, :interest_percent, :interest_amount, :stage_fee, :total_due, :days_overdue)");

    $sent_email_flag = 0;
    $email_result = null;

    $insert->execute([
        ':rechnung_id' => $rechnung['id'],
        ':stufe' => $stufe,
        ':datum' => $rechnung['datum'] ?? null,
        ':text' => '',
        ':sent_email' => $sent_email_flag,
        ':email_result' => $email_result,
        ':note' => 'created by cron',
        ':pdf_path' => $pdfPathForDb,
        ':net_amount' => $base_amount,
        ':interest_percent' => $interest_percent,
        ':interest_amount' => $interest_amount,
        ':stage_fee' => $stage_fee_amount,
        ':total_due' => $total_due,
        ':days_overdue' => null
    ]);

    $mahnung_id = $pdo->lastInsertId();

    // Generate the PDF using the shared base template and Mahnung body for consistent layout/encoding
    try {
        $pdf = pdf_base_create($pdo, ['title' => 'Mahnung']);
        $mahnung_id_local = (int)$mahnung_id;
        // mahnung_body.php expects $mahnung_id, $pdo, and $pdf in scope
        $mahnung_id = $mahnung_id_local;
        $__body = __DIR__ . '/mahnung_body.php';
        if (is_file($__body)) { include $__body; }
        $pdf->Output('F', $pdfFullPath);
    } catch (Throwable $e) {
        // If PDF generation fails, log error but continue to keep cron idempotent
        @file_put_contents(__DIR__ . '/../logs/mahnung_cron.log', '['.date('Y-m-d H:i:s').'] PDF gen failed: '.$e->getMessage()."\n", FILE_APPEND);
    }

    // attempt to send email if requested and email provided
    if ($sendEmail && !empty($rechnung['client_email']) && function_exists('getMailer')) {
        try {
            $mail = getMailer();
            $mail->addAddress($rechnung['client_email']);
            // Subject per stage
            $invNr = ($rechnung['rechnungsnummer'] ?? $rechnung['nummer'] ?? $rechnung['id']);
            if ($stufe === 0) $mail->Subject = 'Zahlungserinnerung zu Ihrer Rechnung ' . $invNr;
            elseif ($stufe === 1) $mail->Subject = '1. Mahnung zu Ihrer Rechnung ' . $invNr;
            elseif ($stufe === 2) $mail->Subject = 'Letzte Mahnung zu Ihrer Rechnung ' . $invNr;
            else $mail->Subject = 'Hinweis zu Ihrer Rechnung ' . $invNr;

            // Body texts (Austrian, as specified)
            if ($stufe === 0) {
                $mail->Body = "Guten Tag, leider konnten wir noch keinen Zahlungseingang zu unserer Rechnung feststellen. Der offene Rechnungsbetrag ist weiterhin ausständig. Wir bitten Sie, den Betrag innerhalb der nächsten 7 Tage zu überweisen. Vielen Dank. Freundliche Grüße";
            } elseif ($stufe === 1) {
                $mail->Body = "Guten Tag, unsere Rechnung ist weiterhin offen. Der offene Rechnungsbetrag entspricht dem ursprünglichen Rechnungsbetrag. Bitte begleichen Sie diesen innerhalb der nächsten 7 Tage, um weitere Schritte zu vermeiden. Freundliche Grüße";
            } elseif ($stufe === 2) {
                $mail->Body = "Guten Tag, trotz unserer bisherigen Zahlungserinnerungen ist die Rechnung noch nicht beglichen. Der offene Betrag entspricht weiterhin dem ursprünglichen Rechnungsbetrag. Wir ersuchen Sie, den Betrag umgehend zu überweisen. Sollte kein Zahlungseingang erfolgen, behalten wir uns weitere Schritte vor. Freundliche Grüße";
            } else {
                $mail->Body = "Hinweis zu Ihrer Rechnung. Bitte begleichen Sie den offenen Betrag.";
            }
            $mail->AltBody = $mail->Body;

            $mail->addAttachment($pdfFullPath, $pdfFilename);
            $mail->send();
            $stmt = $pdo->prepare('UPDATE mahnungen SET sent_email = 1, email_result = :res WHERE id = :id');
            $stmt->execute([':res' => 'sent', ':id' => $mahnung_id]);
        } catch (Exception $e) {
            $stmt = $pdo->prepare('UPDATE mahnungen SET sent_email = 0, email_result = :res WHERE id = :id');
            $stmt->execute([':res' => 'error: '.$e->getMessage(), ':id' => $mahnung_id]);
        }
    }

    // update rechnungen: set status = 'mahnung' and current stage (only if not paid)
    $u = $pdo->prepare("UPDATE rechnungen SET status = COALESCE(NULLIF(status,''),'mahnung'), mahn_stufe = :stufe WHERE id = :id AND (status IS NULL OR LOWER(status) NOT IN ('bezahlt','paid'))");
    $u->execute([':stufe' => $stufe, ':id' => $rechnung['id']]);

    return $mahnung_id;
}

// main loop
foreach ($invoices as $inv) {
    try {
        $due = getDueDate($inv);
        if (!$due) { cron_log('Skip (no due date) invoice ID='.$inv['id']); continue; }
        $daysOverdue = (int)$due->diff($now)->format('%r%a');
        if ($daysOverdue < 0) { // not yet due
            continue;
        }

        // Determine next stage based on thresholds
        $latest = getLatestStage($pdo, $inv['id']);
        $currentStage = $latest ? (int)$latest['stufe'] : -1; // -1 means none

        // Find the highest stage whose threshold is met
        $requiredStage = -1;
        for ($s=0; $s<=3; $s++) {
            if ($daysOverdue >= threshold_for_stage($s)) {
                $requiredStage = $s;
            }
        }
        if ($requiredStage <= $currentStage) {
            continue; // nothing to do
        }

        // Only create the immediate next stage to avoid skipping steps and mass emails
        $nextStage = $currentStage + 1;
        // Ensure its threshold is satisfied
        if ($daysOverdue >= threshold_for_stage($nextStage)) {
            createMahnungRecord($pdo, $inv, $nextStage, AUTO_SEND_EMAIL);
            cron_log("Created stage {$nextStage} for invoice ".($inv['rechnungsnummer'] ?? $inv['id'])." (daysOverdue={$daysOverdue})");
        }
    } catch (Exception $e) {
        cron_log('Error on invoice '.($inv['rechnungsnummer'] ?? $inv['id']).': '.$e->getMessage());
    }
}

cron_log('Run complete.');

echo "Done.\n";
