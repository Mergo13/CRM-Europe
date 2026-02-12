<?php
// pages/lieferschein_speichern.php
// Modern professional Lieferschein PDF (FPDF) with robust DB checks and UTF-8 fallback.
// Replace existing file with this. Edit $company / $bank to your real data.

error_reporting(E_ALL);
ini_set('display_errors', 1);
global $pdo;
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../vendor/setasign/fpdf/fpdf.php';
require_once __DIR__ . '/../includes/pdf_branding.php';

/* ==========================
   Config: edit company + bank
   ========================== */
$company = [
    'name'     => 'Vision L&T GmbH',
    'address'  => 'Musterstraße 12',
    'zip_city' => '12345 Berlin',
    'phone'    => '+49 (0)30 1234 5678',
    'email'    => 'info@vision-lt.de',
    'website'  => 'www.vision-lt.de',
    'logo'     => __DIR__ . '/../assets/logo.jpg' // set path to your logo file
];

$bank = [
    'bank_name'   => 'Musterbank AG',
    'iban'        => 'DE12 3456 7890 1234 5678 90',
    'bic'         => 'MUSTDEFFXXX',
    'account_holder' => 'Vision L&T GmbH',
    'tax_id'      => 'USt-IdNr: DE123456789'
];
/* ========================== */

/* ---------------------------
   Helpers
----------------------------*/
function ensureColumnExists(PDO $pdo, string $table, string $column, string $columnDef) {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $cols = $pdo->query("PRAGMA table_info(" . $table . ")")->fetchAll(PDO::FETCH_ASSOC);
        $exists = false;
        foreach ($cols as $c) if (strcasecmp($c['name'], $column) === 0) { $exists = true; break; }
        if (!$exists) $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$columnDef};");
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['cnt'] === 0) $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$columnDef};");
    }
}

/* ---------------------------
   Ensure tables + columns exist
----------------------------*/
try {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS lieferscheine (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nummer TEXT,
                client_id INTEGER,
                datum TEXT,
                bemerkung TEXT
            );
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS lieferschein_positionen (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                lieferschein_id INTEGER,
                produkt_id INTEGER,
                menge REAL,
                einzelpreis REAL,
                gesamt REAL
            );
        ");
    } else {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS lieferscheine (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nummer VARCHAR(64),
                client_id INT,
                datum DATE,
                bemerkung TEXT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS lieferschein_positionen (
                id INT AUTO_INCREMENT PRIMARY KEY,
                lieferschein_id INT,
                produkt_id INT,
                menge DOUBLE,
                einzelpreis DOUBLE,
                gesamt DOUBLE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    ensureColumnExists($pdo, 'lieferscheine', 'kundennummer', ($driver === 'sqlite' ? 'TEXT' : 'VARCHAR(64) NULL'));
    // Ensure status column and index for workflow state
    ensureColumnExists($pdo, 'lieferscheine', 'status', ($driver === 'sqlite' ? 'TEXT DEFAULT "offen"' : "VARCHAR(32) NULL DEFAULT 'offen'"));
    // New header fields used by this app
    ensureColumnExists($pdo, 'lieferscheine', 'lieferdatum', ($driver === 'sqlite' ? 'TEXT' : 'DATE NULL'));
    ensureColumnExists($pdo, 'lieferscheine', 'erstellungsdatum', ($driver === 'sqlite' ? 'TEXT' : 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP'));
    ensureColumnExists($pdo, 'lieferscheine', 'lieferadresse_id', ($driver === 'sqlite' ? 'INTEGER' : 'INT NULL'));
    ensureColumnExists($pdo, 'lieferscheine', 'bestellnummer', ($driver === 'sqlite' ? 'TEXT' : 'VARCHAR(128) NULL'));

    try {
        if ($driver !== 'sqlite') {
            $stmt = $pdo->prepare("SELECT COUNT(1) AS cnt FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='lieferscheine' AND INDEX_NAME='idx_lieferscheine_status'");
            $stmt->execute();
            $exists = (int)($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0) > 0;
            if (!$exists) { $pdo->exec("CREATE INDEX idx_lieferscheine_status ON lieferscheine(status)"); }
        }
    } catch (Throwable $e2) { /* ignore */ }

    // Drop obsolete price columns from lieferschein_positionen if they exist
    try { $pdo->exec("ALTER TABLE lieferschein_positionen DROP COLUMN einzelpreis"); } catch (Throwable $e) { /* ignore */ }
    try { $pdo->exec("ALTER TABLE lieferschein_positionen DROP COLUMN gesamt"); } catch (Throwable $e) { /* ignore */ }

    // ensure clients table exists before adding columns
    $clientsExists = false;
    if ($driver === 'sqlite') {
        $tbls = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='clients'")->fetchAll(PDO::FETCH_ASSOC);
        $clientsExists = (count($tbls) > 0);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients'");
        $stmt->execute();
        $clientsExists = ((int)$stmt->fetch(PDO::FETCH_ASSOC)['cnt'] > 0);
    }
    if ($clientsExists) {
        ensureColumnExists($pdo, 'clients', 'kundennummer', ($driver === 'sqlite' ? 'TEXT' : 'VARCHAR(64) NULL'));
        ensureColumnExists($pdo, 'clients', 'firmenname', ($driver === 'sqlite' ? 'TEXT' : 'VARCHAR(128) NULL'));
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo "DB Setup Error: " . htmlspecialchars($e->getMessage());
    exit;
}

/* ---------------------------
   Validate input
----------------------------*/
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Method not allowed";
    exit;
}

$client_id = isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
$client_name = trim($_POST['client_name'] ?? '');
$kundennummer_override = trim($_POST['kundennummer'] ?? '');
$datum = !empty($_POST['datum']) ? $_POST['datum'] : date('Y-m-d');
$bemerkung = isset($_POST['bemerkung']) ? trim($_POST['bemerkung']) : '';
$lieferdatum = !empty($_POST['lieferdatum']) ? $_POST['lieferdatum'] : $datum;
$bestellnummer = isset($_POST['bestellnummer']) ? trim($_POST['bestellnummer']) : null;
$lieferadresse_id = isset($_POST['lieferadresse_id']) && $_POST['lieferadresse_id'] !== '' ? (int)$_POST['lieferadresse_id'] : null;

if ($client_id <= 0 && $client_name === '') {
    http_response_code(400);
    echo "Kein Kunde ausgewählt oder eingegeben.";
    exit;
}

/* ---------------------------
   Resolve or create client
----------------------------*/
try {
    $client = null;
    if ($client_id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? LIMIT 1");
        $stmt->execute([$client_id]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        $kundennummer = $kundennummer_override ?: ($client['kundennummer'] ?? null);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $stmt->execute([$client_name]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$client) {
            $stmt = $pdo->prepare("SELECT * FROM clients WHERE name LIKE ? LIMIT 1");
            $stmt->execute(['%'.$client_name.'%']);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if ($client) {
            $client_id = (int)$client['id'];
            $kundennummer = $kundennummer_override ?: ($client['kundennummer'] ?? null);
        } else {
            $kundennummer = $kundennummer_override ?: ('K' . str_pad((string)time()%100000, 5, '0', STR_PAD_LEFT));
            $ins = $pdo->prepare("INSERT INTO clients (name, kundennummer) VALUES (?, ?)");
            $ins->execute([$client_name, $kundennummer]);
            $client_id = $pdo->lastInsertId();
            $stmt2 = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
            $stmt2->execute([$client_id]);
            $client = $stmt2->fetch(PDO::FETCH_ASSOC);
        }
    }
} catch (Exception $e) {
    http_response_code(500);
    echo "Kunde Lookup/Create Fehler: " . htmlspecialchars($e->getMessage());
    exit;
}

/* ---------------------------
   Save Lieferschein + positions
----------------------------*/
try {
    $pdo->beginTransaction();

    $nummer = 'LS-' . time();

    $stmt = $pdo->prepare("INSERT INTO lieferscheine (nummer, client_id, kundennummer, datum, bemerkung, status, lieferdatum, bestellnummer, lieferadresse_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$nummer, $client_id, $kundennummer, $datum, $bemerkung, 'offen', $lieferdatum, $bestellnummer, $lieferadresse_id]);
    $lieferschein_id = $pdo->lastInsertId();

    $productIds = $_POST['product_id'] ?? [];
    $mengeArr = $_POST['menge'] ?? [];
    $insertPos = $pdo->prepare("INSERT INTO lieferschein_positionen (lieferschein_id, produkt_id, menge) VALUES (?, ?, ?)");

    for ($i = 0; $i < count($productIds); $i++) {
        $pid = (int)$productIds[$i];
        if ($pid <= 0) continue;
        $menge = (float)str_replace(',', '.', $mengeArr[$i] ?? 1.0);
        $insertPos->execute([$lieferschein_id, $pid, $menge]);
    }

    // Inventory movements: OUT per product
    require_once __DIR__ . '/../includes/services/InventoryService.php';
    $inv = new InventoryService($pdo);
    for ($i = 0; $i < count($productIds); $i++) {
        $pid = (int)$productIds[$i];
        if ($pid <= 0) continue;
        $menge = (float)str_replace(',', '.', $mengeArr[$i] ?? 1.0);
        $inv->addMovement($pid, $menge, InventoryService::TYPE_OUT, null, 'lieferscheine', (int)$lieferschein_id, 'Ausgang via Lieferschein');
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo "Konnte Lieferschein nicht speichern: " . htmlspecialchars($e->getMessage());
    exit;
}

/* ---------------------------
   After save: return generator URL (single source of truth)
----------------------------*/
// Always defer PDF creation to /pages/lieferschein_pdf.php
try {
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && strpos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
    $pdfUrl = '/pages/lieferschein_pdf.php?id=' . $lieferschein_id . '&force=1';
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'lieferschein_id' => $lieferschein_id, 'pdf_url' => $pdfUrl]);
        exit;
    } else {
        header('Location: ' . $pdfUrl, true, 302);
        exit;
    }
} catch (Throwable $e) {
    // fall through to legacy behavior if something above fails
}

/* ---------------------------
   Fetch positions and output PDF
----------------------------*/
try {
    $posStmt = $pdo->prepare("SELECT p.name, lp.menge, lp.einzelpreis, lp.gesamt FROM lieferschein_positionen lp LEFT JOIN produkte p ON p.id = lp.produkt_id WHERE lp.lieferschein_id = ? ORDER BY lp.id ASC");
    $posStmt->execute([$lieferschein_id]);
    $positions = $posStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500);
    echo "Fehler beim Lesen der Positionen: " . htmlspecialchars($e->getMessage());
    exit;
}

/* ---------------------------
   Generate PDF (modern styling) - center + padded boxes
   Includes helper to draw a box and center content with padding
----------------------------*/
try {
    $pdf = new FPDF('P','mm','A4');
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(false);

    // Use bundled DejaVuSans font descriptor at project root for UTF-8 support
    if (!defined('FPDF_FONTPATH')) {
        define('FPDF_FONTPATH', __DIR__ . '/../'); // DejaVuSans.php and .z are stored at project root
    }
    $usingDejaVu = true;
    // Register regular and bold using the same descriptor to avoid undefined bold errors
    $pdf->AddFont('DejaVu', '', 'DejaVuSans.php');
    $pdf->AddFont('DejaVu', 'B', 'DejaVuSans.php');

    $out = function($str) use ($usingDejaVu) {
        if ($usingDejaVu) return $str;
        return iconv('UTF-8','ISO-8859-1//TRANSLIT', $str);
    };

    // Helper: draw a rectangle box and place lines of text inside it with padding and vertical centering.
    // - $lines: array of ['font'=>['family','style',size], 'text'=>string, 'align'=>'L'|'C'|'R']
    function drawBoxWithContent(FPDF $pdf, float $x, float $y, float $w, float $h, array $lines, int $pad = 4, bool $fill = false, array $boxDrawColor = [220,220,220], array $boxFillColor = [246,246,247]) {
        // draw background if fill
        $pdf->SetDrawColor($boxDrawColor[0], $boxDrawColor[1], $boxDrawColor[2]);
        if ($fill) {
            $pdf->SetFillColor($boxFillColor[0], $boxFillColor[1], $boxFillColor[2]);
            $pdf->Rect($x, $y, $w, $h, 'F');
        } else {
            $pdf->Rect($x, $y, $w, $h);
        }

        // compute text block height
        $lineHeights = [];
        $totalTextH = 0;
        foreach ($lines as $ln) {
            [$family, $style, $size] = $ln['font'];
            $pdf->SetFont($family, $style, $size);
            // approximate line height = size * 0.35 mm per point (FPDF point to mm ~ 0.3528)
            $lh = $size * 0.3528 * 1.05; // small multiplier for leading
            $lineHeights[] = $lh;
            $totalTextH += $lh;
        }

        // available inner height
        $innerH = $h - 2 * $pad;
        $startY = $y + $pad;
        if ($totalTextH < $innerH) {
            // center vertically
            $startY = $y + $pad + (($innerH - $totalTextH) / 2);
        }

        // render each line horizontally according to align and with padding
        $curY = $startY;
        foreach ($lines as $idx => $ln) {
            [$family, $style, $size] = $ln['font'];
            $text = $ln['text'];
            $align = $ln['align'] ?? 'L';
            $pdf->SetFont($family, $style, $size);
            $pdf->SetXY($x + $pad, $curY);
            $cellW = $w - 2 * $pad;
            // Choose alignment
            $alignChar = in_array($align, ['L','C','R']) ? $align : 'L';
            // Use MultiCell for safety if text might wrap; but constrain height to a single "line" for alignment
            // We'll write with MultiCell but ensure it doesn't exceed the precomputed single line height here.
            $pdf->MultiCell($cellW, $lineHeights[$idx], $text, 0, $alignChar);
            $curY += $lineHeights[$idx];
        }
    }

    // Small nbLines helper (same as before)
    $nbLines = function($pdf, float $w, string $txt) {
        $cellPadding = 2;
        $effectiveWidth = max(1, $w - 2 * $cellPadding);
        $spaceWidth = $pdf->GetStringWidth(' ');
        $words = preg_split('/\s+/', str_replace("\r", '', trim($txt)));
        if (!$words) return 1;
        $lines = 1;
        $currentWidth = 0.0;
        foreach ($words as $word) {
            $wordWidth = $pdf->GetStringWidth($word);
            if ($wordWidth > $effectiveWidth) {
                if ($currentWidth > 0) { $lines++; $currentWidth = 0; }
                $partWidth = 0.0;
                $chars = preg_split('//u', $word, null, PREG_SPLIT_NO_EMPTY);
                foreach ($chars as $ch) {
                    $cw = $pdf->GetStringWidth($ch);
                    if ($partWidth + $cw > $effectiveWidth) {
                        $lines++;
                        $partWidth = $cw;
                    } else {
                        $partWidth += $cw;
                    }
                }
                $currentWidth = $partWidth;
            } else {
                if ($currentWidth == 0) {
                    $currentWidth = $wordWidth;
                } else {
                    if ($currentWidth + $spaceWidth + $wordWidth <= $effectiveWidth) {
                        $currentWidth += $spaceWidth + $wordWidth;
                    } else {
                        $lines++;
                        $currentWidth = $wordWidth;
                    }
                }
            }
        }
        return max(1, (int)$lines);
    };

    // design tokens
    $brandColor = [14, 85, 167];
    $mutedBg = [246,246,247];
    $tableHeaderBg = [240,240,240];

    // Header: use shared firmendaten from settings (settings_company)
    $margin = 15;
    pdf_branding_header($pdf, $pdo, ['using_dejavu' => $usingDejaVu, 'font_regular' => ($usingDejaVu ? 'DejaVu' : 'Arial')]);
    // Establish topY after header for following layout
    $topY = $pdf->GetY();

    // Document meta box (use helper to pad & center)
    $metaX = 210 - $margin - 70;
    $metaY = $topY + 30;
    $metaW = 70;
    $metaH = 28;
    // prepare lines for meta box
    $metaLines = [
        ['font' => [$usingDejaVu ? 'DejaVu' : 'Arial', 'B', 11], 'text' => $out('LIEFERSCHEIN'), 'align' => 'C'],
        ['font' => [$usingDejaVu ? 'DejaVu' : 'Arial', '', 9], 'text' => $out('Nummer: ' . $nummer), 'align' => 'C'],
        ['font' => [$usingDejaVu ? 'DejaVu' : 'Arial', '', 9], 'text' => $out('Datum: ' . $datum), 'align' => 'C'],
    ];
    drawBoxWithContent($pdf, $metaX, $metaY, $metaW, $metaH, $metaLines, 6, true, [14,85,167], [14,85,167]);
    // after drawing meta box, reset text color
    $pdf->SetTextColor(0,0,0);

    $pdf->Ln(12);

    // Client block - improved: padded box with centered vertical alignment
    $clientX = $margin;
    $clientW = 130;
    $clientH = 34; // increased to allow center/padding
    // build client text lines (use firmenname big + others)
    $clientLines = [];
    if (!empty($client['firmenname'])) {
        $clientLines[] = ['font' => [$usingDejaVu ? 'DejaVu' : 'Arial', 'B', 12], 'text' => $out($client['firmenname']), 'align' => 'L'];
        if (!empty($client['name'])) $clientLines[] = ['font' => [$usingDejaVu ? 'DejaVu' : 'Arial', '', 10], 'text' => $out($client['name']), 'align' => 'L'];
    } else if (!empty($client['name'])) {
        $clientLines[] = ['font' => [$usingDejaVu ? 'DejaVu' : 'Arial', 'B', 12], 'text' => $out($client['name']), 'align' => 'L'];
    }
    if (!empty($client['adresse'])) $clientLines[] = ['font' => [$usingDejaVu ? 'DejaVu' : 'Arial', '', 10], 'text' => $out($client['adresse']), 'align' => 'L'];
    $loc = trim(($client['plz'] ?? '') . ' ' . ($client['ort'] ?? ''));
    if ($loc) $clientLines[] = ['font' => [$usingDejaVu ? 'DejaVu' : 'Arial', '', 10], 'text' => $out($loc), 'align' => 'L'];
    if (!empty($client['email'])) $clientLines[] = ['font' => [$usingDejaVu ? 'DejaVu' : 'Arial', '', 10], 'text' => $out($client['email']), 'align' => 'L'];
    $clientLines[] = ['font' => [$usingDejaVu ? 'DejaVu' : 'Arial', '', 9], 'text' => $out('Kundennummer: ' . ($kundennummer ?? '')), 'align' => 'L'];

    drawBoxWithContent($pdf, $clientX - 3, $pdf->GetY(), $clientW + 6, $clientH, $clientLines, 6, true, [220,220,220], $mutedBg);
    $pdf->SetY($pdf->GetY() + $clientH + 4);

    // Table header
    $tableX = $margin;
    $pdf->SetX($tableX);
    $w1 = 92; $w2 = 26; $w3 = 36; $w4 = 36;
    $pdf->SetFillColor($tableHeaderBg[0], $tableHeaderBg[1], $tableHeaderBg[2]);
    if ($usingDejaVu) $pdf->SetFont('DejaVu','B',10); else $pdf->SetFont('Arial','B',10);
    $pdf->Cell($w1,9, $out('Bezeichnung'), 1, 0, 'L', true);
    $pdf->Cell($w2,9, $out('Menge'), 1, 0, 'C', true);
    $pdf->Cell($w3,9, $out('Einzelpreis'), 1, 0, 'R', true);
    $pdf->Cell($w4,9, $out('Gesamt'), 1, 1, 'R', true);

    // Table rows: compute exact row height per-row (so wrapped descriptions won't misalign numeric cells)
    if ($usingDejaVu) $pdf->SetFont('DejaVu','',10); else $pdf->SetFont('Arial','',10);

    $pageHeight = 297;
    $bottomSafety = 48;
    $currentY = $pdf->GetY();
    $availableHeight = $pageHeight - $currentY - $bottomSafety - 10;

    // line height used for wrapped text (mm)
    $lineHeight = 4.2;
    // minimum row height (single line)
    $minRowHeight = $lineHeight;

    $numRows = count($positions);
    // Pre-calc lines per row for description column (this is the one that wraps)
    $linesPerRow = [];
    foreach ($positions as $pos) {
        $name = (string)($pos['name'] ?? 'Produkt');
        $lines = $nbLines($pdf, $w1, $name);
        $linesPerRow[] = max(1, $lines);
    }

    // compute heights and total required height
    $rowHeights = [];
    $totalRequired = 0;
    foreach ($linesPerRow as $ln) {
        $h = max($minRowHeight, $ln * $lineHeight);
        $rowHeights[] = $h;
        $totalRequired += $h;
    }

    // determine how many rows fit; if not all, we'll truncate and add a notice
    $fitRows = 0;
    $acc = 0;
    for ($i = 0; $i < count($rowHeights); $i++) {
        if ($acc + $rowHeights[$i] > $availableHeight) break;
        $acc += $rowHeights[$i];
        $fitRows++;
    }
    $truncated = ($fitRows < $numRows);

    // render each fitted row with aligned cells: draw cell borders, vertically center text inside
    $pdf->SetFont($usingDejaVu ? 'DejaVu' : 'Arial', '', ($lineHeight <= 4 ? 8 : 10));
    $rowFill = false;
    for ($i = 0; $i < $fitRows; $i++) {
        $pos = $positions[$i];
        $name = (string)($pos['name'] ?? 'Produkt');
        $menge = (string)$pos['menge'];
        $einzel = number_format((float)$pos['einzelpreis'],2,',','.');
        $gesamtStr = number_format((float)$pos['gesamt'],2,',','.');

        $h = $rowHeights[$i];
        $x = $tableX;
        $y = $pdf->GetY();

        // alternate shading
        if ($rowFill) {
            $pdf->SetFillColor(250,250,250);
            $fillFlag = true;
        } else {
            $fillFlag = false;
        }

        // draw cell rectangles (borders + background)
        $pdf->SetDrawColor(220,220,220);
        if ($fillFlag) {
            $pdf->Rect($x, $y, $w1, $h, 'F');
            $pdf->Rect($x + $w1, $y, $w2, $h, 'F');
            $pdf->Rect($x + $w1 + $w2, $y, $w3, $h, 'F');
            $pdf->Rect($x + $w1 + $w2 + $w3, $y, $w4, $h, 'F');
        }
        // draw borders
        $pdf->Rect($x, $y, $w1, $h);
        $pdf->Rect($x + $w1, $y, $w2, $h);
        $pdf->Rect($x + $w1 + $w2, $y, $w3, $h);
        $pdf->Rect($x + $w1 + $w2 + $w3, $y, $w4, $h);

        // padding inside cells
        $pad = 2;

        // description: vertically center; write with MultiCell so it wraps
        $pdf->SetXY($x + $pad, $y + max(0, ($h - $linesPerRow[$i] * $lineHeight) / 2));
        $pdf->MultiCell($w1 - 2*$pad, $lineHeight, $out($name), 0, 'L');

        // Menge (center vertically + horizontally)
        $pdf->SetXY($x + $w1 + $pad, $y + ($h/2) - ($lineHeight/2));
        $pdf->Cell($w2 - 2*$pad, $lineHeight, $out($menge), 0, 0, 'C');

        // Einzelpreis (right align, vertically centered)
        $pdf->SetXY($x + $w1 + $w2 + $pad, $y + ($h/2) - ($lineHeight/2));
        $pdf->Cell($w3 - 2*$pad, $lineHeight, $out($einzel), 0, 0, 'R');

        // Gesamt (right align, vertically centered)
        $pdf->SetXY($x + $w1 + $w2 + $w3 + $pad, $y + ($h/2) - ($lineHeight/2));
        $pdf->Cell($w4 - 2*$pad, $lineHeight, $out($gesamtStr), 0, 0, 'R');

        // move to next row Y
        $pdf->SetY($y + $h);
        $rowFill = !$rowFill;
    }

    // if truncated, add small notice
    if ($truncated) {
        $pdf->Ln(2);
        if ($usingDejaVu) $pdf->SetFont('DejaVu','',8); else $pdf->SetFont('Arial','',8);
        $pdf->Cell(0,6, $out('(weitere Positionen ausgelassen)'), 0, 1, 'C');
    }

    // table bottom line
    $pdf->Cell($w1,0,'','T',0);
    $pdf->Cell($w2,0,'','T',0);
    $pdf->Cell($w3,0,'','T',0);
    $pdf->Cell($w4,0,'','T',1);

    // Totals box on right
    $pdf->Ln(6);
    $rightBoxX = 210 - $margin - 80;
    $pdf->SetXY($rightBoxX, $pdf->GetY());
    $pdf->SetFillColor(249,249,249);
    $pdf->SetDrawColor(220,220,220);
    if ($usingDejaVu) $pdf->SetFont('DejaVu','',10); else $pdf->SetFont('Arial','',10);
    $pdf->Cell(80,7, $out('Zwischensumme'), 1, 0, 'L', true);
    $pdf->Cell(-80,7, number_format($total,2,',','.'), 1, 1, 'R', true);
    $pdf->SetXY($rightBoxX, $pdf->GetY());
    if ($usingDejaVu) $pdf->SetFont('DejaVu','B',12); else $pdf->SetFont('Arial','B',12);
    $pdf->Cell(80,10, $out('Gesamt'), 1, 0, 'L', true);
    $pdf->Cell(-80,10, number_format($total,2,',','.'), 1, 1, 'R', true);

    // Bemerkung
    if (!empty($bemerkung)) {
        $pdf->Ln(6);
        if ($usingDejaVu) $pdf->SetFont('DejaVu','',9); else $pdf->SetFont('Arial','',9);
        $pdf->MultiCell(0,6, $out('Bemerkung: ' . $bemerkung));
    }

    /* Footer - shared firmendaten */
    pdf_branding_footer($pdf, $pdo, ['using_dejavu' => $usingDejaVu, 'font_regular' => ($usingDejaVu ? 'DejaVu' : 'Arial')]);

    // signature line
    $pdf->SetY(-32);
    $pdf->SetX(125);
    $pdf->Cell(70,5, '__________________________', 0, 1, 'C');
    $pdf->SetX(125);
    $pdf->Cell(70,5, $out('Unterschrift / Stempel'), 0, 1, 'C');

    // Output
    $filename = 'lieferschein_' . $nummer . '.pdf';
    $pdf->Output('I', $filename);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo "PDF-Generierung fehlgeschlagen: " . htmlspecialchars($e->getMessage());
    exit;
}
