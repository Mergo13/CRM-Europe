<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/tax.php';
require_once __DIR__ . '/../includes/vies.php';

$pdo = $GLOBALS['pdo'] ?? ($pdo ?? null);
if (!($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Keine Datenbankverbindung']);
    exit;
}

function json_out(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_out(['success' => false, 'error' => 'Method not allowed'], 405);
    }

    // Ensure schema changes that may cause implicit commits are executed before starting a transaction (MySQL auto-commits on DDL)
    try { $pdo->exec('ALTER TABLE rechnungen ADD COLUMN hinweis TEXT NULL'); } catch (Throwable $e) {}

    $pdo->beginTransaction();

    /* -------------------------------------------------
       BASIC INPUTS
    ------------------------------------------------- */
    $clientId = ($_POST['client_id'] ?? '') !== '' ? (int)$_POST['client_id'] : null;

    $firma   = trim($_POST['firma'] ?? '');
    $name    = trim($_POST['name'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $telefon = trim($_POST['telefon'] ?? '');
    $adresse = trim($_POST['adresse'] ?? '');
    $plz     = trim($_POST['plz'] ?? '');
    $ort     = trim($_POST['ort'] ?? '');
    $atu     = trim($_POST['atu'] ?? '');

    $date   = $_POST['datum'] ?? date('Y-m-d');
    $status = $_POST['status'] ?? 'offen';
    $rechnungsnummer = trim($_POST['rechnungsnummer'] ?? '');

    $rechnungId = isset($_POST['rechnung_id']) && is_numeric($_POST['rechnung_id'])
        ? (int)$_POST['rechnung_id']
        : null;

    $isUpdate = $rechnungId && $rechnungId > 0;

    /* -------------------------------------------------
       POSITIONS ARRAYS
    ------------------------------------------------- */
    $productIds = $_POST['product_id'] ?? [];
    $mengeArr   = $_POST['menge'] ?? [];
    $preisArr   = $_POST['preis'] ?? [];
    $gesamtArr  = $_POST['gesamt'] ?? [];
    $beschrArr  = $_POST['beschreibung'] ?? [];

    if (empty($productIds)) {
        json_out(['success' => false, 'error' => 'Keine Positionen ausgewählt.'], 400);
    }

    /* -------------------------------------------------
       CREATE CLIENT IF NEEDED
    ------------------------------------------------- */
    if (!$clientId) {
        if ($firma === '' && $name === '') {
            json_out(['success' => false, 'error' => 'Bitte Kunden auswählen oder eingeben.'], 400);
        }

        $kundennummer = 'K' . date('Ymd') . '-' . random_int(1000, 9999);

        $stmt = $pdo->prepare("
            INSERT INTO clients (kundennummer, firma, name, email, telefon, adresse, plz, ort, atu)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $kundennummer,
            $firma ?: null,
            $name ?: null,
            $email ?: null,
            $telefon ?: null,
            $adresse ?: null,
            $plz ?: null,
            $ort ?: null,
            $atu ?: null
        ]);

        $clientId = (int)$pdo->lastInsertId();
    }


    /* -------------------------------------------------
       GENERATE INVOICE NUMBER (IF EMPTY)
    ------------------------------------------------- */
    if ($rechnungsnummer === '') {
        $prefix = 'R-' . date('Ymd', strtotime($date)) . '-';

        // Get last sequence for THIS datum (same day) and +1
        $stmt = $pdo->prepare("
            SELECT MAX(CAST(SUBSTRING(rechnungsnummer, -4) AS UNSIGNED)) AS max_seq
            FROM rechnungen
            WHERE datum = ?
              AND rechnungsnummer LIKE ?
        ");
        $stmt->execute([$date, $prefix . '%']);
        $seq = ((int)($stmt->fetchColumn() ?: 0)) + 1;

        $rechnungsnummer = $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
    }

    /* -------------------------------------------------
       INSERT / UPDATE INVOICE HEADER
    ------------------------------------------------- */
    $faelligkeit = date('Y-m-d', strtotime($date . ' +14 days'));
    $hinweis = trim($_POST['hinweis'] ?? '');


    if ($isUpdate) {

        $stmt = $pdo->prepare("
            UPDATE rechnungen
            SET client_id=?, rechnungsnummer=?, datum=?, faelligkeit=?, status=?, hinweis=?
            WHERE id=?
        ");
        $stmt->execute([
            $clientId,
            $rechnungsnummer,
            $date,
            $faelligkeit,
            $status,
            $hinweis ?: null,
            $rechnungId
        ]);

        $pdo->prepare("DELETE FROM rechnungs_positionen WHERE rechnung_id=?")
            ->execute([$rechnungId]);

    } else {

        /* -------------------------------------------------
           TEMPORARY UNIQUE VERWENDUNGSZWECK (REQUIRED)
        ------------------------------------------------- */
        $tempVz = 'TMP-' . uniqid('', true);

        $stmt = $pdo->prepare("
            INSERT INTO rechnungen
            (client_id, rechnungsnummer, datum, betrag, faelligkeit, status, verwendungszweck)
            VALUES (?, ?, ?, 0.00, ?, ?, ?)
        ");
        $stmt->execute([
            $clientId,
            $rechnungsnummer,
            $date,
            $faelligkeit,
            $status,
            $tempVz
        ]);

        $rechnungId = (int)$pdo->lastInsertId();

        /* -------------------------------------------------
           FINAL VERWENDUNGSZWECK
        ------------------------------------------------- */
        $finalVz = $rechnungsnummer . '-' . $rechnungId;

        $pdo->prepare("
            UPDATE rechnungen
            SET verwendungszweck = ?, hinweis = ?
            WHERE id = ?
        ")->execute([$finalVz, $hinweis ?: null, $rechnungId]);
    }

    /* -------------------------------------------------
       INSERT POSITIONS
    ------------------------------------------------- */
    $insertPos = $pdo->prepare("
        INSERT INTO rechnungs_positionen
        (rechnung_id, produkt_id, beschreibung, menge, einzelpreis, gesamt)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $netTotal = 0.0;

    foreach ($productIds as $i => $pidRaw) {
        $pid = (is_numeric($pidRaw) && (int)$pidRaw > 0) ? (int)$pidRaw : null;

        $beschreibung = trim($beschrArr[$i] ?? '');
        if ($pid === null && $beschreibung === '') {
            $beschreibung = 'Manuelle Position';
        }

        $menge  = (float)str_replace(',', '.', $mengeArr[$i] ?? 1);
        $preis  = (float)str_replace(',', '.', $preisArr[$i] ?? 0);
        $gesamt = round(
            isset($gesamtArr[$i]) && $gesamtArr[$i] !== ''
                ? (float)str_replace(',', '.', $gesamtArr[$i])
                : $menge * $preis,
            2
        );

        $netTotal += $gesamt;

        $insertPos->execute([
            $rechnungId,
            $pid,
            $beschreibung,
            $menge,
            $preis,
            $gesamt
        ]);
    }

    /* -------------------------------------------------
       VAT LOGIC
    ------------------------------------------------- */
    $vatPerc = 20.0;

    $vatTotal   = round($netTotal * ($vatPerc / 100), 2);
    $grossTotal = round($netTotal + $vatTotal, 2);

    $pdo->prepare("
        UPDATE rechnungen
        SET betrag=?, gesamt=?
        WHERE id=?
    ")->execute([
        number_format($netTotal, 2, '.', ''),
        number_format($grossTotal, 2, '.', ''),
        $rechnungId
    ]);

    // Inventory movements: OUT for products or convert from offer reservation
    require_once __DIR__ . '/../includes/services/InventoryService.php';
    $inv = new InventoryService($pdo);

    $sourceOfferId = isset($_POST['source_angebot_id']) && is_numeric($_POST['source_angebot_id']) ? (int)$_POST['source_angebot_id'] : null;
    if ($sourceOfferId) {
        // Convert reservations tied to the offer into OUT; do not duplicate per line
        $inv->convertReservationToOut('angebote', $sourceOfferId);
    } else {
        foreach ($productIds as $i => $pidRaw) {
            $pid = (is_numeric($pidRaw) && (int)$pidRaw > 0) ? (int)$pidRaw : null;
            if ($pid) {
                $menge  = (float)str_replace(',', '.', $mengeArr[$i] ?? 1);
                $inv->addMovement($pid, $menge, InventoryService::TYPE_OUT, null, 'rechnungen', (int)$rechnungId, 'Ausgang via Rechnung');
            }
        }
    }

    $pdo->commit();

    json_out([
        'success' => true,
        'rechnung_id' => $rechnungId,
        'pdf_url' => '/pages/rechnung_pdf.php?id=' . $rechnungId
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_out([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], 500);
}
