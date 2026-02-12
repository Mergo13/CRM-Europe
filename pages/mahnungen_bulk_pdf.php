<?php
declare(strict_types=1);
ini_set('display_errors', '0'); // critical: never print warnings into a PDF response
error_reporting(E_ALL);

if (!ob_get_level()) {
    ob_start();
}


require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../config/db.php';
if (!defined('FPDF_FONTPATH')) { define('FPDF_FONTPATH', __DIR__ . '/../'); }
require_once __DIR__ . '/../vendor/setasign/fpdf/fpdf.php';
require_once __DIR__ . '/../includes/pdf_branding.php';
require_once __DIR__ . '/base_template.php';

$pdo = $GLOBALS['pdo'] ?? (isset($pdo) ? $pdo : null);
if (!($pdo instanceof PDO)) {

    if (ob_get_length()) { @ob_end_clean(); }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Database connection not available';
    exit;
}

$ids = [];
if (isset($_GET['ids'])) {
    $ids = is_array($_GET['ids']) ? array_map('strval', $_GET['ids']) : explode(',', (string)$_GET['ids']);
}
$ids = array_values(array_unique(array_filter(array_map(
    static fn($v) => (int)preg_replace('/[^0-9]/', '', (string)$v),
    $ids
), static fn($n) => $n > 0)));

if (!$ids) {
    if (ob_get_length()) { @ob_end_clean(); }
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Bad request: missing ids';
    exit;
}

$pdf = pdf_base_create($pdo, ['title' => 'Sammeldruck Mahnungen']);

$first = true;
foreach ($ids as $mid) {
    if ($first) {
        $first = false;
    } else {
        $pdf->AddPage();
    }

    $mahnung_id = (int)$mid;
    $__body = __DIR__ . '/mahnung_body.php';
    if (is_file($__body)) {
        include $__body;
    } else {
        $pdf->SetFont('DejaVu','',10);
        $pdf->MultiCell(0, 5, enc('Vorlagen-Datei mahnung_body.php nicht gefunden.'));
    }
}

// ensure absolutely nothing was output before PDF headers/body
if (ob_get_length()) { @ob_end_clean(); }

$filename = 'mahnungen-sammeldruck.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');


// Ensure nothing has been output before streaming the PDF
while (ob_get_level() > 0) {
    ob_end_clean();
}

$pdf->Output('I', $filename);
exit;