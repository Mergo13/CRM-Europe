<?php
// pages/rechnung_pdf.php — Generate or serve Rechnung (invoice) PDF
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$GLOBALS['PDF_UNICODE'] = false;

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../config/db.php';
if (!defined('FPDF_FONTPATH')) { define('FPDF_FONTPATH', __DIR__ . '/../'); }
require_once __DIR__ . '/../vendor/setasign/fpdf/fpdf.php';
require_once __DIR__ . '/../includes/pdf_branding.php';
require_once __DIR__ . '/base_template.php';

require_once __DIR__ . '/../vendor/autoload.php';
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

// Resolve PDO
$pdo = $GLOBALS['pdo'] ?? (isset($pdo) ? $pdo : null);
if (!($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection not available';
    exit;
}

// Validate input
$id = isset($_GET['id']) ? (int)preg_replace('/[^0-9]/', '', (string)$_GET['id']) : 0;
$force = isset($_GET['force']) && (string)$_GET['force'] !== '' ? (int)$_GET['force'] : 0;
// Enable verbose QR diagnostics when requested
$qr_debug = false;
if (isset($_GET['qr_debug'])) {
    $val = strtolower(trim((string)$_GET['qr_debug']));
    $qr_debug = in_array($val, ['1','true','yes','on'], true);
}
if ($id <= 0) {
    http_response_code(400);
    echo 'Bad request: missing or invalid id';
    exit;
}

// Load Rechnung header
try {
    $stmt = $pdo->prepare('SELECT * FROM rechnungen WHERE id = ?');
    $stmt->execute([$id]);
    $rechnung = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$rechnung) {
        http_response_code(404);
        echo 'Rechnung not found for id ' . htmlspecialchars((string)$id, ENT_QUOTES | ENT_HTML5);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Database error';
    exit;
}

$datum = $rechnung['datum'] ?? date('Y-m-d');
// Payment reference (Verwendungszweck) – required for bank transfers & QR
$verwendungszweck = (string)($rechnung['verwendungszweck'] ?? '');

$pdfFile = pdf_file_path('rechnung', $id, '', (string)$datum);
$pdfWebPath = pdf_web_path('rechnung', $id, '', (string)$datum);

// If a PDF already exists and not forced, check freshness against templates and serve if up-to-date
if (is_file($pdfFile) && is_readable($pdfFile) && !$force) {
    $pdfMtime = @filemtime($pdfFile) ?: 0;
    $sources = [
        __FILE__,
        __DIR__ . '/rechnung_body.php',
        __DIR__ . '/../includes/pdf_branding.php',
        __DIR__ . '/../pages/helpers.php',
    ];
    $needsRegen = false;
    foreach ($sources as $src) {
        if (is_file($src) && @filemtime($src) > $pdfMtime) { $needsRegen = true; break; }
    }
    if (!$needsRegen) {
        if ($qr_debug) { header('X-QR-Debug: cached-pdf-served'); }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($pdfFile) . '"');
        header('Content-Length: ' . filesize($pdfFile));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        readfile($pdfFile);
        exit;
    }
}

// Build PDF using the shared base template
$pdf = pdf_base_create($pdo, ['title' => 'Rechnung']);

$rechnung_id = $id;
$__body = __DIR__ . '/rechnung_body.php';
if (is_file($__body)) { include $__body; }

try {
    $pdf->Output('F', $pdfFile);
    // Cleanup temp QR images
    if (!empty($GLOBALS['_qr_cleanup'])) {
        foreach ($GLOBALS['_qr_cleanup'] as $file) {
            @unlink($file);
        }
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo 'Failed to write PDF file';
    exit;
}

// Update pdf_path if column exists
try {
    $pdo->exec("UPDATE rechnungen SET pdf_path = " . $pdo->quote($pdfWebPath) . " WHERE id = " . (int)$id);
} catch (Throwable $e) {}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename($pdfFile) . '"');
header('Content-Length: ' . filesize($pdfFile));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
readfile($pdfFile);
exit;