<?php
// pages/rechnung_speichern.php — legacy shim and PDF body (kept for backward compatibility)
// If accessed directly (without a $pdf context), delegate to the new save handler.
if (!isset($pdf) || !is_object($pdf) || !method_exists($pdf, 'GetY')) {
    require __DIR__ . '/rechnung_save.php';
    exit;
}

require __DIR__ . '/rechnung_body.php';
return;

/* =============================================================
   ANGEBOT – BODY (MODERN AUSTRIAN STYLE)
============================================================= */

// ---- Right-side meta box ----
$topY = $pdf->GetY();
$metaX = 210 - 15 - 70;
$metaY = $topY + 8;
$metaW = 70;
$metaH = 28;

$metaLines = [
    ['font' => ['DejaVu', 'B', 11], 'text' => enc('ANGEBOT'), 'align' => 'C'],
    ['font' => ['DejaVu', '', 9],  'text' => enc('Angebotsnr.: ' . $rechnungsnummer), 'align' => 'C'],
    ['font' => ['DejaVu', '', 9],  'text' => enc('Datum: ' . date('d.m.Y', strtotime($date))), 'align' => 'C'],
];

drawBoxWithContent($pdf, $metaX, $metaY, $metaW, $metaH, $metaLines, 6, true, [30,30,30], [30,30,30]);
$pdf->Ln(22);

// ---- Client block ----
if ($client) {
    $clientLines = [];

    $nameLine = trim(($client['firma'] ?? '') . ' ' . ($client['name'] ?? ''));
    if ($nameLine !== '') {
        $clientLines[] = ['font' => ['DejaVu','B',11], 'text' => enc($nameLine), 'align' => 'L'];
    }
    if (!empty($client['adresse'])) {
        $clientLines[] = ['font' => ['DejaVu','',10], 'text' => enc($client['adresse']), 'align' => 'L'];
    }
    $loc = trim(($client['plz'] ?? '') . ' ' . ($client['ort'] ?? ''));
    if ($loc !== '') {
        $clientLines[] = ['font' => ['DejaVu','',10], 'text' => enc($loc), 'align' => 'L'];
    }

    $boxX = 12;
    $boxW = 130;
    $boxH = 30;
    $yNow = $pdf->GetY();

    drawBoxWithContent(
        $pdf,
        $boxX,
        $yNow,
        $boxW,
        $boxH,
        $clientLines,
        6,
        true,
        [220,220,220],
        [246,246,247]
    );

    $pdf->SetY($yNow + $boxH + 8);
}

// ---- Intro text ----
$pdf->SetFont('DejaVu','',10);
$pdf->MultiCell(
    0,
    5,
    enc(
        "Sehr geehrte Damen und Herren,\n\n" .
        "vielen Dank für Ihre Anfrage. Gerne unterbreiten wir Ihnen folgendes Angebot:"
    )
);
$pdf->Ln(6);

// ---- Positions table ----
$w1 = 92; $w2 = 26; $w3 = 36; $w4 = 36;
$tableX = 15;

$pdf->SetX($tableX);
$pdf->SetFillColor(240,240,240);
$pdf->SetDrawColor(220,220,220);
$pdf->SetFont('DejaVu','B',10);

$pdf->Cell($w1,9, enc('Leistung / Beschreibung'), 1, 0, 'L', true);
$pdf->Cell($w2,9, enc('Menge'), 1, 0, 'C', true);
$pdf->Cell($w3,9, enc('Einzelpreis'), 1, 0, 'R', true);
$pdf->Cell($w4,9, enc('Gesamt'), 1, 1, 'R', true);

$pdf->SetFont('DejaVu','',10);

// ---- Load positions ----
$stmt = $pdo->prepare(
    'SELECT COALESCE(p.name, rp.beschreibung) AS name, rp.menge, rp.einzelpreis, rp.gesamt
     FROM rechnungs_positionen rp
     LEFT JOIN produkte p ON rp.produkt_id = p.id
     WHERE rp.rechnung_id = ?
     ORDER BY rp.id ASC'
);
$stmt->execute([$rechnung_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$lineH = 4.2;
$pad = 2;
$rowFill = false;

foreach ($rows as $row) {
    $name   = enc((string)$row['name']);
    $menge  = number_format((float)$row['menge'], 2, ',', '.');
    $price  = number_format((float)$row['einzelpreis'], 2, ',', '.') . ' €';
    $total  = number_format((float)$row['gesamt'], 2, ',', '.') . ' €';

    $ln = nbLinesWidth($pdf, $w1, $name);
    $h  = max($lineH, $ln * $lineH);

    $y = $pdf->GetY();
    $x = $tableX;

    if ($rowFill) {
        $pdf->SetFillColor(250,250,250);
        $pdf->Rect($x, $y, $w1 + $w2 + $w3 + $w4, $h, 'F');
    }

    $pdf->Rect($x, $y, $w1, $h);
    $pdf->Rect($x + $w1, $y, $w2, $h);
    $pdf->Rect($x + $w1 + $w2, $y, $w3, $h);
    $pdf->Rect($x + $w1 + $w2 + $w3, $y, $w4, $h);

    $pdf->SetXY($x + $pad, $y);
    $pdf->MultiCell($w1 - 2*$pad, $lineH, $name);

    $pdf->SetXY($x + $w1, $y);
    $pdf->Cell($w2, $h, enc($menge), 0, 0, 'C');

    $pdf->SetXY($x + $w1 + $w2, $y);
    $pdf->Cell($w3, $h, enc($price), 0, 0, 'R');

    $pdf->SetXY($x + $w1 + $w2 + $w3, $y);
    $pdf->Cell($w4, $h, enc($total), 0, 0, 'R');

    $pdf->SetY($y + $h);
    $rowFill = !$rowFill;
}

// ---- Totals ----
$pdf->Ln(6);
$rightX = 210 - 15 - 80;
$pdf->SetXY($rightX, $pdf->GetY());

$pdf->SetFont('DejaVu','',10);
$pdf->Cell(80,7, enc('Zwischensumme'), 1, 0, 'L', true);
$pdf->Cell(-80,7, enc(number_format($netTotal, 2, ',', '.') . ' €'), 1, 1, 'R', true);

$pdf->SetFont('DejaVu','B',11);
$pdf->Cell(80,10, enc('Angebotssumme'), 1, 0, 'L', true);
$pdf->Cell(-80,10, enc(number_format($grossTotal, 2, ',', '.') . ' €'), 1, 1, 'R', true);

// Ve
$pdf->Cell(-80,10, enc(number_format($grossTotal, 2, ',', '.') . ' €'), 1, 1, 'R', true);

// ---- Offer validity ----
$pdf->Ln(6);
$pdf->SetFont('DejaVu','',9);
$pdf->MultiCell(
    0,
    5,
    enc(
        "Dieses Angebot ist freibleibend und 14 Tage ab Angebotsdatum gültig.\n" .
        "Alle Preise verstehen sich in Euro. Änderungen und Irrtümer vorbehalten."
    )
);
