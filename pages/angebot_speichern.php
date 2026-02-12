<?php
// pages/angebot_speichern.php
// Angebot speichern und PDF generieren

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

global $pdo;

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/tax.php';

function json_resp($arr, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

$today = date('Y-m-d');
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && str_contains((string)$_SERVER['HTTP_ACCEPT'], 'application/json'));

try {
    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('Keine Datenbankverbindung');
    }

    // Ensure base tables exist (best-effort, minimal columns used here)
    try { $pdo->query('SELECT 1 FROM clients LIMIT 1'); } catch (Throwable $e) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS clients (
                id INT AUTO_INCREMENT PRIMARY KEY,
                kundennummer VARCHAR(64) NULL,
                firma VARCHAR(128) NULL,
                name VARCHAR(128) NULL,
                email VARCHAR(128) NULL,
                telefon VARCHAR(64) NULL,
                adresse VARCHAR(255) NULL,
                plz VARCHAR(16) NULL,
                ort VARCHAR(64) NULL,
                atu VARCHAR(32) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e2) { /* ignore */ }
    }
    try { $pdo->query('SELECT 1 FROM angebote LIMIT 1'); } catch (Throwable $e) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS angebote (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id INT NULL,
                angebotsnummer VARCHAR(64) NULL,
                datum DATE NULL,
                betrag DECIMAL(10,2) DEFAULT 0.00,
                gueltig_bis DATE NULL,
                status VARCHAR(32) NULL,
                pdf_path VARCHAR(255) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e2) { /* ignore */ }
    }

    // Ensure angebot_positionen table exists and supports manual lines (beschreibung, nullable produkt_id)
    try {
        $pdo->query('SELECT 1 FROM angebot_positionen LIMIT 1');
    } catch (Throwable $e) {
        $sql = "CREATE TABLE IF NOT EXISTS angebot_positionen (
            id INT AUTO_INCREMENT PRIMARY KEY,
            angebot_id INT NOT NULL,
            produkt_id INT NULL,
            beschreibung TEXT NULL,
            menge DECIMAL(10,2) NOT NULL DEFAULT 1.00,
            einzelpreis DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            gesamt DECIMAL(10,2) NOT NULL DEFAULT 0.00
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        try { $pdo->exec($sql); } catch (Throwable $e2) { /* ignore and let it fail later if truly missing */ }
    }
    // Best-effort migrations: make produkt_id nullable and add beschreibung column
    try { $pdo->exec('ALTER TABLE angebot_positionen MODIFY produkt_id INT NULL'); } catch (Throwable $e) {}
    try { $pdo->exec('ALTER TABLE angebot_positionen ADD COLUMN beschreibung TEXT NULL'); } catch (Throwable $e) {}

    $date = $_POST['datum'] ?? $today;
    $validUntil = $_POST['gueltig_bis'] ?? date('Y-m-d', strtotime($date . ' +14 days'));
    $status = $_POST['status'] ?? 'offen';

    // Lock for daily sequence (best-effort; continue if not available)
    $lockName = 'angebot_seq_' . $date;
    $got = 0;
    try {
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, 5) AS got');
        $stmt->execute([$lockName]);
        $got = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        // Ignore if function not available (e.g., SQLite or restricted MySQL)
        $got = 0;
    }

    try {
        $pdo->beginTransaction();

        // Determine next sequence for the date
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM angebote WHERE datum = ?');
        $stmt->execute([$date]);
        $count = (int)$stmt->fetchColumn();
        $next = $count + 1;
        $angebotsnummer = 'A-' . date('dm', strtotime($date)) . '-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);

        // Resolve or create client
        if (!empty($_POST['client_id'])) {
            $client_id = (int)$_POST['client_id'];
        } else {
            // Validate minimal client info
            $firma = $_POST['firma'] ?? '';
            $name = $_POST['name'] ?? '';
            if ($firma === '' && $name === '') {
                throw new InvalidArgumentException('Bitte wählen Sie einen Kunden oder geben Sie Kundendaten ein.');
            }
            $kundennummer = 'K' . date('Ymd') . '-' . str_pad((string)rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $stmt = $pdo->prepare('INSERT INTO clients (kundennummer, firma, name, email, telefon, adresse, plz, ort, atu) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $kundennummer,
                $firma !== '' ? $firma : null,
                $name !== '' ? $name : null,
                $_POST['email'] ?? null,
                $_POST['telefon'] ?? null,
                $_POST['adresse'] ?? null,
                $_POST['plz'] ?? null,
                $_POST['ort'] ?? null,
                $_POST['atu'] ?? null,
            ]);
            $client_id = (int)$pdo->lastInsertId();
        }

        // Optional Hinweis/Bemerkung from form
        $hinweis = trim((string)($_POST['hinweis'] ?? ($_POST['bemerkung'] ?? '')));

        // Ensure column exists for hint (best-effort)
        try { $pdo->exec('ALTER TABLE angebote ADD COLUMN hinweis TEXT NULL'); } catch (Throwable $e) {}

        // Insert angebot header
        $stmt = $pdo->prepare('INSERT INTO angebote (client_id, angebotsnummer, datum, betrag, gueltig_bis, status) VALUES (?, ?, ?, 0, ?, ?)');
        $stmt->execute([$client_id, $angebotsnummer, $date, $validUntil, $status]);
        $angebot_id = (int)$pdo->lastInsertId();

        // Persist Hinweis if provided
        if ($hinweis !== '') {
            try {
                $stH = $pdo->prepare('UPDATE angebote SET hinweis = ? WHERE id = ?');
                $stH->execute([$hinweis, $angebot_id]);
            } catch (Throwable $e) {}
        }

        // Positions: allow manual rows with custom description and price
        $productRaw = isset($_POST['product_id']) && is_array($_POST['product_id']) ? $_POST['product_id'] : [];
        $nameArr    = isset($_POST['produkt_name']) && is_array($_POST['produkt_name']) ? $_POST['produkt_name'] : [];
        $descArr    = isset($_POST['beschreibung']) && is_array($_POST['beschreibung']) ? $_POST['beschreibung'] : [];
        $mengeArr   = isset($_POST['menge']) && is_array($_POST['menge']) ? $_POST['menge'] : [];
        $preisArr   = isset($_POST['preis']) && is_array($_POST['preis']) ? $_POST['preis'] : [];

        $rowCount = max(count($productRaw), count($mengeArr), count($preisArr), count($nameArr), count($descArr));
        if ($rowCount === 0) {
            throw new InvalidArgumentException('Keine Positionen ausgewählt.');
        }

        // Collect numeric product IDs to fetch their default prices
        $numericIds = [];
        for ($i = 0; $i < $rowCount; $i++) {
            $val = $productRaw[$i] ?? '';
            if ($val !== '' && $val !== 'manual' && is_numeric($val)) {
                $numericIds[] = (int)$val;
            }
        }
        $productsById = [];
        if (!empty($numericIds)) {
            $in = implode(',', array_fill(0, count($numericIds), '?'));
            $stmt = $pdo->prepare("SELECT id, name, preis FROM produkte WHERE id IN ($in)");
            $stmt->execute($numericIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $productsById[(int)$p['id']] = $p;
            }
        }

        $totalAmount = 0.0;
        $ins = $pdo->prepare('INSERT INTO angebot_positionen (angebot_id, produkt_id, beschreibung, menge, einzelpreis, gesamt) VALUES (?, ?, ?, ?, ?, ?)');
        // Inventory reservation support
        require_once __DIR__ . '/../includes/services/InventoryService.php';
        $inv = new InventoryService($pdo);
        $reservedItems = [];
        for ($i = 0; $i < $rowCount; $i++) {
            $raw = $productRaw[$i] ?? '';
            $pidVal = null;
            $desc = null;
            if ($raw !== '' && $raw !== 'manual' && is_numeric($raw)) {
                $pidVal = (int)$raw;
            } else if ($raw === 'manual' || $raw === '') {
                $prodName = trim((string)($nameArr[$i] ?? ''));
                $descText = trim((string)($descArr[$i] ?? ''));
                if ($prodName !== '' && $descText !== '') {
                    $desc = $prodName . ' — ' . $descText;
                } else if ($prodName !== '') {
                    $desc = $prodName;
                } else if ($descText !== '') {
                    $desc = $descText;
                } else {
                    $desc = 'Manuelle Position';
                }
            }
            $menge = isset($mengeArr[$i]) && $mengeArr[$i] !== '' ? (float)str_replace(',', '.', (string)$mengeArr[$i]) : 1.0;
            $preis = isset($preisArr[$i]) && $preisArr[$i] !== '' ? (float)str_replace(',', '.', (string)$preisArr[$i])
                : ($pidVal !== null && isset($productsById[$pidVal]['preis']) ? (float)$productsById[$pidVal]['preis'] : 0.0);
            $gesamt = round($menge * $preis, 2);
            $totalAmount += $gesamt;
            $ins->execute([$angebot_id, $pidVal, $desc, $menge, $preis, $gesamt]);
            // Create reservation for actual products
            if ($pidVal !== null && $pidVal > 0) {
                $inv->reserveStock($pidVal, $menge, null, 'angebote', $angebot_id, 'Reservierung via Angebot');
                $reservedItems[] = [$pidVal, $menge];
            }
        }
        // If Angebot already accepted, convert reservation to OUT immediately
        if (strtolower((string)$status) === 'angenommen') {
            $inv->convertReservationToOut('angebote', $angebot_id);
        }

        // Persist totals and tax fields (with Reverse Charge when eligible)
        // Determine client tax mode (auto only; offer currently does not present manual override)
        $clientRow = null;
        try {
            $stc = $pdo->prepare('SELECT id, country_code, vat_validation_status FROM clients WHERE id = ?');
            $stc->execute([$client_id]);
            $clientRow = $stc->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) { $clientRow = null; }
        $autoMode = function_exists('tax_decide_mode_for_client') ? tax_decide_mode_for_client($clientRow) : 'standard_vat';
        $vatPerc = ($autoMode === 'eu_reverse_charge') ? 0.0 : 20.0;
        $vatTotal = round($totalAmount * ($vatPerc/100), 2);
        $grossTotal = round($totalAmount + $vatTotal, 2);
        try {
            $st = $pdo->prepare('UPDATE angebote SET net_total = ?, vat_total = ?, gross_total = ?, tax_mode = ?, tax_mode_source = ?, legal_text_key = ?, betrag = ? WHERE id = ?');
            $st->execute([
                number_format($totalAmount, 2, '.', ''),
                number_format($vatTotal, 2, '.', ''),
                number_format($grossTotal, 2, '.', ''),
                $autoMode,
                'auto',
                ($autoMode === 'eu_reverse_charge' ? 'eu_rc_service' : null),
                number_format($totalAmount, 2, '.', ''), // keep legacy betrag as net
                $angebot_id
            ]);
        } catch (Throwable $e) { /* ignore if columns missing */ }

        // Ensure legacy angebote.betrag equals SUM(menge*einzelpreis) from angebot_positionen
        if (function_exists('recalc_angebot_total')) {
            recalc_angebot_total($pdo, $angebot_id);
        }

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        // Release lock after successful transaction (or if no active transaction)
        try { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); } catch (Throwable $e) {}

        // Do not generate PDF here. Return generator endpoint URL so the UI can render fresh layout.
        $pdfUrl = '/pages/angebot_pdf.php?id=' . $angebot_id . '&force=1';
        $pdfWebPath = pdf_web_path('angebot', $angebot_id, '', $date);
        try {
            $stmt = $pdo->prepare('UPDATE angebote SET pdf_path = ? WHERE id = ?');
            $stmt->execute([$pdfWebPath, $angebot_id]);
        } catch (Throwable $e) {}

        if ($isAjax) {
            json_resp(['success' => 1, 'angebot_id' => $angebot_id, 'pdf_url' => $pdfUrl, 'tax_mode' => $autoMode]);
        } else {
            header('Location: ' . $pdfUrl, true, 302);
            exit;
        }

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }

} catch (Throwable $e) {
    // Release lock if held
    if (isset($lockName) && isset($pdo)) {
        try { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); } catch (Throwable $e2) {}
    }

    $isValidation = $e instanceof InvalidArgumentException;

    // Log detailed error only for server-side failures (not for validation)
    if (!$isValidation) {
        try {
            $logDir = __DIR__ . '/../logs';
            if (!is_dir($logDir)) { @mkdir($logDir, 0775, true); }
            $logFile = $logDir . '/angebot_speichern.log';
            @file_put_contents($logFile,
                date('Y-m-d H:i:s') . ' ' . ($e->getMessage()) . "\n" . ($e->getTraceAsString()) . "\n\n",
                FILE_APPEND | LOCK_EX
            );
        } catch (Throwable $e3) { /* ignore logging errors */ }
        @error_log('Angebot speichern Fehler: ' . $e->getMessage());
    }

    $msg = $isValidation ? trim((string)$e->getMessage()) : 'Fehler beim Speichern des Angebots.';
    $code = $isValidation ? 422 : 500;

    if ($isAjax) {
        json_resp(['success' => 0, 'error' => $msg] + ($isValidation ? [] : ['debug' => $e->getMessage()]), $code);
    } else {
        http_response_code($code);
        $class = $isValidation ? 'alert-warning' : 'alert-danger';
        echo '<div class="alert ' . $class . '">' . htmlspecialchars($msg, ENT_QUOTES) . '</div>';
        exit;
    }
}
