<?php
// pages/get_offene_rechnungen.php
header('Content-Type: application/json; charset=utf-8');

$preferredLog = __DIR__ . '/../logs/get_offene_rechnungen.log';
$logFile = (is_dir(dirname($preferredLog)) && is_writable(dirname($preferredLog))) ? $preferredLog : rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'get_offene_rechnungen.log';
function log_debug($m){ global $logFile; @file_put_contents($logFile, date('Y-m-d H:i:s').' '.$m.PHP_EOL, FILE_APPEND|LOCK_EX); }

try {
    // load DB config - expecting either $pdo (PDO) or $dsn+$db_user+$db_pass in config/db.php
    $cfg = __DIR__ . '/../config/db.php';
    $pdo = null;
    if (file_exists($cfg)) {
        require_once $cfg;
        if (isset($pdo) && $pdo instanceof PDO) {
            log_debug("Using \$pdo from config/db.php");
        } elseif (isset($dsn,$db_user,$db_pass)) {
            try {
                $pdo = new PDO($dsn, $db_user, $db_pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
                log_debug("Connected to MySQL via config/db.php");
            } catch (Exception $e) {
                log_debug("MySQL connect failed: ".$e->getMessage());
                $pdo = null;
            }
        } else {
            log_debug("config/db.php loaded but no \$pdo or credentials found.");
        }
    } else {
        log_debug("config/db.php not found");
    }

    // fallback to local sqlite demo if no MySQL available
    if (!($pdo instanceof PDO)) {
        $dataDir = __DIR__ . '/../data';
        @mkdir($dataDir, 0755, true);
        $sqlite = $dataDir . '/demo.db';
        $isNew = !file_exists($sqlite);
        $pdo = new PDO('sqlite:' . $sqlite);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if ($isNew) {
            log_debug("Seeding demo sqlite DB");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS clients (id INTEGER PRIMARY KEY, kundennummer TEXT, name TEXT, email TEXT, firma TEXT);
                CREATE TABLE IF NOT EXISTS rechnungen (id INTEGER PRIMARY KEY, client_id INTEGER, rechnungsnummer TEXT, datum TEXT, faelligkeit TEXT, status TEXT, gesamt NUMERIC, total NUMERIC, mahn_stufe INTEGER DEFAULT 0);
            ");
            $pdo->exec("INSERT INTO clients (kundennummer,name,email,firma) VALUES ('D-1','Demo Kunde','demo@example.test','Demo GmbH')");
            $cid = $pdo->lastInsertId();
            $today = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d');
            $pdo->prepare("INSERT INTO rechnungen (client_id,rechnungsnummer,datum,faelligkeit,status,gesamt,mahn_stufe) VALUES (?,?,?,?,?,?,?)")
                ->execute([$cid,'DEMO-0001',$today,date('Y-m-d', strtotime('+7 days')),'offen',49.9,0]);
        }
    }

    // Query open invoices: status != 'bezahlt' OR mahn_stufe < 3
    $sql = "
      SELECT r.id, r.rechnungsnummer, COALESCE(r.gesamt, r.total) AS gesamt, r.faelligkeit, r.status, r.client_id, r.mahn_stufe,
             c.name AS client_name, c.firma, c.email AS client_email
      FROM rechnungen r
      LEFT JOIN clients c ON c.id = r.client_id
      WHERE (r.status IS NULL OR TRIM(LOWER(r.status)) NOT IN ('bezahlt','paid')) OR (r.mahn_stufe IS NOT NULL AND r.mahn_stufe < 3)
      ORDER BY COALESCE(r.datum, r.faelligkeit, DATE('now')) DESC, r.rechnungsnummer DESC
      LIMIT 1000
    ";
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();

    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id' => $row['id'],
            'rechnungsnummer' => $row['rechnungsnummer'],
            'client_id' => $row['client_id'],
            'client_name' => $row['client_name'],
            'firma' => $row['firma'],
            'client_email' => $row['client_email'] ?? null,
            'gesamt' => is_numeric($row['gesamt']) ? floatval($row['gesamt']) : null,
            'faelligkeit' => $row['faelligkeit'],
            'status' => $row['status'],
            'mahn_stufe' => isset($row['mahn_stufe']) ? intval($row['mahn_stufe']) : null
        ];
    }

    echo json_encode(['success'=>true,'invoices'=>$out], JSON_PRETTY_PRINT);
    exit;

} catch (Exception $e) {
    log_debug("ERROR get_offene_rechnungen: ".$e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'Server error','detail'=>$e->getMessage()], JSON_PRETTY_PRINT);
    exit;
}

