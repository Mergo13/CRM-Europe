<?php
// pages/angebot_pdf.php — Generate or serve Angebot (quote) PDF
// Usage: /pages/angebot_pdf.php?id=123[&force=1]
// - If a PDF exists for the Angebot and force is not set, serves the existing file inline.
// - Otherwise generates a fresh PDF from DB data, saves it to the standard path, updates pdf_path, and serves it.

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');


require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../config/db.php';
// Ensure FPDF can find DejaVuSans font definition located at project root
if (!defined('FPDF_FONTPATH')) {
    define('FPDF_FONTPATH', __DIR__ . '/../'); // DejaVuSans.php and .z are in project root
}
require_once __DIR__ . '/../vendor/setasign/fpdf/fpdf.php';
require_once __DIR__ . '/../includes/pdf_branding.php';
require_once __DIR__ . '/base_template.php';

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
if ($id <= 0) {
    http_response_code(400);
    echo 'Bad request: missing or invalid id';
    exit;
}

// Load Angebot header (use only crm_app.angebote columns; no joins)
try {
    $stmt = $pdo->prepare('SELECT * FROM angebote WHERE id = ?');
    $stmt->execute([$id]);
    $angebot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$angebot) {
        http_response_code(404);
        echo 'Angebot not found for id ' . htmlspecialchars((string)$id, ENT_QUOTES | ENT_HTML5);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Database error';
    exit;
}

$datum = $angebot['datum'] ?? date('Y-m-d');
$pdfFile = pdf_file_path('angebot', $id, '', (string)$datum);
$pdfWebPath = pdf_web_path('angebot', $id, '', (string)$datum);

// If a PDF already exists and not forced, check freshness against templates and serve if up-to-date
if (is_file($pdfFile) && is_readable($pdfFile) && !$force) {
    $pdfMtime = @filemtime($pdfFile) ?: 0;
    $sources = [
        __FILE__,
        __DIR__ . '/angebot_body.php',
        __DIR__ . '/../includes/pdf_branding.php',
        __DIR__ . '/helpers.php',
    ];
    $needsRegen = false;
    foreach ($sources as $src) {
        if (is_file($src) && @filemtime($src) > $pdfMtime) { $needsRegen = true; break; }
    }
    if (!$needsRegen) {
        // Clear any existing output buffers to avoid corrupting the PDF stream
        try { while (ob_get_level() > 0) { @ob_end_clean(); } } catch (Throwable $e) {}
        if (!is_file($pdfFile) || !is_readable($pdfFile)) {
            http_response_code(500);
            echo 'PDF file not accessible';
            exit;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($pdfFile) . '"');
        header('Content-Transfer-Encoding: binary');
        header('Content-Length: ' . filesize($pdfFile));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        readfile($pdfFile);
        exit;
    }
}

// Build PDF using the shared base template
$pdf = pdf_base_create($pdo, ['title' => 'Angebot']);

// Include body-only renderer (expects $angebot_id, $pdo, $pdf)
$angebot_id = $id;
$__body = __DIR__ . '/angebot_body.php';
if (is_file($__body)) { include $__body; }

// Ensure directory exists and write file
try {
    $pdf->Output('F', $pdfFile);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Failed to write PDF file';
    exit;
}

// Try to update pdf_path on angebot header (non-fatal)
try {
    $stmt = $pdo->prepare('UPDATE angebote SET pdf_path = ? WHERE id = ?');
    $stmt->execute([$pdfWebPath, $id]);
} catch (Throwable $e) {}

// Serve the generated/updated file inline
try { while (ob_get_level() > 0) { @ob_end_clean(); } } catch (Throwable $e) {}
if (!is_file($pdfFile) || !is_readable($pdfFile)) {
    http_response_code(500);
    echo 'PDF file not accessible';
    exit;
}
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename($pdfFile) . '"');
header('Content-Transfer-Encoding: binary');
header('Content-Length: ' . filesize($pdfFile));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
readfile($pdfFile);
exit;
