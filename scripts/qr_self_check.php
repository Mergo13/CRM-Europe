<?php
// scripts/qr_self_check.php
// Standalone diagnostics for Composer autoload and QR generation/embedding
// Run via CLI: php scripts/qr_self_check.php
// Or via browser: /scripts/qr_self_check.php

declare(strict_types=1);

@error_reporting(E_ALL);
@ini_set('display_errors', '1');

$IS_CLI = (PHP_SAPI === 'cli');

function out($msg) {
    global $IS_CLI;
    if ($IS_CLI) {
        fwrite(STDOUT, $msg . "\n");
    } else {
        echo htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "<br>\n";
    }
}

function ok($msg){ out('[OK]  ' . $msg); }
function warn($msg){ out('[WARN] ' . $msg); }
function err($msg){ out('[ERR] ' . $msg); }

if (!$IS_CLI) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>QR Self Check</title><pre style="font: 14px/1.4 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace">';
}

out('== QR / Composer Self-Check ==');
out('PHP ' . PHP_VERSION);

// 1) Check required extensions
$exts = ['pdo', 'mbstring', 'iconv', 'json'];
$missing = [];
foreach ($exts as $e) {
    if (!extension_loaded($e)) { $missing[] = $e; }
}
if ($missing) { warn('Missing extensions: ' . implode(', ', $missing)); } else { ok('Extensions present: ' . implode(', ', $exts)); }

// 2) Autoload
$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    err('vendor/autoload.php not found. Run composer install.');
    exit(1);
}
require_once $autoload;
ok('Composer autoload loaded.');

// 3) Endroid classes
$hasBuilder = class_exists(Endroid\QrCode\Builder\Builder::class);
$hasWriter  = class_exists(Endroid\QrCode\Writer\PngWriter::class);
if ($hasBuilder && $hasWriter) { ok('Endroid QR classes available (v6).'); }
else { err('Endroid QR classes missing. Check composer.json and vendor/endroid/qr-code.'); }

// 4) Try to build a QR PNG into temp
try {
    $data = "BCD\n001\n1\nSCT\nBKAUATWW\nDemo Company GmbH\nAT611904300234573201\nEUR1.00\n\nTESTREF";
    if (!$hasBuilder || !$hasWriter) { throw new RuntimeException('QR classes unavailable'); }
    $result = Endroid\QrCode\Builder\Builder::create()
        ->writer(new Endroid\QrCode\Writer\PngWriter())
        ->data($data)
        ->size(200)
        ->margin(10)
        ->build();

    $tmpDir = sys_get_temp_dir();
    if (!@is_writable($tmpDir)) {
        $tmpDir = $root . '/logs/qr_tmp';
        if (!is_dir($tmpDir)) { @mkdir($tmpDir, 0775, true); }
    }
    $path = rtrim($tmpDir, '/\\') . '/qr_self_check.png';
    $result->saveToFile($path);

    if (is_file($path) && filesize($path) > 0) {
        ok('QR PNG generated at: ' . $path . ' (' . filesize($path) . ' bytes)');
    } else {
        err('QR PNG not created at: ' . $path);
    }
} catch (Throwable $e) {
    err('QR build failed: ' . $e->getMessage());
}

// 5) Try FPDF embed
try {
    if (!defined('FPDF_FONTPATH')) define('FPDF_FONTPATH', $root . '/');
    require_once $root . '/vendor/setasign/fpdf/fpdf.php';
    $pdf = new FPDF('P','mm','A4');
    $pdf->AddPage();
    $pdf->SetFont('Arial','B',14);
    $pdf->Cell(0,10,'QR Embed Test',0,1,'C');
    if (isset($path) && is_file($path)) {
        $pdf->Image($path, 150, 40, 40, 40);
        $pdf->SetFont('Arial','',10);
        $pdf->SetXY(150, 82);
        $pdf->Cell(40,6,'SEPA QR-Code',0,0,'C');
    } else {
        $pdf->SetTextColor(200,0,0);
        $pdf->Cell(0,10,'PNG missing, cannot embed.',0,1,'C');
    }
    $out = $root . '/logs/qr_test.pdf';
    if (!is_dir($root . '/logs')) { @mkdir($root . '/logs', 0775, true); }
    $pdf->Output('F', $out);
    if (is_file($out) && filesize($out) > 0) {
        ok('PDF written: ' . $out . ' (' . filesize($out) . ' bytes)');
    } else {
        err('PDF not written: ' . $out);
    }
} catch (Throwable $e) {
    err('FPDF embed failed: ' . $e->getMessage());
}

// 6) Cleanup (optional)
try { if (isset($path) && is_file($path)) @unlink($path); } catch (Throwable $e) {}

out('Self-check completed.');

if (!$IS_CLI) { echo '</pre>'; }
