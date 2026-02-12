<?php
// pages/lieferschein_pdf.php — Generate or serve Lieferschein PDF
// Usage: /pages/lieferschein_pdf.php?id=123[&force=1]

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../config/db.php';
if (!defined('FPDF_FONTPATH')) { define('FPDF_FONTPATH', __DIR__ . '/../'); }
require_once __DIR__ . '/../vendor/setasign/fpdf/fpdf.php';
require_once __DIR__ . '/../includes/pdf_branding.php';

$pdo = $GLOBALS['pdo'] ?? (isset($pdo) ? $pdo : null);
if (!($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection not available';
    exit;
}

$id = isset($_GET['id']) ? (int)preg_replace('/[^0-9]/', '', (string)$_GET['id']) : 0;
$force = isset($_GET['force']) && (string)$_GET['force'] !== '' ? (int)$_GET['force'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo 'Bad request: missing or invalid id';
    exit;
}

// Load header
try {
    $stmt = $pdo->prepare('SELECT * FROM lieferscheine WHERE id = ?');
    $stmt->execute([$id]);
    $ls = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ls) {
        http_response_code(404);
        echo 'Lieferschein not found for id ' . htmlspecialchars((string)$id, ENT_QUOTES | ENT_HTML5);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Database error';
    exit;
}

$datum = $ls['datum'] ?? date('Y-m-d');
$pdfFile = pdf_file_path('lieferschein', $id, '', (string)$datum);
$pdfWebPath = pdf_web_path('lieferschein', $id, '', (string)$datum);

if (is_file($pdfFile) && is_readable($pdfFile) && !$force) {
    $pdfMtime = @filemtime($pdfFile) ?: 0;
    $sources = [
        __FILE__,
        __DIR__ . '/lieferschein_body.php',
        __DIR__ . '/../includes/pdf_branding.php',
        __DIR__ . '/../pages/helpers.php',
    ];
    $needsRegen = false;
    foreach ($sources as $src) {
        if (is_file($src) && @filemtime($src) > $pdfMtime) { $needsRegen = true; break; }
    }
    if (!$needsRegen) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($pdfFile) . '"');
        header('Content-Length: ' . filesize($pdfFile));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        readfile($pdfFile);
        exit;
    }
}

// Build PDF using the same pattern as Rechnung/Angebot
class LieferscheinPdf extends FPDF
{
    public PDO $pdo;
    public function Footer(): void
    {
        pdf_branding_footer($this, $this->pdo, [
            'using_dejavu' => true,
            'font_regular' => 'DejaVu',
        ]);
    }
}

$pdf = new LieferscheinPdf('P', 'mm', 'A4');
$pdf->pdo = $pdo;
$pdf->AddFont('DejaVu', '', 'DejaVuSans.php');
$pdf->AddFont('DejaVu', 'B', 'DejaVuSans-Bold.php');
$pdf->SetAutoPageBreak(true, 30);
$pdf->AddPage();

pdf_branding_header($pdf, $pdo, ['using_dejavu' => true, 'font_regular' => 'DejaVu']);

$lieferschein_id = $id;
$__body = __DIR__ . '/lieferschein_body.php';
if (is_file($__body)) { include $__body; }

try {
    $pdf->Output('F', $pdfFile);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Failed to write PDF file';
    exit;
}

// No pdf_path column in schema for lieferscheine by default; skip safe update

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename($pdfFile) . '"');
header('Content-Length: ' . filesize($pdfFile));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
readfile($pdfFile);
exit;
