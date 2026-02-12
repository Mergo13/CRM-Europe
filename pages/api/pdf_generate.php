<?php
declare(strict_types=0);
global $pdo;

require_once __DIR__ . '/../../vendor/setasign/fpdf/fpdf.php';
require_once __DIR__ . '/../../config/db.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');


// ----------------------------
// GET INPUT
// ----------------------------
$type = $_GET['type'] ?? $_POST['type'] ?? null;
$id = $_GET['id'] ?? $_POST['id'] ?? null;

if (!$type || !$id) {
    die("Missing type or id");
}

// ----------------------------
// CONFIG FOR ALL DOCUMENT TYPES
// ----------------------------
$basePdfDir = __DIR__ . "/../../pdf";

$config = [

    "rechnung" => [
        "table" => "rechnungen",
        "id_field" => "id",
        "nr_field" => "rechnungsnummer",
        "date_field" => "datum",
        "client_field" => "client_id",

        "items_table" => "rechnungs_positionen",
        "items_fk" => "rechnung_id",

        "pdf_dir" => "$basePdfDir/rechnungen",
        "prefix" => "RE-",

        "title" => "Rechnung",
        "nr_label" => "Rechnungsnummer",
        "intro" => "Vielen Dank für Ihren Auftrag.",
    ],

    "angebot" => [
        "table" => "angebote",
        "id_field" => "id",
        "nr_field" => "angebotsnummer",
        "date_field" => "datum",
        "client_field" => "client_id",

        "items_table" => "angebote_positionen",
        "items_fk" => "angebot_id",

        "pdf_dir" => "$basePdfDir/angeboten",
        "prefix" => "AN-",

        "title" => "Angebot",
        "nr_label" => "Angebotsnummer",
        "intro" => "Wir freuen uns, Ihnen folgendes Angebot zu unterbreiten.",
    ],

    "lieferschein" => [
        "table" => "lieferscheine",
        "id_field" => "id",
        "nr_field" => "nummer",
        "date_field" => "datum",
        "client_field" => "client_id",

        "items_table" => "lieferschein_positionen",
        "items_fk" => "lieferschein_id",

        "pdf_dir" => "$basePdfDir/lieferscheine",
        "prefix" => "LS-",

        "title" => "Lieferschein",
        "nr_label" => "Lieferscheinnummer",
        "intro" => "Hiermit bestätigen wir die Lieferung folgender Artikel.",
    ],

    "mahnung" => [
        "table" => "mahnungen",
        "id_field" => "id",
        "nr_field" => "rechnung_id", // load invoice number

        "date_field" => "datum",

        "pdf_dir" => "$basePdfDir/mahnungen",
        "prefix" => "MA-",

        "title" => "Mahnung",
        "nr_label" => "Rechnungsnummer",
        "intro" => "Zu der unten aufgeführten Rechnung ist keine Zahlung eingegangen.",
    ],

];

if (!isset($config[$type])) {
    die("Invalid type");
}

$cfg = $config[$type];

// ----------------------------
// LOAD MAIN RECORD
// ----------------------------
$stmt = $pdo->prepare("SELECT * FROM {$cfg['table']} WHERE {$cfg['id_field']} = ?");
$stmt->execute([$id]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
    die("Document not found.");
}

// ----------------------------
// SPECIAL CASE: MAHNUNG (loads invoice + client)
// ----------------------------
if ($type === "mahnung") {

    $invoiceId = $doc["rechnung_id"];

    $stmtInv = $pdo->prepare("SELECT * FROM rechnungen WHERE id = ?");
    $stmtInv->execute([$invoiceId]);
    $invoice = $stmtInv->fetch(PDO::FETCH_ASSOC);

    $clientId = $invoice["client_id"];
    $mainNr = $invoice["rechnungsnummer"];
} else {
    $clientId = $doc[$cfg["client_field"]];
    $mainNr = $doc[$cfg["nr_field"]];
}

// ----------------------------
// LOAD CLIENT
// ----------------------------
$stmtC = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
$stmtC->execute([$clientId]);
$client = $stmtC->fetch(PDO::FETCH_ASSOC);

// ----------------------------
// LOAD POSITIONEN
// ----------------------------
$items = [];

if (!empty($cfg["items_table"])) {
    $stmtI = $pdo->prepare("SELECT * FROM {$cfg['items_table']} WHERE {$cfg['items_fk']} = ?");
    $stmtI->execute([$id]);
    $items = $stmtI->fetchAll(PDO::FETCH_ASSOC);
}

// ----------------------------
// PDF SAVE DIRECTORY: type/year/month
// ----------------------------
$year = date("Y");
$month = date("m");

$saveDir = "{$cfg['pdf_dir']}/$year/$month";
if (!is_dir($saveDir)) if (!mkdir($saveDir, 0775, true) && !is_dir($saveDir)) {
    throw new \RuntimeException(sprintf('Directory "%s" was not created', $saveDir));
}

$safeNr = preg_replace('/[^0-9A-Za-z_-]/', '_', $mainNr);
$filename = "{$cfg['prefix']}{$safeNr}.pdf";
$fullPath = "$saveDir/$filename";

// ----------------------------
// BUILD THE PDF (FPDF)
// ----------------------------
$pdf = new FPDF();
$pdf->AddPage();

$pdf->AddFont('DejaVu','','DejaVuSans.ttf',true);
$pdf->AddFont('DejaVu','B','DejaVuSans-Bold.ttf',true);
$pdf->SetFont('DejaVu','',12);


$pdf->SetFont('DejaVu','B',16);
$pdf->Cell(0, 10, $cfg["title"], 0, 1);

$pdf->SetFont('DejaVu','',12);
$pdf->Cell(0, 8, "{$cfg['nr_label']}: $mainNr", 0, 1);
$pdf->Cell(0, 8, "Datum: " . $doc[$cfg['date_field']], 0, 1);

$pdf->Ln(5);


$pdf->SetFont('DejaVu','B',16);
$pdf->SetFont('DejaVu','',12);
$pdf->SetFont('DejaVu','B',12);

// CLIENT DATA
$pdf->SetFont('DejaVu','B',12);
$pdf->Cell(0, 8, "Kunde:", 0, 1);

$pdf->SetFont('DejaVu','',12);
$pdf->Cell(0, 6, ($client["firma"] ?? $client["name"]), 0, 1);
$pdf->Cell(0, 6, $client["adresse"], 0, 1);
$pdf->Cell(0, 6, $client["plz"] . " " . $client["ort"], 0, 1);

$pdf->Ln(5);

$pdf->MultiCell(0, 6, $cfg["intro"]);
$pdf->Ln(5);

// ----------------------------
// POSITIONEN TABLE
// ----------------------------
if ($items) {

    $pdf->SetFont('DejaVu','B',12);
    $pdf->Cell(80, 8, "Produkt", 1);
    $pdf->Cell(30, 8, "Menge", 1);
    $pdf->Cell(40, 8, "Einzelpreis", 1);
    $pdf->Cell(40, 8, "Gesamt", 1);
    $pdf->Ln();

    $pdf->SetFont('DejaVu','',12);

    foreach ($items as $pos) {
        $pdf->Cell(80, 8, $pos["produkt_id"], 1);
        $pdf->Cell(30, 8, $pos["menge"], 1);
        $pdf->Cell(40, 8, number_format($pos["einzelpreis"], 2), 1);
        $pdf->Cell(40, 8, number_format($pos["gesamt"], 2), 1);
        $pdf->Ln();
    }
}

// ----------------------------
// SAVE + OUTPUT
// ----------------------------
$pdf->Output("F", $fullPath);   // save file
$pdf->Output("I", $filename);   // show in browser
exit;
