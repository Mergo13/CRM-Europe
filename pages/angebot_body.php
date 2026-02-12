<?php
// pages/angebot_body.php — PDF BODY ONLY for an offer (Angebot)
// Renders ONLY the body (no header/footer). Intended to be included by an FPDF/FPDI generator.
// Expects: $pdf (FPDF-like), $pdo (PDO), and $angebot_id in scope. Gracefully warns if missing.

if (!isset($pdf) || !is_object($pdf) || !method_exists($pdf, 'GetY')) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'This endpoint is a PDF body template and must be included by a PDF generator with $pdf and $pdo context.'
    ]);
    exit;
}

// Lightweight helpers (defined only if not present)
if (!function_exists('enc')) {
    function enc($s): string {
        if ($s === null) return '';
        $str = (string)$s;
        // Respect global PDF_UNICODE flag (Unicode TTF fonts like DejaVu)
        if (!empty($GLOBALS['PDF_UNICODE'])) {
            return $str;
        }
        // Prefer centralized branding helper for consistency
        if (function_exists('pdf_branding_text')) {
            return pdf_branding_text($str);
        }
        // Fallback to Windows-1252 for classic single-byte fonts (preserve umlauts)
        $converted = @iconv('UTF-8', 'Windows-1252//IGNORE', $str);
        if ($converted === false || $converted === null || $converted === '') {
            if (function_exists('mb_convert_encoding')) {
                $converted = @mb_convert_encoding($str, 'ISO-8859-1', 'UTF-8');
            }
            if ($converted === false || $converted === null) {
                $converted = preg_replace('/[^\x20-\x7E]/', '?', $str) ?? '';
            }
        }
        return $converted;
    }
}

if (!function_exists('drawBoxWithContent')) {
    /**
     * Draw a simple content box with minimal borders.
     * lines: [ ['font'=>['DejaVu','B',11], 'text'=>"...", 'align'=>'C'], ... ]
     */
    function drawBoxWithContent($pdf, float $x, float $y, float $w, float $h, array $lines, int $padY = 6, bool $border = true, array $stroke = [200,200,200], array $fill = [248,248,249]): void {
        $pdf->SetFillColor($fill[0], $fill[1], $fill[2]);
        $pdf->SetDrawColor($stroke[0], $stroke[1], $stroke[2]);
        $pdf->Rect($x, $y, $w, $h, $border ? 'DF' : 'F');
        $innerY = $y + $padY - 2;
        foreach ($lines as $line) {
            $font = $line['font'] ?? ['DejaVu','',10];
            $pdf->SetFont($font[0], $font[1], $font[2]);
            $pdf->SetXY($x + 2, $innerY);
            $pdf->Cell($w - 4, 5, enc($line['text'] ?? ''), 0, 1, $line['align'] ?? 'L');
            $innerY += 5.2;
        }
    }
}

if (!function_exists('money_eur')) {
    function money_eur($v): string { return number_format((float)$v, 2, ',', '.') . ' €'; }
}

// Ensure PDO
$pdo = $pdo ?? ($GLOBALS['pdo'] ?? null);
if (!($pdo instanceof PDO)) {
    $pdf->SetFont('DejaVu','',10);
    $pdf->SetTextColor(180,0,0);
    $pdf->MultiCell(0,5, enc('Datenbankverbindung fehlt.'));
    $pdf->SetTextColor(0,0,0);
    return;
}

// Resolve Angebot-ID
$angebot_id = $angebot_id ?? null;
if (!$angebot_id && isset($_GET['id'])) {
    $angebot_id = (int)preg_replace('/[^0-9]/','', (string)$_GET['id']);
}
if (!$angebot_id) {
    $pdf->SetFont('DejaVu','',10);
    $pdf->SetTextColor(180,0,0);
    $pdf->MultiCell(0,5, enc('Angebots-ID fehlt.'));
    $pdf->SetTextColor(0,0,0);
    return;
}

require_once __DIR__ . '/helpers.php';

// Load offer data from crm_app.angebote (table name: angebote)
try {
    $stmt = $pdo->prepare("SELECT * FROM angebote WHERE id = ? LIMIT 1");
    $stmt->execute([$angebot_id]);
    $o = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$o) {
        $pdf->SetFont('DejaVu','',10);
        $pdf->SetTextColor(180,0,0);
        $pdf->MultiCell(0,5, enc('Angebot nicht gefunden.'));
        $pdf->SetTextColor(0,0,0);
        return;
    }
} catch (Throwable $e) {
    $pdf->SetFont('DejaVu','',10);
    $pdf->SetTextColor(180,0,0);
    $pdf->MultiCell(0,5, enc('Fehler beim Laden des Angebots: ' . $e->getMessage()));
    $pdf->SetTextColor(0,0,0);
    return;
}

// Resolve client
$client = null;
if (!empty($o['client_id'])) {
    try {
        $cs = $pdo->prepare('SELECT id, firma, name, adresse, plz, ort, atu, email, telefon FROM clients WHERE id = ?');
        $cs->execute([(int)$o['client_id']]);
        $client = $cs->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { $client = null; }
}

// Format data
$angebotsnummer = $o['angebotsnummer'] ?? $o['id'];
$datumRaw   = !empty($o['datum']) ? (string)$o['datum'] : date('Y-m-d');
$datum      = date('d.m.Y', strtotime($datumRaw));
$gueltigBis = !empty($o['gueltig_bis']) ? date('d.m.Y', strtotime((string)$o['gueltig_bis'])) : '';
$status     = (string)($o['status'] ?? 'offen');

// Load items and compute totals like rechnung (shared rules)
$items = [];
try { $items = fetch_angebot_items($pdo, (int)$angebot_id); } catch (Throwable $e) { $items = []; }

// Description: use angebot.beschreibung if present, otherwise first item name, else fallback
$beschreibung = '';
if (isset($o['beschreibung'])) { $beschreibung = trim((string)$o['beschreibung']); }
if ($beschreibung === '' && !empty($items)) { $beschreibung = (string)($items[0]['name'] ?? ''); }
if ($beschreibung === '') { $beschreibung = 'Leistung laut Beschreibung'; }

// Totals
$netto = isset($o['betrag']) && is_numeric($o['betrag']) ? (float)$o['betrag'] : 0.0;
if ($netto <= 0 && !empty($items)) {
    foreach ($items as $it) { $netto += (float)$it['total']; }
}
$vatPerc = null; foreach ($items as $it) { if ($it['vat'] !== null) { $vatPerc = (float)$it['vat']; break; } }
if ($vatPerc === null) { $vatPerc = 20.0; }
$ust = round($netto * ($vatPerc/100), 2);
$brutto = $netto + $ust;

// =================================================
// TITLE (CENTERED, MODERN) — match Rechnung
// =================================================
$pdf->Ln(8);
$pdf->SetFont('DejaVu','B',18);
// Accent color for document title (muted blue), then reset
$pdf->SetTextColor(0,0,0);
$pdf->Cell(0,10, enc('ANGEBOT'), 0, 1, 'C');
$pdf->SetTextColor(0,0,0);
$pdf->Ln(6);

// =================================================
// META (2-COLUMN, CLEAN) — same coords as Rechnung
// =================================================
$pdf->SetFont('DejaVu','',10);
$y = $pdf->GetY();

$pdf->SetXY(20, $y);
$pdf->Cell(40,6, enc('Angebotsnummer:'), 0, 0);
$pdf->Cell(60,6, enc($angebotsnummer), 0, 1);

$pdf->SetX(20);
$pdf->Cell(40,6, enc('Angebotsdatum:'), 0, 0);
$pdf->Cell(60,6, enc($datum), 0, 1);

$pdf->SetXY(120, $y);
$pdf->Cell(30,6, enc('Status:'), 0, 0);
$pdf->Cell(40,6, enc($status), 0, 1);

$pdf->SetX(120);
$pdf->Cell(30,6, enc('Gültig bis:'), 0, 0);
$pdf->Cell(40,6, enc($gueltigBis), 0, 1);

$pdf->Ln(10);

// =================================================
// CLIENT BLOCK (NO BOX, MODERN) — same style as Rechnung
// =================================================
if ($client) {
    $pdf->SetFont('DejaVu','',11);
    $pdf->Cell(0,6, enc('Angebot für:'), 0, 1);

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
    $atu = trim((string)($client['atu'] ?? ''));
    if ($atu !== '') { $pdf->Cell(0,5, enc('ATU: ' . $atu), 0, 1); }

    $pdf->Ln(8);
}

// =================================================
// INTRO — same font/spacing as Rechnung
// =================================================
$pdf->SetFont('DejaVu','',10);
$pdf->MultiCell(0,5, enc('Wir erlauben uns, folgendes Angebot zu unterbreiten:'));
$pdf->Ln(6);

// =================================================
// POSITIONS TABLE — identical to Rechnung
// =================================================
$layout = pdf_table_layout('rechnung');
[$w1,$w2,$w3,$w4] = $layout['widths'];
$tableX = (210 - ($w1+$w2+$w3+$w4)) / 2;

// Helper to render the table header (also used after page breaks)
if (!function_exists('__angebot_render_header')) {
    function __angebot_render_header($pdf, float $tableX, float $w1, float $w2, float $w3, float $w4): void {
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
__angebot_render_header($pdf, $tableX, $w1, $w2, $w3, $w4);

$lineH = 5.2; $pad = 2.0;
$alt = false;

// Render rows using custom drawing so we can style name bold and show description under it
foreach ($items as $row) {
    // Prepare row content
    $name = (string)($row['name'] ?? '');
    $desc = trim((string)($row['description'] ?? ''));
    if ($desc !== '') {
        $d = $desc;
        $d = str_replace(["<br>", "<br/>", "<br />"], "\n", $d);
        $d = str_replace(["</p>", "</li>"], "\n", $d);
        $d = strip_tags($d);
        $d = preg_replace("/\n{3,}/", "\n\n", $d);
        $desc = trim($d);
    }

    // Measure row height before drawing, to handle page breaks cleanly
    $padX = 2.0;
    $pdf->SetFont('DejaVu','B',10);
    $nameLines = pdf_table_NbLines($pdf, max(0.1, $w1 - 2*$padX), $name);
    $nameH = $nameLines * $lineH;

    $descH = 0.0; $descLineH = $lineH * 0.90;
    if ($desc !== '') {
        $pdf->SetFont('DejaVu','',9);
        $descLines = pdf_table_NbLines($pdf, max(0.1, $w1 - 2*$padX), $desc);
        $descH = $descLines * $descLineH;
    }
    $rowH = max($lineH, $nameH + $descH);

    // Page break guard: if row won't fit, add a page and redraw header
    $pageH = method_exists($pdf,'GetPageHeight') ? $pdf->GetPageHeight() : 297;
    $bottomMargin = 30; // must match BaseTemplatePdf::SetAutoPageBreak(true, 30)
    $bottom = $pageH - $bottomMargin;
    if ($pdf->GetY() + $rowH > $bottom) {
        $pdf->AddPage();
        __angebot_render_header($pdf, $tableX, $w1, $w2, $w3, $w4);
    }

    // Now draw the row using the same logic as before
    $padX = 2.0;
    $x0 = $tableX;
    $y0 = $pdf->GetY();

    // Draw borders for all cells
    $pdf->SetDrawColor(200,200,200);
    $pdf->Rect($x0, $y0, $w1, $rowH);
    $pdf->Rect($x0 + $w1, $y0, $w2, $rowH);
    $pdf->Rect($x0 + $w1 + $w2, $y0, $w3, $rowH);
    $pdf->Rect($x0 + $w1 + $w2 + $w3, $y0, $w4, $rowH);

    // Write product name (bold)
    $pdf->SetXY($x0 + $padX, $y0 + 0.5);
    $pdf->SetFont('DejaVu','B',10);
    $pdf->MultiCell($w1 - 2*$padX, $lineH, enc($name), 0, 'L');

    // Write description below (normal, smaller)
    if ($desc !== '') {
        $pdf->SetFont('DejaVu','',9);
        $pdf->SetXY($x0 + $padX, $y0 + $nameH);
        $pdf->MultiCell($w1 - 2*$padX, $descLineH, enc($desc), 0, 'L');
    }

    // Other columns: vertically centered values
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
// TOTALS — same placement and format as Rechnung
// =================================================
// Prefer stored snapshot in header if present
$net = 0.0; foreach ($items as $it) { $net += (float)$it['total']; }
$taxMode = strtolower((string)($o['tax_mode'] ?? ''));
$vatPercEff = ($taxMode === 'eu_reverse_charge') ? 0.0 : 20.0;
$storedNet = isset($o['net_total']) ? (float)$o['net_total'] : null;
$storedVat = isset($o['vat_total']) ? (float)$o['vat_total'] : null;
$storedGross = isset($o['gross_total']) ? (float)$o['gross_total'] : null;
if ($storedNet !== null && $storedVat !== null && $storedGross !== null) {
    $net = $storedNet; $ust = $storedVat; $gross = $storedGross;
} else {
    $ust = round($net * ($vatPercEff/100), 2);
    $gross = $net + $ust;
}

// Enforce Reverse Charge rendering regardless of stored snapshot inconsistencies
if ($taxMode === 'eu_reverse_charge') {
    $vatPercEff = 0.0;
    $ust = 0.0;
    $gross = $net;
}

$totX = 210 - 15 - 70;

$pdf->SetFont('DejaVu','',10);
$pdf->SetX($totX);
$pdf->Cell(40,6, enc('Zwischensumme (netto):'), 0, 0);
$pdf->Cell(30,6, enc(format_currency_eur($net)), 0, 1, 'R');

// VAT row only if not reverse charge
if ($vatPercEff > 0.0 || $ust > 0.0) {
    $pdf->SetX($totX);
    $pdf->Cell(40,6, enc('USt. ' . number_format($vatPercEff,2,',','.') . ' %:'), 0, 0);
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

// Reverse charge note if applicable
if ($taxMode === 'eu_reverse_charge') {
    $pdf->Ln(3);
    $pdf->SetFont('DejaVu','',9);
    $pdf->SetTextColor(60,60,60);
    $pdf->MultiCell(0, 5, enc('Steuerschuldnerschaft des Leistungsempfängers gemäß Art. 196 MwSt-RL (Reverse Charge).'));
    $pdf->SetTextColor(0,0,0);
}

// =================================================
// HINWEIS (optional) — show any client note under totals
// =================================================
$__note = '';
foreach (['hinweis','bemerkung','notiz','notes','comment','kommentar','text'] as $__k) {
    if (!empty($o[$__k])) { $__note = trim((string)$o[$__k]); break; }
}
if ($__note !== '') {
    $pdf->Ln(6);
    $pdf->SetFont('DejaVu','B',10);
    $pdf->Cell(0,6, enc('Hinweis'), 0, 1);
    $pdf->SetFont('DejaVu','',9);
    $pdf->MultiCell(0,5, enc($__note));
}

// =================================================
// OFFER NOTE — same style as invoice note
// =================================================
$pdf->Ln(10);
$pdf->SetFont('DejaVu','',9);
$text = "Wir danken Ihnen für Ihre Anfrage.\n\n" .
        "Dieses Angebot ist freibleibend und bis {$gueltigBis} gültig.";
$pdf->MultiCell(0, 5, enc($text));

// End of body — no header/footer here.
