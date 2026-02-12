<?php
// pages/document_pdf.php — Unified PDF generator for angebot|rechnung|mahnung
// Usage: /pages/document_pdf.php?type=rechnung|angebot|mahnung&id=123[&force=1]

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../config/db.php';
if (!defined('FPDF_FONTPATH')) { define('FPDF_FONTPATH', __DIR__ . '/../'); }
require_once __DIR__ . '/base_template.php';

$pdo = $GLOBALS['pdo'] ?? (isset($pdo) ? $pdo : null);
if (!($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection not available';
    exit;
}

$type = strtolower(trim((string)($_GET['type'] ?? '')));
$id = isset($_GET['id']) ? (int)preg_replace('/[^0-9]/', '', (string)$_GET['id']) : 0;
$force = isset($_GET['force']) && (string)$_GET['force'] !== '' ? (int)$_GET['force'] : 0;
if ($id <= 0 || !in_array($type, ['rechnung','angebot','mahnung'], true)) {
    http_response_code(400);
    echo 'Bad request: missing or invalid parameters';
    exit;
}

try {
    if ($type === 'rechnung') {
        $stmt = $pdo->prepare('SELECT * FROM rechnungen WHERE id = ?');
    } elseif ($type === 'angebot') {
        $stmt = $pdo->prepare('SELECT * FROM angebote WHERE id = ?');
    } else { // mahnung
        $stmt = $pdo->prepare('SELECT * FROM mahnungen WHERE id = ?');
    }
    $stmt->execute([$id]);
    $header = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$header) {
        http_response_code(404);
        echo ucfirst($type) . ' not found for id ' . htmlspecialchars((string)$id, ENT_QUOTES | ENT_HTML5);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Database error';
    exit;
}

$datum = $header['datum'] ?? ($header['created_at'] ?? date('Y-m-d'));
$subfolder = ($type === 'mahnung') ? (string)($header['stufe'] ?? '') : '';
$pdfFile = pdf_file_path($type, $id, $subfolder, (string)$datum);
$pdfWebPath = pdf_web_path($type, $id, $subfolder, (string)$datum);

// Serve existing if fresh
if (is_file($pdfFile) && is_readable($pdfFile) && !$force) {
    $pdfMtime = @filemtime($pdfFile) ?: 0;
    $sources = [ __FILE__, __DIR__ . '/base_template.php', __DIR__ . '/../includes/pdf_branding.php', __DIR__ . '/helpers.php' ];
    // Add body templates per type
    $sources[] = __DIR__ . '/' . $type . '_body.php';
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

// Build using base template
$docTitleMap = [ 'rechnung' => 'Rechnung', 'angebot' => 'Angebot', 'mahnung' => 'Mahnung' ];
$pdf = pdf_base_create($pdo, ['title' => $docTitleMap[$type] ?? ucfirst($type)]);

// Normalize body start position and default typography to avoid deformed look
// Ensure the body starts below the branding header and uses DejaVu font like others
$minStartY = 52; // aligns with Angebot/Rechnung baseline under header
if ($pdf->GetY() < $minStartY) { $pdf->SetY($minStartY); }
$pdf->SetFont('DejaVu','',10);
$pdf->SetTextColor(0,0,0);
$pdf->SetDrawColor(0,0,0);
$pdf->SetFillColor(255,255,255);

// Include body template (expects $<type>_id, $pdo, $pdf in scope)
if ($type === 'rechnung') { $rechnung_id = $id; }
if ($type === 'angebot') { $angebot_id = $id; }
if ($type === 'mahnung') { $mahnung_id = $id; }
$body = __DIR__ . '/' . $type . '_body.php';
if (is_file($body)) { include $body; }

// Write file
try {
    $pdf->Output('F', $pdfFile);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Failed to write PDF file';
    exit;
}

// Try update header table's pdf_path for convenience
try {
    if ($type === 'rechnung') {
        $pdo->exec("UPDATE rechnungen SET pdf_path = " . $pdo->quote($pdfWebPath) . " WHERE id = " . (int)$id);
    } elseif ($type === 'angebot') {
        $stmt = $pdo->prepare('UPDATE angebote SET pdf_path = ? WHERE id = ?');
        $stmt->execute([$pdfWebPath, $id]);
    } else { // mahnung
        $pdo->exec("UPDATE mahnungen SET pdf_path = " . $pdo->quote($pdfWebPath) . " WHERE id = " . (int)$id);
    }
} catch (Throwable $e) {}

// Serve
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename($pdfFile) . '"');
header('Content-Length: ' . filesize($pdfFile));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
readfile($pdfFile);
exit;
