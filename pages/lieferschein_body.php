<?php
// pages/lieferschein_body.php — PDF BODY ONLY for a delivery note (Lieferschein)
// Expects: $pdf (FPDF-like), $pdo (PDO), and $lieferschein_id in scope.

declare(strict_types=1);

if (!isset($pdf) || !is_object($pdf) || !method_exists($pdf, 'GetY')) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'This endpoint is a PDF body template and must be included by a PDF generator with $pdf and $pdo context.'
    ]);
    exit;
}

require_once __DIR__ . '/helpers.php';

$pdo = $pdo ?? ($GLOBALS['pdo'] ?? null);
if (!($pdo instanceof PDO)) {
    $pdf->SetFont('DejaVu','',10);
    $pdf->SetTextColor(180,0,0);
    $pdf->MultiCell(0,5, enc('Datenbankverbindung fehlt.'));
    $pdf->SetTextColor(0,0,0);
    return;
}

$lieferschein_id = $lieferschein_id ?? null;
if (!$lieferschein_id && isset($_GET['id'])) {
    $lieferschein_id = (int)preg_replace('/[^0-9]/','', (string)$_GET['id']);
}
if (!$lieferschein_id) {
    $pdf->SetFont('DejaVu','',10);
    $pdf->SetTextColor(180,0,0);
    $pdf->MultiCell(0,5, enc('Lieferschein-ID fehlt.'));
    $pdf->SetTextColor(0,0,0);
    return;
}

// Load lieferschein
try {
    $stmt = $pdo->prepare('SELECT * FROM lieferscheine WHERE id = ? LIMIT 1');
    $stmt->execute([$lieferschein_id]);
    $ls = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ls) {
        $pdf->SetFont('DejaVu','',10);
        $pdf->SetTextColor(180,0,0);
        $pdf->MultiCell(0,5, enc('Lieferschein nicht gefunden.'));
        $pdf->SetTextColor(0,0,0);
        return;
    }
} catch (Throwable $e) {
    $pdf->SetFont('DejaVu','',10);
    $pdf->SetTextColor(180,0,0);
    $pdf->MultiCell(0,5, enc('Fehler beim Laden des Lieferscheins.'));
    $pdf->SetTextColor(0,0,0);
    return;
}

// Client
$client = null;
if (!empty($ls['client_id'])) {
    try {
        $cs = $pdo->prepare('SELECT id, firma, name, adresse, plz, ort FROM clients WHERE id = ?');
        $cs->execute([(int)$ls['client_id']]);
        $client = $cs->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { $client = null; }
}

$nummer = $ls['nummer'] ?? $ls['id'];
$lieferDatumRaw = !empty($ls['lieferdatum']) ? (string)$ls['lieferdatum'] : ((string)($ls['datum'] ?? ''));
$dateStr = $lieferDatumRaw !== '' ? date('d.m.Y', strtotime($lieferDatumRaw)) : date('d.m.Y');

// =================================================
// TITLE (CENTERED, MODERN) — align with Rechnung/Angebot
// =================================================
$pdf->Ln(8);
$pdf->SetFont('DejaVu','B',18);
$pdf->Cell(0,10, enc('LIEFERSCHEIN'), 0, 1, 'C');
$pdf->Ln(6);

// =================================================
// META (2-COLUMN, CLEAN) — same style
// =================================================
$pdf->SetFont('DejaVu','',10);
$y = $pdf->GetY();

$pdf->SetXY(20, $y);
$pdf->Cell(40,6, enc('Lieferscheinnummer:'), 0, 0);
$pdf->Cell(60,6, enc($nummer), 0, 1);

$pdf->SetX(20);
$pdf->Cell(40,6, enc('Lieferdatum:'), 0, 0);
$pdf->Cell(60,6, enc($dateStr), 0, 1);

// Right side: Kundennummer when available
$pdf->SetXY(120, $y);
$pdf->Cell(30,6, enc('Kundennummer:'), 0, 0);
$pdf->Cell(40,6, enc((string)($ls['kundennummer'] ?? '')), 0, 1);

$pdf->SetX(120);
$pdf->Cell(30,6, enc('Bestellnummer:'), 0, 0);
$pdf->Cell(40,6, enc((string)($ls['bestellnummer'] ?? '')), 0, 1);

$pdf->Ln(10);

// =================================================
// CLIENT BLOCK (NO BOX, MODERN) — same style as Rechnung/Angebot
// =================================================
if ($client) {
    $pdf->SetFont('DejaVu','',11);
    $pdf->Cell(0,6, enc('Lieferung an:'), 0, 1);

    $firma = trim((string)($client['firma'] ?? ''));
    $name  = trim((string)($client['name'] ?? ''));

    if ($firma !== '') {
        $pdf->SetFont('DejaVu','B',11);
        $pdf->Cell(0,5, enc($firma), 0, 1);
    }
    if ($name !== '') {
        $pdf->SetFont('DejaVu','',10);
        $pdf->Cell(0,5, enc($name), 0, 1);
    }

    $pdf->SetFont('DejaVu','',10);
    if (!empty($client['adresse'])) {
        $pdf->Cell(0,5, enc((string)$client['adresse']), 0, 1);
    }
    $loc = trim((string)($client['plz'] ?? '') . ' ' . (string)($client['ort'] ?? ''));
    if ($loc !== '') { $pdf->Cell(0,5, enc($loc), 0, 1); }

    $pdf->Ln(8);
}

// =================================================
// INTRO — neutral line like other docs
// =================================================
$pdf->SetFont('DejaVu','',10);
if (!empty($ls['bemerkung'])) {
    $pdf->MultiCell(0,5, enc((string)$ls['bemerkung']));
    $pdf->Ln(4);
} else {
    $pdf->MultiCell(0,5, enc('Folgende Artikel wurden geliefert:'));
    $pdf->Ln(6);
}

// =================================================
// ITEMS TABLE (CENTERED, LIGHT) — same rendering helpers
// =================================================
$layout = pdf_table_layout('lieferschein');
[$w1,$w2] = $layout['widths'];
$tableX = (210 - ($w1+$w2)) / 2;

$pdf->SetX($tableX);
$pdf->SetFont('DejaVu','B',10);
$pdf->SetDrawColor(200,200,200);
$pdf->Cell($w1, 8, enc('Artikel / Beschreibung'), 'B', 0, 'L');
$pdf->Cell($w2, 8, enc('Menge'), 'B', 1, 'C');

$pdf->SetFont('DejaVu','',10);
$items = fetch_lieferschein_items($pdo, (int)$lieferschein_id);
$lineH = 5.2; $pad = 2.0;
foreach ($items as $row) {
    $name = trim((string)($row['name'] ?? ''));
    $desc = trim((string)($row['description'] ?? ''));
    $col1 = $name;
    if ($desc !== '') {
        $col1 = $name !== '' ? ($name . "\n" . $desc) : $desc;
    }
    $qty = isset($row['qty']) ? (float)$row['qty'] : 0.0;
    $qty_disp = (fmod($qty, 1.0) === 0.0) ? number_format((int)round($qty), 0, ',', '.') : number_format($qty, 2, ',', '.');

    pdf_table_Row(
        $pdf,
        [
            (string)$col1,
            (string)$qty_disp,
        ],
        [$w1,$w2],
        ['L','C'],
        $lineH,
        $tableX,
        false,
        [250,250,250],
        true,
        2.0,
        [0] // wrap first column (Artikel/Beschreibung)
    );
}

$pdf->Ln(8);

// =================================================
// HINWEIS (optional) — show any client note
// =================================================
$__note = '';
foreach (['hinweis','bemerkung','notiz','notes','comment','kommentar','text'] as $__k) {
    if (!empty($ls[$__k])) { $__note = trim((string)$ls[$__k]); break; }
}
if ($__note !== '') {
    $pdf->SetFont('DejaVu','B',10);
    $pdf->Cell(0,6, enc('Hinweis'), 0, 1);
    $pdf->SetFont('DejaVu','',9);
    $pdf->MultiCell(0,5, enc($__note));
    $pdf->Ln(6);
}

// =================================================
// NOTE — closing information
// =================================================
$pdf->SetFont('DejaVu','',9);
$pdf->MultiCell(0,5, enc('Bitte prüfen Sie die gelieferte Ware. Etwaige Abweichungen melden Sie uns innerhalb von 3 Werktagen.'));
