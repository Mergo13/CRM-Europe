<?php
// pages/get_rechnungen_status.php
header('Content-Type: application/json; charset=utf-8');

$logFile = __DIR__ . '/../logs/get_rechnungen_status.log';
function log_debug($m){ global $logFile; @file_put_contents($logFile, date('Y-m-d H:i:s').' '.$m.PHP_EOL, FILE_APPEND|LOCK_EX); }

try {
    // load DB config (expects config/db.php to define $pdo or $dsn+$db_user+$db_pass)
    $cfg = __DIR__ . '/../config/db.php';
    if (!file_exists($cfg)) throw new Exception("DB config missing: {$cfg}");
    require_once $cfg;

    // ensure $pdo
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        if (!isset($dsn,$db_user,$db_pass)) throw new Exception("DB variables missing (dsn/db_user/db_pass)");
        $pdo = new PDO($dsn, $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }

    // SQL: safer for ONLY_FULL_GROUP_BY by using ANY_VALUE for non-aggregated r/c columns
    $sql = "
      SELECT
        r.id,
        r.rechnungsnummer,
        COALESCE(r.gesamt, r.total, r.betrag) AS amount,
        ANY_VALUE(r.datum) AS datum,
        ANY_VALUE(r.faelligkeit) AS faelligkeit,
        ANY_VALUE(r.status) AS status,
        COALESCE(ANY_VALUE(r.mahn_stufe),0) AS mahn_stufe,
        ANY_VALUE(c.id) AS client_id,
        ANY_VALUE(c.name) AS client_name,
        ANY_VALUE(c.firma) AS firma,
        -- aggregation from mahnungen
        MAX(m.created_at) AS last_mahnung_at,
        COUNT(m.id) AS mahn_count
      FROM rechnungen r
      LEFT JOIN clients c ON c.id = r.client_id
      LEFT JOIN mahnungen m ON m.rechnung_id = r.id
      GROUP BY r.id, r.rechnungsnummer
      ORDER BY COALESCE(ANY_VALUE(r.datum), ANY_VALUE(r.faelligkeit), NOW()) DESC, r.rechnungsnummer DESC
      LIMIT 5000
    ";

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => $r['id'],
            'rechnungsnummer' => $r['rechnungsnummer'],
            'client' => (!empty($r['firma']) ? $r['firma'] : $r['client_name']),
            'client_id' => $r['client_id'],
            'amount' => $r['amount'] !== null ? floatval($r['amount']) : null,
            'datum' => $r['datum'],
            'faelligkeit' => $r['faelligkeit'],
            'status' => $r['status'],
            'mahn_stufe' => intval($r['mahn_stufe']),
            'last_mahnung_at' => $r['last_mahnung_at'],
            'mahn_count' => intval($r['mahn_count'])
        ];
    }

    echo json_encode(['success'=>true,'invoices'=>$out], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;

} catch (Exception $e) {
    log_debug("ERROR get_rechnungen_status: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()], JSON_PRETTY_PRINT);
    exit;
}
