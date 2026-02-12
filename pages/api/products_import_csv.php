<?php
// pages/api/products_import_csv.php
// CSV Importer for products (all prices in CSV are NETTO).
// POST multipart/form-data with field 'csv_file'.
// Protect with X-API-TOKEN header matching APP_API_TOKEN env var.
//
// Usage: POST /pages/api/products_import_csv.php (multipart form) with csv_file field
// Response: JSON with imported / updated counts and errors.
//
// Requirements:
// - require '../config/db.php' in project to provide $pdo (PDO instance).
// - 'produkte' table must exist. Optional 'produkt_preise' table will be used if present.
// - Set APP_API_TOKEN in .env if you want protection (then include X-API-TOKEN header).
// - Ensure pages/logs is writable if you want logfile entries (adjust $LOGFILE path if needed).

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');

// ----- CONFIG -----
$MAX_ROWS = 5000;
$EXPECTED_HEADERS = ['sku','name','description','vat','price_1','price_5','price_10','price_25','price_50','price_100','price_200'];
$LOGFILE = __DIR__ . '/../logs/products_import.log'; // optional - ensure directory writable
// ------------------

// simple env helper (fallback)
function env($k, $d = null) {
    $v = getenv($k);
    return $v === false ? $d : $v;
}

// Simple API token check (if APP_API_TOKEN set)
$tokenEnv = env('APP_API_TOKEN', '');
$provided = $_SERVER['HTTP_X_API_TOKEN'] ?? ($_GET['api_token'] ?? '');
if ($tokenEnv !== '' && $provided !== $tokenEnv) {
    http_response_code(401);
    echo json_encode(['success'=>false,'error'=>'Unauthorized']);
    exit;
}

// only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'error'=>'Method not allowed']);
    exit;
}

// file upload validation
if (!isset($_FILES['csv_file']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'CSV file required (field csv_file).']);
    exit;
}
$info = $_FILES['csv_file'];
if ($info['size'] === 0) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'Empty file uploaded.']);
    exit;
}
if ($info['size'] > 20 * 1024 * 1024) { // 20MB limit
    http_response_code(413);
    echo json_encode(['success'=>false,'error'=>'File too large (max 20MB).']);
    exit;
}

// ----- DB init -----
require_once __DIR__ . '/../../config/db.php'; // expects $pdo (adjust path if needed)
if (!isset($pdo) || !$pdo instanceof PDO) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'Database connection not available.']);
    exit;
}

// helper logger
function log_msg($msg) {
    global $LOGFILE;
    $line = '[' . date('c') . '] ' . $msg . PHP_EOL;
    @file_put_contents($LOGFILE, $line, FILE_APPEND | LOCK_EX);
}

// check table existence
function table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM {$table} LIMIT 1");
        $stmt->execute();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

if (!table_exists($pdo, 'produkte')) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'Required table "produkte" not found in DB.']);
    exit;
}

// detect if produkt_preise table exists
$has_tier_table = table_exists($pdo, 'produkt_preise');

// open CSV
$fh = fopen($info['tmp_name'], 'r');
if ($fh === false) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'Failed to open uploaded file.']);
    exit;
}

// detect delimiter from first line and read header (explicit escape to avoid deprecation)
$CSV_ENC = '"';
$CSV_ESC = '\\';
$delims = [',',';','\t','|'];
$firstLine = fgets($fh);
if ($firstLine === false) {
    fclose($fh);
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'CSV file is empty.']);
    exit;
}
$bestDelim = ',';
$bestCols = 0;
foreach ($delims as $d) {
    $cols = str_getcsv($firstLine, $d, $CSV_ENC, $CSV_ESC);
    $cnt = is_array($cols) ? count($cols) : 0;
    if ($cnt > $bestCols) { $bestCols = $cnt; $bestDelim = $d; }
}
$rawHeader = str_getcsv($firstLine, $bestDelim, $CSV_ENC, $CSV_ESC);
if ($rawHeader === null || $rawHeader === false) { $rawHeader = []; }
// normalize header values and strip UTF-8 BOM
$header = array_map(function($h){
    $h = (string)$h;
    // strip BOM on first cell if present
    $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
    return strtolower(trim($h));
}, $rawHeader);

// basic header validation: require at least 'name' and one price column (price_1)
if (!in_array('name', $header, true) || !in_array('price_1', $header, true)) {
    fclose($fh);
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'CSV missing required columns. Expected at least name and price_1.', 'expected' => $EXPECTED_HEADERS, 'found' => $header]);
    exit;
}

// map header positions
$pos = array_flip($header);

// helpers
function norm_number($s) {
    $s = trim((string)$s);
    if ($s === '') return null;
    // accept comma as decimal separator and remove spaces
    $s = str_replace([' ', "\xC2\xA0"], '', $s);
    $s = str_replace(',', '.', $s);
    if (!is_numeric($s)) return null;
    return round((float)$s, 2);
}

// prepared statements for upsert into produkte (sku unique preferred)
$selectBySku = $pdo->prepare("SELECT id FROM produkte WHERE sku = :sku LIMIT 1");
$selectByName = $pdo->prepare("SELECT id FROM produkte WHERE name = :name LIMIT 1");
$insertProduct = $pdo->prepare("INSERT INTO produkte (sku, name, beschreibung, preis, vat, created_at) VALUES (:sku, :name, :desc, :preis, :vat, NOW())");
$updateProduct = $pdo->prepare("UPDATE produkte SET sku = :sku, name = :name, beschreibung = :desc, preis = :preis, vat = :vat, updated_at = NOW() WHERE id = :id");

// produkt_preise statements (if exists)
if ($has_tier_table) {
    $selectTier = $pdo->prepare("SELECT id FROM produkt_preise WHERE produkt_id = :pid AND menge = :menge LIMIT 1");
    $insertTier = $pdo->prepare("INSERT INTO produkt_preise (produkt_id, menge, preis_netto, created_at) VALUES (:pid, :menge, :preis, NOW())");
    $updateTier = $pdo->prepare("UPDATE produkt_preise SET preis_netto = :preis, updated_at = NOW() WHERE id = :id");
}

// process rows
$line = 1;
$imported = 0;
$updated = 0;
$errors = [];
while (($row = fgetcsv($fh, 0, $bestDelim, $CSV_ENC, $CSV_ESC)) !== false) {
    $line++;
    if ($line > $MAX_ROWS) {
        $errors[] = "Max rows limit reached at line {$line}.";
        break;
    }
    // skip empty rows
    $allEmpty = true;
    foreach ($row as $c) { if (trim((string)$c) !== '') { $allEmpty = false; break; } }
    if ($allEmpty) continue;

    // build associative row
    $r = [];
    foreach ($pos as $col => $idx) {
        $r[$col] = isset($row[$idx]) ? $row[$idx] : '';
    }

    // mandatory: name
    $name = trim((string)($r['name'] ?? ''));
    if ($name === '') {
        $errors[] = "Line {$line}: missing product name.";
        continue;
    }
    $sku = trim((string)($r['sku'] ?? ''));
    $desc = trim((string)($r['description'] ?? ''));
    $vat = norm_number($r['vat'] ?? '');
    if ($vat === null) $vat = (float)env('DEFAULT_VAT', 20.0);

    // parse prices (ALL NETTO as requested)
    $tiers = [
        1 => norm_number($r['price_1'] ?? ''),
        5 => norm_number($r['price_5'] ?? ''),
        10 => norm_number($r['price_10'] ?? ''),
        25 => norm_number($r['price_25'] ?? ''),
        50 => norm_number($r['price_50'] ?? ''),
        100 => norm_number($r['price_100'] ?? ''),
        200 => norm_number($r['price_200'] ?? ''),
    ];

    // determine base price: prefer price_1, else first non-null tier
    $basePrice = $tiers[1];
    if ($basePrice === null) {
        foreach ($tiers as $q => $p) { if ($p !== null) { $basePrice = $p; break; } }
    }
    if ($basePrice === null) {
        $errors[] = "Line {$line}: no price provided for product '{$name}'.";
        continue;
    }

    // upsert product
    $productId = null;
    try {
        if ($sku !== '') {
            $selectBySku->execute([':sku' => $sku]);
            $rowId = $selectBySku->fetchColumn();
            if ($rowId) $productId = (int)$rowId;
        }
        if ($productId === null) {
            $selectByName->execute([':name' => $name]);
            $rowId = $selectByName->fetchColumn();
            if ($rowId) $productId = (int)$rowId;
        }

        if ($productId === null) {
            $insertProduct->execute([
                ':sku' => $sku,
                ':name' => $name,
                ':desc' => $desc,
                ':preis' => $basePrice,
                ':vat' => $vat
            ]);
            $productId = (int)$pdo->lastInsertId();
            $imported++;
        } else {
            $updateProduct->execute([
                ':sku' => $sku,
                ':name' => $name,
                ':desc' => $desc,
                ':preis' => $basePrice,
                ':vat' => $vat,
                ':id' => $productId
            ]);
            $updated++;
        }

        // handle tier prices if table exists
        if ($has_tier_table) {
            foreach ($tiers as $qty => $priceNet) {
                if ($priceNet === null) continue;
                // upsert tier row
                $selectTier->execute([':pid' => $productId, ':menge' => $qty]);
                $tierId = $selectTier->fetchColumn();
                if ($tierId) {
                    $updateTier->execute([':preis' => $priceNet, ':id' => $tierId]);
                } else {
                    $insertTier->execute([':pid' => $productId, ':menge' => $qty, ':preis' => $priceNet]);
                }
            }
        }

    } catch (Throwable $e) {
        $errors[] = "Line {$line}: DB error - " . $e->getMessage();
        log_msg("Import error line {$line}: " . $e->getMessage());
    }
}

// close file
fclose($fh);

// result
$result = [
    'success' => count($errors) === 0,
    'imported' => $imported,
    'updated' => $updated,
    'errors_count' => count($errors),
    'errors' => array_slice($errors, 0, 20),
    'tier_table_used' => $has_tier_table
];

echo json_encode($result, JSON_UNESCAPED_UNICODE);
exit;
