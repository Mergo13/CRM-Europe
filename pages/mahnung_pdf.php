<?php
// pages/mahnung_pdf.php — Generate or serve Mahnung PDF
// Usage: /pages/mahnung_pdf.php?id=123[&force=1]

declare(strict_types=1);

/* ==========================================================
   🔒 HARD PDF SAFETY — MUST BE FIRST
   ========================================================== */
ini_set('display_errors', '0');
error_reporting(0);

// Kill ALL output buffers (BOM, whitespace, warnings, etc.)
while (ob_get_level()) {
    ob_end_clean();
}

/* ==========================================================
   Dependencies (NO OUTPUT ALLOWED IN THESE FILES)
   ========================================================== */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../config/db.php';

if (!defined('FPDF_FONTPATH')) {
    define('FPDF_FONTPATH', __DIR__ . '/../');
}

require_once __DIR__ . '/../vendor/setasign/fpdf/fpdf.php';
require_once __DIR__ . '/../includes/pdf_branding.php';
require_once __DIR__ . '/base_template.php';

/* ==========================================================
   Database
   ========================================================== */
$pdo = $GLOBALS['pdo'] ?? null;
if (!($pdo instanceof PDO)) {
    http_response_code(500);
    exit;
}

/* ==========================================================
   Input
   ========================================================== */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$force = isset($_GET['force']) ? (int)$_GET['force'] : 0;

if ($id <= 0) {
    http_response_code(400);
    exit;
}

/* ==========================================================
   Load Mahnung
   ========================================================== */
$stmt = $pdo->prepare('SELECT * FROM mahnungen WHERE id = ?');
$stmt->execute([$id]);
$m = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$m) {
    http_response_code(404);
    exit;
}

$datum = $m['datum'] ?? ($m['created_at'] ?? date('Y-m-d'));
$subfolder = (string)($m['stufe'] ?? '');

/* ==========================================================
   Paths
   ========================================================== */
$pdfFile = pdf_file_path('mahnung', $id, $subfolder, (string)$datum);
$pdfWebPath = pdf_web_path('mahnung', $id, $subfolder, (string)$datum);

/* ==========================================================
   Serve existing PDF if valid
   ========================================================== */
if (is_file($pdfFile) && is_readable($pdfFile) && !$force) {

    $pdfMtime = @filemtime($pdfFile) ?: 0;
    $sources = [
        __FILE__,
        __DIR__ . '/mahnung_body.php',
        __DIR__ . '/../includes/pdf_branding.php',
        __DIR__ . '/helpers.php',
    ];

    $needsRegen = false;
    foreach ($sources as $src) {
        if (is_file($src) && @filemtime($src) > $pdfMtime) {
            $needsRegen = true;
            break;
        }
    }

    if (!$needsRegen) {
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($pdfFile) . '"');
        header('Content-Length: ' . filesize($pdfFile));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');

        readfile($pdfFile);
        exit;
    }
}

/* ==========================================================
   Generate PDF
   ========================================================== */
$pdf = pdf_base_create($pdo, ['title' => 'Mahnung']);

$mahnung_id = $id;
$bodyFile = __DIR__ . '/mahnung_body.php';
if (is_file($bodyFile)) {
    include $bodyFile; // MUST contain ONLY FPDF calls
}

/* ==========================================================
   Save PDF to disk
   ========================================================== */
$pdf->Output('F', $pdfFile);

/* ==========================================================
   Persist PDF path (best effort)
   ========================================================== */
try {
    $pdo->exec(
        "UPDATE mahnungen SET pdf_path = " .
        $pdo->quote($pdfWebPath) .
        " WHERE id = " . (int)$id
    );
} catch (Throwable $e) {
    // ignore
}

/* ==========================================================
   Final PDF Output
   ========================================================== */
while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename($pdfFile) . '"');
header('Content-Length: ' . filesize($pdfFile));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

readfile($pdfFile);
exit;
