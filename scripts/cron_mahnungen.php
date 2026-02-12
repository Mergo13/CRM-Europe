<?php
// scripts/cron_mahnungen.php
// Cron-ready script to generate Mahnungen in stages based on days overdue.
// Run via CLI or web cron: php scripts/cron_mahnungen.php [--dry-run]
// Uses defaults if not configured: Erinnerung 7d, Mahnung 1 at 14d, Mahnung 2 at 21d, Letzte at 30d.

error_reporting(E_ALL);
ini_set('display_errors', 1);

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../config/db.php';
if (isset($GLOBALS['pdo'])) { $pdo = $GLOBALS['pdo']; }

// Load optional app-config thresholds
$defaults = [
    // canonical keys per requirements
    'zahlungserinnerung_days' => 5,
    'mahnung1_days' => 14,
    'letzte_mahnung_days' => 30,
    // legacy fallbacks for compatibility
    'erinnerung_days' => 7,
    'mahnung2_days' => 21,
    'letzte_days' => 30,
];

$cfg = $defaults;
$configFile = __DIR__ . '/../config/app-config.php';
if (file_exists($configFile)) {
    require_once $configFile;
    if (class_exists('CRMConfig') && property_exists('CRMConfig', 'mahnung')) {
        $userCfg = (array) CRMConfig::$mahnung;
        $cfg = array_merge($cfg, $userCfg);
    }
}

// Resolve effective thresholds without hardcoding
$th_erinnerung = (int)($cfg['zahlungserinnerung_days'] ?? $cfg['erinnerung_days']);
$th_m1 = (int)($cfg['mahnung1_days'] ?? 14);
$th_last = (int)($cfg['letzte_mahnung_days'] ?? ($cfg['letzte_days'] ?? 30));

function out($msg){ echo '['.date('Y-m-d H:i:s')."] $msg\n"; }

try {
    // Find invoices that are open/overdue (status not 'paid'), with a due date (faelligkeit) in the past
    $sql = "SELECT r.id, r.rechnungsnummer, r.faelligkeit, r.status, r.mahn_stufe, r.client_id
            FROM rechnungen r
            WHERE (r.status IS NULL OR r.status NOT IN ('paid','bezahlt'))
              AND r.faelligkeit IS NOT NULL AND r.faelligkeit <> ''
              AND r.faelligkeit <= CURDATE()";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $counts = ['erinnerung'=>0,'m1'=>0,'last'=>0,'skipped'=>0];

    foreach ($rows as $row) {
        $stufe = (int)($row['mahn_stufe'] ?? 0);
        $due = new DateTime($row['faelligkeit']);
        $now = new DateTime('today');
        $days = (int)$due->diff($now)->format('%r%a');
        if ($days < 0) { $counts['skipped']++; continue; }

        $targetStage = null;
        if ($days >= $th_last) {
            $targetStage = 2; // Letzte Mahnung
        } elseif ($days >= $th_m1) {
            $targetStage = 1; // Mahnung 1
        } elseif ($days >= $th_erinnerung) {
            $targetStage = 0; // Zahlungserinnerung
        }

        if ($targetStage === null || $targetStage <= $stufe) { $counts['skipped']++; continue; }

        // Prepare POST payload to mahnung_speichern.php
        $post = http_build_query([
            'rechnungsnummer' => $row['rechnungsnummer'],
            'stufe' => $targetStage,
            'due_days' => max(7, $days),
            'send_now' => '0',
            'auto_cron' => '1'
        ]);

        if ($dryRun) {
            out("DRY-RUN would create Mahnung stufe=$targetStage for Rechnung {$row['rechnungsnummer']} (days overdue=$days)");
            continue;
        }

        // Attempt to call via local HTTP if host is known; else fallback to include in a separate process
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = $host ? ($scheme . '://' . $host) : '';
        $endpoint = $baseUrl ? ($baseUrl . '/pages/mahnung_speichern.php') : null;

        $ok = false; $resp = null;
        if ($endpoint) {
            $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => $post, 'timeout' => 30]]);
            $resp = @file_get_contents($endpoint, false, $ctx);
            if ($resp !== false) { $ok = true; }
        }

        if (!$ok) {
            // Fallback: call locally via CLI PHP to avoid headers/output issues
            $cmd = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../scripts/run_mahnung_save.php') . ' ' . escapeshellarg($row['rechnungsnummer']) . ' ' . (int)$targetStage . ' ' . (int)max(7, $days);
            @exec($cmd, $outLines, $code);
            $ok = ($code === 0);
            $resp = implode("\n", (array)$outLines);
        }

        if ($ok) {
            if ($targetStage === 0) $counts['erinnerung']++; elseif ($targetStage === 1) $counts['m1']++; elseif ($targetStage === 2) $counts['m2']++; else $counts['m3']++;
            out("OK Mahnung stufe=$targetStage for {$row['rechnungsnummer']} response: " . substr((string)$resp,0,120));
        } else {
            out("FAIL Mahnung stufe=$targetStage for {$row['rechnungsnummer']}");
        }
    }

    out('Summary: ' . json_encode($counts));
    exit(0);
} catch (Throwable $e) {
    out('ERROR: ' . $e->getMessage());
    exit(1);
}
