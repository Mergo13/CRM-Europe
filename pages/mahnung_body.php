<?php
// pages/mahnung_body.php — PDF BODY ONLY for a reminder (Mahnung)
// Expects: $pdf (FPDF-like), $pdo (PDO), and $mahnung_id in scope.

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

$mahnung_id = $mahnung_id ?? null;
if (!$mahnung_id && isset($_GET['id'])) {
    $mahnung_id = (int)preg_replace('/[^0-9]/','', (string)$_GET['id']);
}
if (!$mahnung_id) {
    $pdf->SetFont('DejaVu','',10);
    $pdf->SetTextColor(180,0,0);
    $pdf->MultiCell(0,5, enc('Mahnungs-ID fehlt.'));
    $pdf->SetTextColor(0,0,0);
    return;
}

try {
    $stmt = $pdo->prepare('SELECT * FROM mahnungen WHERE id = ? LIMIT 1');
    $stmt->execute([$mahnung_id]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$m) {
        $pdf->SetFont('DejaVu','',10);
        $pdf->SetTextColor(180,0,0);
        $pdf->MultiCell(0,5, enc('Mahnung nicht gefunden.'));
        $pdf->SetTextColor(0,0,0);
        return;
    }
} catch (Throwable $e) {
    $pdf->SetFont('DejaVu','',10);
    $pdf->SetTextColor(180,0,0);
    $pdf->MultiCell(0,5, enc('Fehler beim Laden der Mahnung.'));
    $pdf->SetTextColor(0,0,0);
    return;
}

// Load invoice + client for addressing
$rechnung = null; $client = null; $rechnungsnummer = '';
if (!empty($m['rechnung_id'])) {
    try {
        $rs = $pdo->prepare('SELECT * FROM rechnungen WHERE id = ?');
        $rs->execute([(int)$m['rechnung_id']]);
        $rechnung = $rs->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($rechnung) {
            $rechnungsnummer = $rechnung['rechnungsnummer'] ?? (string)$rechnung['id'];
            if (!empty($rechnung['client_id'])) {
                $cs = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
                $cs->execute([(int)$rechnung['client_id']]);
                $client = $cs->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        }
    } catch (Throwable $e) {}
}

$stufe = (int)($m['stufe'] ?? 0);
$datum = !empty($m['datum']) ? date('d.m.Y', strtotime((string)$m['datum'])) : date('d.m.Y');
$daysOver = (int)($m['days_overdue'] ?? 0);
// Amounts must strictly equal the original invoice total — no fees, no interest, no changes
$invoiceTotal = 0.0;
if ($rechnung) {
    if (isset($rechnung['gesamt']) && is_numeric($rechnung['gesamt']) && $rechnung['gesamt'] !== '') {
        $invoiceTotal = (float)$rechnung['gesamt'];
    } elseif (isset($rechnung['total']) && is_numeric($rechnung['total']) && $rechnung['total'] !== '') {
        $invoiceTotal = (float)$rechnung['total'];
    } elseif (isset($rechnung['betrag']) && is_numeric($rechnung['betrag']) && $rechnung['betrag'] !== '') {
        $invoiceTotal = (float)$rechnung['betrag'];
    }
}
$net  = $invoiceTotal; // display-only net equals invoice total if VAT is unknown here
$vatP = 0.0; // do not calculate VAT here; keep amount identical to invoice amount shown below
$vatA = 0.0;
$intP = 0.0; $intA = 0.0; $fee = 0.0;
$total= $invoiceTotal;

// Header badge
// Ensure a consistent start position below the header
$minStartY = 52; // aligns with Angebot/Rechnung baseline below branding header
if ($pdf->GetY() < $minStartY) { $pdf->SetY($minStartY); }

$topY = $pdf->GetY();
$metaX = 210 - 15 - 70; $metaY = $topY + 2; $metaW = 70; $metaH = 28;
$pdf->SetDrawColor(30,30,30); $pdf->SetFillColor(30,30,30);

$pdf->SetTextColor(10,10,10);
// Make title a little bigger and left-aligned, with invoice number below it
$pdf->SetFont('DejaVu','B',11);
// Title per stage (must match Austrian formal wording)
$__title = '';
switch ($stufe) {
    case 0:
        $__title = 'Zahlungserinnerung';
        break;
    case 1:
        $__title = '1. Mahnung';
        break;
    case 2:
        $__title = 'Letzte Mahnung';
        break;
    default:
        $__title = 'Ankündigung der Inkassoübergabe';
        break;
}
$pdf->SetXY($metaX + 2, $metaY + 4);
$pdf->MultiCell($metaW - 4, 5.5, enc($__title), 0, 'L');
// Invoice number on its own line, left-aligned
$pdf->SetFont('DejaVu','',9);
$pdf->SetX($metaX + 2);
$pdf->Cell($metaW - 4, 5, enc('Rechnung Nr. ' . $rechnungsnummer), 0, 2, 'L');
// Date line, left-aligned for consistency
$pdf->SetFont('DejaVu','',8);
$pdf->SetX($metaX + 2);
$pdf->Cell($metaW - 4, 5, enc('Datum: ' . (!empty($m['created_at']) ? date('d.m.Y', strtotime((string)$m['created_at'])) : date('d.m.Y'))), 0, 2, 'L');
$pdf->SetTextColor(0,0,0);
// Slightly reduce spacing to compensate for the extra invoice number line
$pdf->Ln(17);

// Client block
if ($client) {
    $pdf->SetDrawColor(220, 220, 220);
    $pdf->SetFillColor(246, 246, 247);
    $boxX = 12;
    $boxW = 130;
    $boxH = 30;
    $yNow = $pdf->GetY();

    $pdf->SetXY($boxX + 2, $yNow + 3);
    $pdf->SetFont('DejaVu', 'B', 14);
    if (!empty($client['firma'])) {
        $pdf->SetX($boxX + 2);
        $pdf->Cell($boxW - 4, 5, enc($client['firma']), 2, 1, 'L');
        $pdf->SetFont('DejaVu', '', 10);
        if (!empty($client['name'])) {
            $pdf->SetX($boxX + 2);
            $pdf->Cell($boxW - 4, 5, enc($client['name']), 0, 1, 'L');
        }
    } else if (!empty($client['name'])) {
        $pdf->SetX($boxX + 2);
        $pdf->Cell($boxW - 4, 5, enc($client['name']), 0, 1, 'L');
    }
    $pdf->SetFont('DejaVu', '', 10);
    if (!empty($client['adresse'])) {
        $pdf->SetX($boxX + 2);
        $pdf->Cell($boxW - 4, 5, enc((string)$client['adresse']), 0, 1, 'L');
    }
    $loc = trim(($client['plz'] ?? '') . ' ' . ($client['ort'] ?? ''));
    if ($loc !== '') {
        $pdf->SetX($boxX + 2);
        $pdf->Cell($boxW - 4, 5, enc($loc), 0, 1, 'L');
    }
    $pdf->SetY($yNow + $boxH + 8);
}

// Text (stage-specific, Austrian formal language)
$pdf->SetFont('DejaVu','',10);
$invoice_date_str = '';
if ($rechnung && !empty($rechnung['datum'])) {
    $invoice_date_str = date('d.m.Y', strtotime((string)$rechnung['datum']));
}
$greeting = 'Sehr geehrte Damen und Herren,';
$pdf->MultiCell(0,5, enc($greeting));
$pdf->Ln(2);

$para = '';
// Determine interest mention eligibility based on stored amounts
$__mention_interest = false; // Interest is not mentioned per requirement

// Compute a clear payment deadline date (Zahlungsziel)
$deadlineDays = isset($m['due_days']) && (int)$m['due_days'] > 0 ? (int)$m['due_days'] : 7;
$baseTs = !empty($m['datum']) ? strtotime((string)$m['datum']) : time();
$deadlineDate = date('d.m.Y', strtotime('+' . $deadlineDays . ' days', $baseTs));

// If a custom text is provided in mahnungen.text, prefer it over defaults
$customText = trim((string)($m['text'] ?? ''));
if ($customText !== '') {
    $para = $customText;
} else {
switch ($stufe) {
    case 0:
        // Zahlungserinnerung
        $para = 'Zahlungserinnerung zur Rechnung Nr. ' . $rechnungsnummer;
        if ($invoice_date_str !== '') {
            $para .= ' vom ' . $invoice_date_str;
        }
        $para .= '. Der derzeit offene Gesamtbetrag beträgt ' . format_currency_eur($total) . '. ';
        $para .= 'Wir ersuchen um Überweisung bis spätestens ' . $deadlineDate . '. ';
        // Mandatory sentence
        $para .= 'Sofern die Zahlung bereits erfolgt ist, betrachten Sie dieses Schreiben bitte als gegenstandslos.';
        break;
    case 1:
        // 1. Mahnung
        $para = 'Der Betrag aus der Rechnung Nr. ' . $rechnungsnummer;
        if ($invoice_date_str !== '') { $para .= ' vom ' . $invoice_date_str; }
        $para .= ' ist überfällig. Der aktuell offene Gesamtbetrag beträgt ' . format_currency_eur($total) . '. ';
        if ($__mention_interest) {
            $para .= 'Gemäß den gesetzlichen Bestimmungen fallen ab Verzug Verzugszinsen an.' . ' ';
        }
        $para .= 'Wir ersuchen um Ausgleich bis spätestens ' . $deadlineDate . '.';
        break;
    case 2:
        // Letzte Mahnung
        $para = 'Wir fordern Sie hiermit letztmalig auf, den offenen Betrag in Höhe von ' . format_currency_eur($total) . ' zu begleichen. ';
        $para .= 'Die Forderung bezieht sich auf die Rechnung Nr. ' . $rechnungsnummer;
        if ($invoice_date_str !== '') { $para .= ' vom ' . $invoice_date_str; }
        if ($__mention_interest) {
            $para .= '. Es wurden Verzugszinsen gemäß den gesetzlichen Bestimmungen berechnet';
            $para .= ' und werden bis zum Zahlungseingang weitergeführt';
        }
        $para .= '. Bitte leisten Sie die Zahlung bis spätestens ' . $deadlineDate . '. ';
        $para .= 'Dies ist unsere letzte Erinnerung. Für Rückfragen oder bei Unklarheiten kontaktieren Sie uns bitte umgehend.';
        break;
    default:
        // Inkasso-Ankündigung
        $para = 'Alle gesetzten Zahlungsfristen sind abgelaufen. ';
        $para .= 'Mangels Zahlung beabsichtigen wir, die offene Forderung an ein Inkassounternehmen zu übergeben. ';
        $para .= 'Hierdurch können zusätzliche Kosten gemäß den gesetzlichen Bestimmungen entstehen. ';
        $para .= 'Ein Ausgleich des offenen Betrages von ' . format_currency_eur($total) . ' bis zum Versand an das Inkassounternehmen verhindert die Übergabe.';
        break;
}
$pdf->MultiCell(0,5, enc($para));
$pdf->Ln(6);
}

// Amounts table (fixed widths). Show only the original invoice amount as required (no fees, no interest)
$tblX = (210 - 90) / 2; // center a 90mm table
$tblW = 90; $colL = 60; $colR = 30;
$yStart = $pdf->GetY();
$pdf->SetFont('DejaVu','',10);
$pdf->SetXY($tblX, $yStart);
$pdf->Cell($colL,8, enc('Offener Rechnungsbetrag'), 1, 0, 'L', true);
$pdf->Cell($colR,8, enc(format_currency_eur($total)), 1, 1, 'R', true);
$pdf->SetX($tblX);
$pdf->SetFont('DejaVu','B',11);
$pdf->Cell($colL,10, enc('Gesamt fällig'), 1, 0, 'L', true);
$pdf->Cell($colR,10, enc(format_currency_eur($total)), 1, 1, 'R', true);

// Optional client note (Hinweis) under the amount table
$__note = '';
foreach (['hinweis','bemerkung','notiz','notes','comment','kommentar','text'] as $__k) {
    if (!empty($m[$__k])) { $__note = trim((string)$m[$__k]); break; }
}
if ($__note !== '') {
    $pdf->Ln(6);
    $pdf->SetFont('DejaVu','B',10);
    $pdf->Cell(0,6, enc('Hinweis'), 0, 1);
    $pdf->SetFont('DejaVu','',9);
    $pdf->MultiCell(0,5, enc($__note));
}

$pdf->Ln(6);
$pdf->SetFont('DejaVu','',10);
$closing = '';
switch ($stufe) {
    case 0:
        $closing = 'Wir danken Ihnen für Ihre rasche Überweisung.';

        break;
    case 1:
        $closing = 'Bitte führen Sie die Zahlung innerhalb der genannten Frist durch. Sofern die Zahlung bereits erfolgt ist, betrachten Sie dieses Schreiben bitte als gegenstandslos.';
        break;
    case 2:
        $closing = 'Bitte leisten Sie Zahlung innerhalb der genannten Frist. Sollte bis dahin kein Zahlungseingang eingelangt sein, behalten wir uns vor, rechtliche Schritte einzuleiten.';
        break;
    default:
        $closing = 'Ein Ausgleich bis zur angekündigten Übergabe verhindert weitere Schritte.';
        break;
}
$pdf->MultiCell(0,5, enc($closing));
