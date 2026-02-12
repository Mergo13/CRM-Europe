<?php
// pages/rechnung_body.php — PDF BODY ONLY for an invoice (Rechnung)
// Modern centered Austrian layout
// Encoding + fonts preserved exactly

declare(strict_types=1);

use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\QrCode;
$GLOBALS['PDF_UNICODE'] = false;
require_once __DIR__ . '/../includes/tax.php';
if (!isset($pdf) || !is_object($pdf) || !method_exists($pdf, 'GetY')) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'This endpoint is a PDF body template and must be included by a PDF generator.'
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

$rechnung_id = $rechnung_id ?? ($_GET['id'] ?? null);
$rechnung_id = (int)preg_replace('/[^0-9]/','', (string)$rechnung_id);
if (!$rechnung_id) {
    $pdf->SetFont('DejaVu','',10);
    $pdf->SetTextColor(180,0,0);
    $pdf->MultiCell(0,5, enc('Rechnungs-ID fehlt.'));
    $pdf->SetTextColor(0,0,0);
    return;
}

// -------------------------------------------------
// Load invoice
// -------------------------------------------------
$stmt = $pdo->prepare("SELECT * FROM rechnungen WHERE id = ? LIMIT 1");
$stmt->execute([$rechnung_id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);
// Verwendungszweck (payment reference) – always defined
$verwendungszweck = trim(
    (string)(
        $verwendungszweck
        ?? ($r['verwendungszweck'] ?? '')
    )
);

if (!$r) {
    $pdf->SetFont('DejaVu','',10);
    $pdf->SetTextColor(180,0,0);
    $pdf->MultiCell(0,5, enc('Rechnung nicht gefunden.'));
    $pdf->SetTextColor(0,0,0);
    return;
}

// -------------------------------------------------
// Resolve client
// -------------------------------------------------
$client = null;
if (!empty($r['client_id'])) {
    $cs = $pdo->prepare("SELECT firma, name, adresse, plz, ort, atu FROM clients WHERE id = ?");
    $cs->execute([(int)$r['client_id']]);
    $client = $cs->fetch(PDO::FETCH_ASSOC) ?: null;
}

$rechnungsnummer = $r['rechnungsnummer'] ?? $r['id'];
$dateStr = !empty($r['datum']) ? date('d.m.Y', strtotime((string)$r['datum'])) : date('d.m.Y');

// Determine tax mode with fallback to auto if header snapshot missing
$taxMode = strtolower((string)($r['tax_mode'] ?? ''));
if ($taxMode === '' && function_exists('tax_decide_mode_for_client')) {
    $taxMode = tax_decide_mode_for_client($client ?? null);
}

// =================================================
// TITLE (CENTERED, MODERN)
// =================================================
$pdf->Ln(8);
$pdf->SetFont('DejaVu','B',18);
// Accent color for document title (muted blue), then reset
$pdf->SetTextColor(0,0,0);
$pdf->Cell(0,10, enc('RECHNUNG'), 0, 1, 'C');
$pdf->SetTextColor(0,0,0);
$pdf->Ln(6);



if (!function_exists('drawBoxWithContent')) {
    function drawBoxWithContent(
        $pdf,
        float $x,
        float $y,
        float $w,
        float $h,
        array $lines,
        int $padY = 6,
        bool $border = true,
        array $stroke = [200, 200, 200],
        array $fill = [248, 248, 249]
    ): void
    {
        $pdf->SetFillColor($fill[0], $fill[1], $fill[2]);
        $pdf->SetDrawColor($stroke[0], $stroke[1], $stroke[2]);
        $pdf->Rect($x, $y, $w, $h, $border ? 'DF' : 'F');

        $innerY = $y + $padY - 2;
        foreach ($lines as $line) {
            $font = $line['font'] ?? ['DejaVu', '', 10];
            $pdf->SetFont($font[0], $font[1], $font[2]);
            $pdf->SetXY($x + 2, $innerY);
            $pdf->Cell($w - 4, 5, enc((string)($line['text'] ?? '')), 0, 1, $line['align'] ?? 'L');
            $innerY += 5.2;
        }
    }
}

if ($client) {
    $yTop = $pdf->GetY();

    // Layout
    $leftX  = 12;
    $rightMargin = 15;
    $rightW = 70;
    $rightX = 210 - $rightMargin - $rightW;

    $gap = 6;
    $boxH = 36; // increase if you need more height for all meta lines

    // ----- Left: Client block (as lines in a box) -----
    $clientLines = [];

    $clientLines[] = ['font' => ['DejaVu','',11], 'text' => enc('Rechnung an:'), 'align' => 'L'];

    $firma = trim((string)($client['firma'] ?? ''));
    $name  = trim((string)($client['name'] ?? ''));

    if ($firma !== '') {
        $clientLines[] = ['font' => ['DejaVu','B',11], 'text' => enc($firma), 'align' => 'L'];
    }
    if ($name !== '') {
        $clientLines[] = ['font' => ['DejaVu','',10], 'text' => enc($name), 'align' => 'L'];
    }
    if (!empty($client['adresse'])) {
        $clientLines[] = ['font' => ['DejaVu','',10], 'text' => enc((string)$client['adresse']), 'align' => 'L'];
    }
    $loc = trim((string)($client['plz'] ?? '') . ' ' . (string)($client['ort'] ?? ''));
    if ($loc !== '') {
        $clientLines[] = ['font' => ['DejaVu','',10], 'text' => enc($loc), 'align' => 'L'];
    }
    $atu = trim((string)($client['atu'] ?? ''));
    if ($atu !== '') {
        $clientLines[] = ['font' => ['DejaVu','',10], 'text' => enc('ATU: ' . $atu), 'align' => 'L'];
    }

    // Compute left width so it doesn't collide with the right box
    $leftW = ($rightX - $gap) - $leftX;

    drawBoxWithContent(
        $pdf,
        $leftX,
        $yTop,
        $leftW,
        $boxH,
        $clientLines,
        6,
        false,
        [220, 220, 220],
        [246, 246, 247]
    );

    // ----- Right: Invoice meta box -----
    $rechnungsnummerKurz = (string)($rechnung['rechnungsnummer'] ?? ($rechnungsnummer ?? ''));
    $verwendungszweckVal = trim((string)($rechnung['verwendungszweck'] ?? ($verwendungszweck ?? '')));
    $rechnungsdatum      = (string)($rechnung['datum'] ?? ($datum ?? date('Y-m-d')));
    $faelligBisRaw       = (string)($rechnung['faellig_bis'] ?? $rechnung['faelligkeitsdatum'] ?? '');



    if ($rechnungsnummerKurz !== '') {
        $metaLines[] = ['font' => ['DejaVu', '', 9], 'text' => enc('Rechnungsnummer: ' . $rechnungsnummerKurz), 'align' => 'L'];
    }
$verwendungszweckPdf = str_replace('-', '', (string)$verwendungszweck);

// wherever you output the meta line:
    // Build ONE formatted verwendungszweck for both PDF + QR
    $verwendungszweckFormatted = trim((string)$verwendungszweck);

// Format: RYYYYNNNN<rest> -> R-YYYY-NNNN-<rest> (rest can grow)
    if (preg_match('/^R(\d{4})(\d{4})(\d+)$/', $verwendungszweckFormatted, $m)) {
        $verwendungszweckFormatted = 'R-' . $m[1] . '-' . $m[2] . '-' . $m[3];
    }

// PDF meta line
    $metaLines[] = [
        'font'  => ['DejaVu', '', 9],
        'text'  => enc('Verwendungszweck: ' . $verwendungszweckFormatted),
        'align' => 'L'
    ];
    $metaLines[] = ['font' => ['DejaVu', '', 9], 'text' => enc('Rechnungsdatum: ' . date('d.m.Y', strtotime($rechnungsdatum))), 'align' => 'L'];


    if ($faelligBisRaw !== '') {
        $metaLines[] = ['font' => ['DejaVu', '', 9], 'text' => enc('Fällig bis: ' . date('d.m.Y', strtotime($faelligBisRaw))), 'align' => 'L'];
    }

    // Ensure meta text is black
    $pdf->SetTextColor(0, 0, 0);

    drawBoxWithContent(
        $pdf,
        $rightX,
        $yTop,
        $rightW,
        $boxH,
        $metaLines,
        5,
        false,
        [30, 30, 30],
        [255, 255, 255]
    );

    // Continue below both boxes
    $pdf->SetY($yTop + $boxH + 8);
}

// =================================================
// INTRO
// =================================================
$pdf->SetFont('DejaVu','',10);
$pdf->MultiCell(0,5, enc('Wir erlauben uns, folgende Leistungen in Rechnung zu stellen:'));
$pdf->Ln(6);

// =================================================
// POSITIONS TABLE (CENTERED, LIGHT)
// =================================================
$layout = pdf_table_layout('rechnung');
[$w1,$w2,$w3,$w4] = $layout['widths'];
$tableX = (210 - ($w1+$w2+$w3+$w4)) / 2;

// Helper to render the table header (also used after page breaks)
if (!function_exists('__rechnung_render_header')) {
    function __rechnung_render_header($pdf, float $tableX, float $w1, float $w2, float $w3, float $w4): void {
        $pdf->SetX($tableX);
        $pdf->SetFont('DejaVu','B',10);
        $pdf->SetDrawColor(200,200,200);
        $pdf->SetFillColor(245,245,247);
        $pdf->Cell($w1, 8, enc('Leistung / Beschreibung'), 1, 0, 'L', true);
        $pdf->Cell($w2, 8, enc('Menge'), 1, 0, 'C', true);
        $pdf->Cell($w3, 8, enc('Einzelpreis'), 1, 0, 'R', true);
        $pdf->Cell($w4, 8, enc('Gesamt'), 1, 1, 'R', true);
        $pdf->SetFont('DejaVu','',10);
    }
}

$items = fetch_rechnung_items($pdo, (int)$rechnung_id);
$lineH = 5.2;     // try 5.0–5.5 for DejaVu 10pt
$pad   = 2.0;
$rowFill = false;
$alt = false;

// Track if the table header has been rendered on the current page
$__rb_headerDrawn = false;
$__rb_headerH = 8; // header row height

foreach ($items as $row) {
    $name = (string)($row['name'] ?? '');
    $desc = trim((string)($row['description'] ?? ''));

    // Estimate row height to handle page breaks before drawing
    $padX = 2.0;
    $pdf->SetFont('DejaVu','',10);
    $nameLines = pdf_table_NbLines($pdf, max(0.1, $w1 - 2*$padX), $name);
    $nameH = $nameLines * $lineH;

    $descH = 0.0; $descLineH = $lineH * 0.90;
    if ($desc !== '') {
        $pdf->SetFont('DejaVu','',9);
        $descLines = pdf_table_NbLines($pdf, max(0.1, $w1 - 2*$padX), $desc);
        $descH = $descLines * $descLineH;
    }
    $rowH_est = max($lineH, $nameH + $descH);

    // Page break / header guard
    $pageH = method_exists($pdf,'GetPageHeight') ? $pdf->GetPageHeight() : 297;
    $bottomMargin = 30; // must match BaseTemplatePdf::SetAutoPageBreak(true, 30)
    $bottom = $pageH - $bottomMargin;

    if (!($__rb_headerDrawn)) {
        // For the first row on a page, ensure there is space for header + row; otherwise start a new page
        if ($pdf->GetY() + $__rb_headerH + $rowH_est > $bottom) {
            $pdf->AddPage();
        }
        __rechnung_render_header($pdf, $tableX, $w1, $w2, $w3, $w4);
        $__rb_headerDrawn = true;
    } else {
        // Subsequent rows: if the row would overflow, break page and re-draw header once
        if ($pdf->GetY() + $rowH_est > $bottom) {
            $pdf->AddPage();
            __rechnung_render_header($pdf, $tableX, $w1, $w2, $w3, $w4);
            $__rb_headerDrawn = true;
        }
    }

    // Re-evaluate desc simple path after potential page break
    if ($desc === '') {
        // Simple row with just the name (wrap allowed in first column)
        pdf_table_Row(
            $pdf,
            [
                $name,
                (string)$row['qty_str'],
                (string)$row['unit_str'],
                (string)$row['total_str'],
            ],
            [$w1,$w2,$w3,$w4],
            ['L','C','R','R'],
            $lineH,
            $tableX,
            $alt
        );
        $alt = !$alt;
        continue;
    }

    // Custom render for name + description
    $padX = 2.0;
    $x0 = $tableX;
    $y0 = $pdf->GetY();

    // Compute height needed for name and description separately (again with current font)
    $pdf->SetFont('DejaVu','',10);
    $nameLines = pdf_table_NbLines($pdf, max(0.1, $w1 - 2*$padX), $name);
    $nameH = $nameLines * $lineH;

    $pdf->SetFont('DejaVu','',9);
    $descLineH = $lineH * 0.90;
    $descLines = pdf_table_NbLines($pdf, max(0.1, $w1 - 2*$padX), $desc);
    $descH = $descLines * $descLineH;

    $rowH = max($lineH, $nameH + $descH);

    // Background/borders for all cells
    $pdf->SetDrawColor(200,200,200);
    $pdf->Rect($x0, $y0, $w1, $rowH);
    $pdf->Rect($x0 + $w1, $y0, $w2, $rowH);
    $pdf->Rect($x0 + $w1 + $w2, $y0, $w3, $rowH);
    $pdf->Rect($x0 + $w1 + $w2 + $w3, $y0, $w4, $rowH);

    // Write name
    $pdf->SetXY($x0 + $padX, $y0 + 0.5);
    $pdf->SetFont('DejaVu','',10);
    $pdf->MultiCell($w1 - 2*$padX, $lineH, enc($name), 0, 'L');

    // Write description below
    $pdf->SetFont('DejaVu','',9);
    $pdf->SetXY($x0 + $padX, $y0 + $nameH);
    $pdf->MultiCell($w1 - 2*$padX, $descLineH, enc($desc), 0, 'L');

    // Other columns: center vertically
    $pdf->SetFont('DejaVu','',10);
    $pdf->SetXY($x0 + $w1, $y0 + ($rowH/2) - ($lineH/2));
    $pdf->Cell($w2, $lineH, enc((string)$row['qty_str']), 0, 0, 'C');

    $pdf->SetXY($x0 + $w1 + $w2, $y0 + ($rowH/2) - ($lineH/2));
    $pdf->Cell($w3, $lineH, enc((string)$row['unit_str']), 0, 0, 'R');

    $pdf->SetXY($x0 + $w1 + $w2 + $w3, $y0 + ($rowH/2) - ($lineH/2));
    $pdf->Cell($w4, $lineH, enc((string)$row['total_str']), 0, 0, 'R');

    // Move to next row
    $pdf->SetXY($x0, $y0 + $rowH);
}

$pdf->Ln(8);

// =================================================

// =================================================
// TOTALS (RIGHT ALIGNED, CLEAN)
// =================================================
// Compute totals from positions (source of truth) and derive VAT/gross by tax mode
$net = 0.0;
foreach ($items as $it) { $net += (float)($it['total'] ?? 0.0); }
$net = round($net, 2);

// Decide VAT mode: use resolved $taxMode (from client/header), default 20% in AT
$vatPerc = 20.0;
switch ($taxMode) {
    case 'eu_reverse_charge':
    case 'export':
        $vatPerc = 0.0;
        break;
}

$ust   = round($net * ($vatPerc / 100.0), 2);
$gross = round($net + $ust, 2);

$totX = 210 - 15 - 70;

$pdf->SetFont('DejaVu','',10);
$pdf->SetX($totX);
$pdf->Cell(40,6, enc('Zwischensumme (netto):'), 0, 0);
$pdf->Cell(30,6, enc(format_currency_eur($net)), 0, 1, 'R');

// VAT row only if not reverse charge
if ($vatPerc > 0.0 || $ust > 0.0) {
    $pdf->SetX($totX);
    $pdf->Cell(40,6, enc('USt. ' . number_format($vatPerc,2,',','.') . ' %:'), 0, 0);
    $pdf->Cell(30,6, enc(format_currency_eur($ust)), 0, 1, 'R');
}

$pdf->Ln(2);
$pdf->AddFont('DejaVu', '', 'DejaVuSans.php');
$pdf->AddFont('DejaVu', 'B', 'DejaVuSans-Bold.php');
$pdf->SetFont('DejaVu','B',12);
$pdf->SetX($totX);
// Accent color for total section
$pdf->SetTextColor(0,0,0);
$pdf->Cell(40,8, enc('Gesamtbetrag:'), 0, 0);
$pdf->Cell(30,8, enc(format_currency_eur($gross)), 0, 1, 'R');
$pdf->SetTextColor(0,0,0);

// Reverse charge legal note if applicable
if ($taxMode === 'eu_reverse_charge') {
    $pdf->Ln(3);
    $pdf->SetFont('DejaVu','',9);
    $pdf->SetTextColor(60,60,60);
    $pdf->MultiCell(0, 5, enc('Steuerschuldnerschaft des Leistungsempfängers gemäß Art. 196 MwSt-RL (Reverse Charge).'));
    $pdf->SetTextColor(0,0,0);
}

/* =================================================
  /* =================================================
   SEPA QR CODE (EPC STANDARD) — Endroid v6 with diagnostics
================================================= */
// Payment reference: prefer passed $verwendungszweck; fallback to DB header snapshot
$__vz = $verwendungszweckFormatted;
// Optional: enable verbose diagnostics for QR/Composer when passed from rechnung_pdf.php (?qr_debug=1)
$qr_debug = isset($qr_debug) ? (bool)$qr_debug : false;

if ($__vz !== '' && $gross > 0) {

    // simple logger
    $qrLogFile = __DIR__ . '/../logs/qr.log';
    $qr_log = static function(string $msg) use ($qrLogFile): void {
        try {
            $dir = dirname($qrLogFile);
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                    throw new \RuntimeException(sprintf('Directory "%s" was not created', $dir));
                }
            }
            $ts = date('Y-m-d H:i:s');
            @file_put_contents($qrLogFile, "[$ts] " . $msg . "\n", FILE_APPEND);
        } catch (Throwable $e) {
            // ignore logging failures
        }
    };

    if ($qr_debug) {
        $qr_log('qr_debug=1 enabled');
        $qr_log('Inputs: vz=' . $__vz . '; gross=' . number_format($gross,2,'.',''));
        $qr_log('Class check: QrCode=' . (class_exists(QrCode::class) ? 'yes' : 'no') . ', PngWriter=' . (class_exists(PngWriter::class) ? 'yes' : 'no'));
        $qr_log('sys_get_temp_dir()=' . sys_get_temp_dir() . ' writable=' . (is_writable(sys_get_temp_dir()) ? 'yes' : 'no'));
    }


// ---- BANK DATA (from settings_company id=1) ----
$setStmt = $pdo->query("SELECT creditor_name, company_name, iban, bic FROM settings_company WHERE id = 1");
$set = $setStmt ? ($setStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];

$iban = strtoupper(str_replace(' ', '', trim((string)($set['iban'] ?? ''))));
$bic  = strtoupper(str_replace(' ', '', trim((string)($set['bic'] ?? ''))));

// Prefer creditor_name for EPC beneficiary;
$empfaengerRaw = (string)($set['creditor_name'] ?? '');


// EPC-safe
$empfaenger = trim(str_replace(["\r", "\n", "\t"], ' ', $empfaengerRaw));
$empfaenger = preg_replace('/\s+/', ' ', $empfaenger) ?: $empfaenger;
$empfaenger = trim($empfaenger);
if (function_exists('mb_substr') && mb_strlen($empfaenger, 'UTF-8') > 70) {
    $empfaenger = mb_substr($empfaenger, 0, 70, 'UTF-8');
} elseif (strlen($empfaenger) > 70) {
    $empfaenger = substr($empfaenger, 0, 70);
}

// EPC QR payload

$ref = trim(str_replace(["\r", "\n", "\t"], ' ', (string)$__vz));
$ref = preg_replace('/\s+/', ' ', $ref) ?: $ref;
$ref = trim($ref);

// Keep it QR/bank-app friendly: max 35 chars for unstructured remittance
if (function_exists('mb_substr') && mb_strlen($ref, 'UTF-8') > 35) {
    $ref = mb_substr($ref, 0, 35, 'UTF-8');
} elseif (strlen($ref) > 35) {
    $ref = substr($ref, 0, 35);
}
    $epc =
        "BCD\n001\n1\nSCT\n" .
        $bic . "\n" .
        $empfaenger . "\n" .
        $iban . "\n" .
        "EUR" . number_format($gross, 2, '.', '') . "\n\n" .
        $ref;

    try {
        if (!class_exists(QrCode::class) || !class_exists(PngWriter::class)) {
            $qr_log('Endroid QrCode or PngWriter class not found. Autoload may be missing.');
            throw new RuntimeException('QR library missing');
        }
        // Build QR in a version-agnostic way (works across Endroid v3–v6)
        $qrCode = new QrCode($epc);
        if (method_exists($qrCode, 'setSize')) { $qrCode->setSize(300); }
        if (method_exists($qrCode, 'setMargin')) { $qrCode->setMargin(10); }
        $writer = new PngWriter();
        $result = $writer->write($qrCode);

        // Determine temp directory (prefer system temp; fallback to project logs/qr_tmp)
        $tmpDir = sys_get_temp_dir();
        if (!@is_writable($tmpDir)) {
            $tmpDir = __DIR__ . '/../logs/qr_tmp';
            if (!is_dir($tmpDir)) {
                if (!mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
                    throw new \RuntimeException(sprintf('Directory "%s" was not created', $tmpDir));
                }
            }
        }
        $qrPath = rtrim($tmpDir, '/\\') . '/sepa_qr_' . $rechnung_id . '.png';
        $result->saveToFile($qrPath);

        // Register cleanup (so temp file gets removed after PDF output)
        $GLOBALS['_qr_cleanup'] = $GLOBALS['_qr_cleanup'] ?? [];
        $GLOBALS['_qr_cleanup'][] = $qrPath;

        if (!is_file($qrPath) || filesize($qrPath) === 0) {
            $qr_log('QR file not written: ' . ($qrPath ?? '(null)'));
        } else {
            $qr_log('QR file created: ' . $qrPath . ' (' . @filesize($qrPath) . ' bytes)');
        }

        // Place QR under totals (bottom-right) ONLY if file exists
        if (isset($qrPath) && is_string($qrPath) && $qrPath !== '' && is_file($qrPath)) {
            $qrSize = 21; // mm
            $pageW = method_exists($pdf, 'GetPageWidth') ? (float)$pdf->GetPageWidth() : 210.0;
            $pageH = method_exists($pdf, 'GetPageHeight') ? (float)$pdf->GetPageHeight() : 297.0;
            $rightMargin = 15.0;
            $bottomMargin = 15.0;

            $qrX = $pageW - $rightMargin - $qrSize; // right-aligned inside margin
            $minY = (float)$pdf->GetY() + 4.0;      // below current content
            $maxY = $pageH - $bottomMargin - $qrSize; // ensure fully on page
            // Clamp Y into [minY, maxY]
            $qrY = max(min($minY, $maxY), 0.0);

            // Background for contrast + thin border
            try {
                $pdf->SetDrawColor(120,120,120);
                $pdf->SetFillColor(255,255,255);
                $pdf->Rect($qrX - 1, $qrY - 1, $qrSize + 2, $qrSize + 2, 'D');
            } catch (Throwable $e) { /* ignore bg errors */ }

            try {
                $pdf->Image($qrPath, $qrX, $qrY, $qrSize, $qrSize);
                $qr_log(sprintf('QR placed at x=%.2f y=%.2f size=%.2f on page %.2fx%.2f', $qrX, $qrY, $qrSize, $pageW, $pageH));
            } catch (Throwable $e) {
                $qr_log('FPDF->Image failed: ' . $e->getMessage());
                // Visible placeholder if image embedding fails
                try {
                    $pdf->SetDrawColor(160,0,0);
                    $pdf->Rect($qrX, $qrY, $qrSize, $qrSize, 'D');
                    // draw cross
                    $pdf->Line($qrX, $qrY, $qrX + $qrSize, $qrY + $qrSize);
                    $pdf->Line($qrX + $qrSize, $qrY, $qrX, $qrY + $qrSize);
                } catch (Throwable $e2) { /* ignore */ }
            }

            // Caption
            $pdf->SetFont('DejaVu', '', 8);
            $pdf->SetXY($qrX, $qrY + $qrSize + 1);
            $pdf->Cell($qrSize, 4, enc('SEPA QR-Code'), 0, 0, 'C');
        } else {
            $qr_log('QR path missing or unreadable; skip placement.');
            // Draw placeholder box to make absence visible
            $qrSize = 42;
            $pageW = method_exists($pdf, 'GetPageWidth') ? (float)$pdf->GetPageWidth() : 210.0;
            $pageH = method_exists($pdf, 'GetPageHeight') ? (float)$pdf->GetPageHeight() : 297.0;
            $rightMargin = 15.0; $bottomMargin = 15.0;
            $qrX = $pageW - $rightMargin - $qrSize;
            $minY = (float)$pdf->GetY() + 4.0;
            $maxY = $pageH - $bottomMargin - $qrSize;
            $qrY = max(min($minY, $maxY), 0.0);
            try {
                $pdf->SetDrawColor(200,0,0);
                $pdf->Rect($qrX, $qrY, $qrSize, $qrSize, 'D');
                $pdf->Line($qrX, $qrY, $qrX + $qrSize, $qrY + $qrSize);
                $pdf->Line($qrX + $qrSize, $qrY, $qrX, $qrY + $qrSize);
                $pdf->SetFont('DejaVu', '', 8);
                $pdf->SetXY($qrX, $qrY + $qrSize + 1);
                $pdf->Cell($qrSize, 4, enc('SEPA QR nicht verfügbar'), 0, 0, 'C');
            } catch (Throwable $e3) { /* ignore */ }
        }
    } catch (Throwable $e) {
        $qr_log('QR generation failed: ' . $e->getMessage());
    }



// =================================================
// HINWEIS (optional) — show any client note under totals
// =================================================
    $__note = '';
    foreach (['hinweis', 'bemerkung', 'notiz', 'notes', 'comment', 'kommentar', 'text'] as $__k) {
        if (!empty($r[$__k])) {
            $__note = trim((string)$r[$__k]);
            break;
        }
    }
    if ($__note !== '') {
        $pdf->Ln(6);
        $pdf->SetFont('DejaVu', 'B', 10);
        $pdf->Cell(0, 6, enc('Hinweis'), 0, 1);
        $pdf->SetFont('DejaVu', '', 9);
        $pdf->MultiCell(0, 5, enc($__note));
    }

// =================================================
// PAYMENT NOTE
// =================================================
    $pdf->Ln(10);
    $pdf->SetFont('DejaVu', '', 9);
    $faelligkeitsdatum = !empty($r['faelligkeit'])
        ? date('d.m.Y', strtotime((string)$r['faelligkeit']))
        : '';

    $text =
        "Wir danken Ihnen für Ihren Auftrag.\n\n" .
        "Der Rechnungsbetrag ist bis spätestens {$faelligkeitsdatum} ohne Abzug fällig.";

    $pdf->MultiCell(0, 5, enc($text));

// ... keep existing content ...

// =================================================
// SIGNATURE / COMPANY FOOTER (BOTTOM)
// =================================================
    $pdf->Ln(8);
    $pdf->SetFont('DejaVu', '', 9);
    $pdf->SetTextColor(60, 60, 60);

    $footerText =
        "Vision L&T\n" .
        "Inh. Mergim Izairi\n";

    $pdf->MultiCell(0, 4.5, enc($footerText), 0, 'L');

    $pdf->SetTextColor(0, 0, 0);

// ... keep existing content ...

}
