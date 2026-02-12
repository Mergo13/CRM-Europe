<?php
// scripts/import_csvs.php
// CLI: import CSVs in ../db_crm into database (works with MySQL or SQLite fallback)

ini_set('display_errors', 1);
error_reporting(E_ALL);
global $pdo;
require __DIR__ . '/../config/db.php';

$csvDir = __DIR__ . '/../db_crm';
if (!is_dir($csvDir)) {
    echo "Fehler: csv-Ordner nicht gefunden: {$csvDir}\n";
    exit(1);
}

/**
 * Helper: create tables if not exist
 * (simple schemas based on CSV names found in your upload)
 */
$create = [];

// clients
$create['clients'] = "CREATE TABLE IF NOT EXISTS clients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT,
    adresse TEXT,
    plz TEXT,
    ort TEXT,
    email TEXT,
    telefon TEXT,
    uid TEXT
)";

// produkte
$create['produkte'] = "CREATE TABLE IF NOT EXISTS produkte (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT,
    beschreibung TEXT,
    preis REAL
)";

// rechnungen + positions
$create['rechnungen'] = "CREATE TABLE IF NOT EXISTS rechnungen (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nummer TEXT,
    client_id INTEGER,
    datum TEXT,
    faelligkeit TEXT,
    betrag REAL,
    status TEXT
)";
$create['rechnungs_positionen'] = "CREATE TABLE IF NOT EXISTS rechnungs_positionen (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    rechnung_id INTEGER,
    produkt_id INTEGER,
    menge REAL,
    einzelpreis REAL,
    gesamt REAL
)";

// mahnungen, angebote if present (simple)
$create['mahnungen'] = "CREATE TABLE IF NOT EXISTS mahnungen (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    rechnung_id INTEGER,
    stufe INTEGER,
    datum TEXT
)";
$create['angebote'] = "CREATE TABLE IF NOT EXISTS angebote (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id INTEGER,
    nummer TEXT,
    datum TEXT,
    betrag REAL
)";

// New tables for Lieferscheine
$create['lieferscheine'] = "CREATE TABLE IF NOT EXISTS lieferscheine (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nummer TEXT,
    client_id INTEGER,
    datum TEXT,
    bemerkung TEXT
)";
$create['lieferschein_positionen'] = "CREATE TABLE IF NOT EXISTS lieferschein_positionen (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    lieferschein_id INTEGER,
    produkt_id INTEGER,
    menge REAL,
    einzelpreis REAL,
    gesamt REAL
)";

// Create tables
foreach ($create as $k => $sql) {
    $pdo->exec($sql);
    echo "Tabelle gesichert/erstellt: $k\n";
}

// Simple CSV import function
function importCsv($pdo, $csvFile, $table, $mapFn = null) {
    if (!file_exists($csvFile)) {
        echo "CSV nicht gefunden: $csvFile\n";
        return;
    }
    $f = fopen($csvFile, 'r');
    if ($f === false) return;
    $hdr = fgetcsv($f, 0, ",");
    if ($hdr === false) { fclose($f); return; }

    // prepare insert (we will map via $mapFn to associative)
    $rows = 0;
    while (($row = fgetcsv($f, 0, ",")) !== false) {
        $assoc = [];
        foreach ($hdr as $i => $col) $assoc[trim($col)] = isset($row[$i]) ? $row[$i] : null;
        if ($mapFn) $assoc = $mapFn($assoc);
        $cols = array_keys($assoc);
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $colList = implode(',', $cols);
        $stmt = $pdo->prepare("INSERT INTO {$table} ({$colList}) VALUES ({$placeholders})");
        $stmt->execute(array_values($assoc));
        $rows++;
    }
    fclose($f);
    echo "Imported $rows rows into $table from $csvFile\n";
}

// Map functions for CSV column mismatches
$maps = [];

// clients.csv
$maps['clients.csv'] = function($r){
    return [
        'name' => $r['name'] ?? ($r['firma'] ?? ($r['client'] ?? '')),
        'adresse' => $r['adresse'] ?? ($r['street'] ?? ''),
        'plz' => $r['plz'] ?? ($r['zip'] ?? ''),
        'ort' => $r['ort'] ?? ($r['city'] ?? ''),
        'email' => $r['email'] ?? '',
        'telefon' => $r['telefon'] ?? ($r['phone'] ?? ''),
        'uid' => $r['uid'] ?? ''
    ];
};

// produkte.csv
$maps['produkte.csv'] = function($r){
    return [
        'name' => $r['name'] ?? ($r['produkt'] ?? ''),
        'beschreibung' => $r['beschreibung'] ?? ($r['desc'] ?? ''),
        'preis' => isset($r['preis']) ? (float)str_replace(',', '.', $r['preis']) : 0.0
    ];
};

// rechnungen.csv (very simple mapping)
$maps['rechnungen.csv'] = function($r){
    return [
        'nummer' => $r['nummer'] ?? ($r['id'] ?? ''),
        'client_id' => isset($r['client_id']) ? (int)$r['client_id'] : null,
        'datum' => $r['datum'] ?? $r['date'] ?? null,
        'faelligkeit' => $r['faelligkeit'] ?? null,
        'betrag' => isset($r['betrag']) ? (float)str_replace(',', '.', $r['betrag']) : 0.0,
        'status' => $r['status'] ?? ''
    ];
};

// rechnungs_positionen.csv mapping (if exists)
$maps['rechnungs_positionen.csv'] = function($r){
    return [
        'rechnung_id' => isset($r['rechnung_id']) ? (int)$r['rechnung_id'] : null,
        'produkt_id' => isset($r['produkt_id']) ? (int)$r['produkt_id'] : null,
        'menge' => isset($r['menge']) ? (float)str_replace(',', '.', $r['menge']) : 1.0,
        'einzelpreis' => isset($r['einzelpreis']) ? (float)str_replace(',', '.', $r['einzelpreis']) : 0.0,
        'gesamt' => isset($r['gesamt']) ? (float)str_replace(',', '.', $r['gesamt']) : 0.0
    ];
};

// iterate CSV files present in directory
$files = scandir($csvDir);
foreach ($files as $f) {
    $low = strtolower($f);
    $full = $csvDir . '/' . $f;
    if (!is_file($full)) continue;
    if ($low === 'clients.csv') importCsv($pdo, $full, 'clients', $maps['clients.csv']);
    if ($low === 'produkte.csv' || $low === 'produkte.csv') importCsv($pdo, $full, 'produkte', $maps['produkte.csv']);
    if ($low === 'rechnungen.csv') importCsv($pdo, $full, 'rechnungen', $maps['rechnungen.csv']);
    if ($low === 'rechnungs_positionen.csv') importCsv($pdo, $full, 'rechnungs_positionen', $maps['rechnungs_positionen.csv']);
    if ($low === 'angebote.csv') importCsv($pdo, $full, 'angebote', function($r){
        return [
            'client_id' => isset($r['client_id']) ? (int)$r['client_id'] : null,
            'nummer' => $r['nummer'] ?? '',
            'datum' => $r['datum'] ?? null,
            'betrag' => isset($r['betrag']) ? (float)str_replace(',', '.', $r['betrag']) : 0.0
        ];
    });
    if ($low === 'mahnungen.csv') importCsv($pdo, $full, 'mahnungen', function($r){
        return [
            'rechnung_id' => isset($r['rechnung_id']) ? (int)$r['rechnung_id'] : null,
            'stufe' => isset($r['stufe']) ? (int)$r['stufe'] : 0,
            'datum' => $r['datum'] ?? null
        ];
    });
}

echo "Import abgeschlossen.\n";
