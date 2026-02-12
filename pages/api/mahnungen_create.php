<?php
// pages/api/mahnungen_create.php - delegate Mahnung creation to pages/mahnung_speichern.php when possible
require_once __DIR__ . '/../../config/db.php';
header('Content-Type: application/json; charset=utf-8');

// Gather input from JSON or form
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) { $payload = $_POST; }

$rechnungsnummer = isset($payload['rechnungsnummer']) ? trim((string)$payload['rechnungsnummer']) : '';
$invoiceId = isset($payload['invoice_id']) ? (int)$payload['invoice_id'] : 0;
$stufe = isset($payload['stufe']) ? (int)$payload['stufe'] : 0;
$dueDays = isset($payload['due_days']) ? (int)$payload['due_days'] : 7;
$sendNow = !empty($payload['send_now']) ? '1' : '0';
$note = isset($payload['note']) ? trim((string)$payload['note']) : '';

try {
    // Resolve rechnungsnummer from invoice id if needed
    if ($rechnungsnummer === '' && $invoiceId > 0) {
        $st = $pdo->prepare('SELECT rechnungsnummer FROM rechnungen WHERE id = ? LIMIT 1');
        $st->execute([$invoiceId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['rechnungsnummer'])) {
            $rechnungsnummer = (string)$row['rechnungsnummer'];
        }
    }

    if ($rechnungsnummer !== '') {
        // Delegate to the canonical generator
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = $host ? ($scheme . '://' . $host) : '';
        $endpoint = $baseUrl ? ($baseUrl . '/pages/mahnung_speichern.php') : null;

        $postData = http_build_query([
            'rechnungsnummer' => $rechnungsnummer,
            'stufe' => $stufe,
            'due_days' => $dueDays,
            'send_now' => $sendNow,
            'note' => $note,
        ]);

        $response = false;
        if ($endpoint) {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => $postData,
                    'timeout' => 30
                ]
            ]);
            $response = @file_get_contents($endpoint, false, $ctx);
        }

        if ($response === false) {
            // Fallback to CLI runner
            $cmd = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../scripts/run_mahnung_save.php') . ' ' . escapeshellarg($rechnungsnummer) . ' ' . (int)$stufe . ' ' . (int)$dueDays;
            @exec($cmd, $outLines, $code);
            if ($code === 0) {
                $response = implode("\n", (array)$outLines);
            }
        }

        if ($response === false) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'mahnung_save_unreachable']);
            exit;
        }

        $decoded = json_decode($response, true);
        if (is_array($decoded)) {
            echo json_encode($decoded);
        } else {
            echo json_encode(['success' => true, 'raw' => $response]);
        }
        exit;
    }

    // Fallback to legacy lightweight insert if no invoice context provided
    $table = 'mahnungen';
    $allowed = ['nummer','rechnungsnummer','kunde','client_id','betrag','total','beschreibung','datum','date','created_at','status','valid_until','level','empfaenger','items_count'];

    $colsStmt = $pdo->prepare("SHOW COLUMNS FROM `$table`");
    $colsStmt->execute();
    $cols = $colsStmt->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols,'Field');

    $toInsert = []; $params = [];
    foreach($allowed as $f){ if(in_array($f,$colNames) && isset($_POST[$f])){ $toInsert[] = "`$f`"; $params[] = $_POST[$f]; } }
    if(empty($toInsert)){
        if(in_array('nummer',$colNames)){ $toInsert[]='`nummer`'; $params[]='EX-'.time(); }
        if(in_array('kunde',$colNames)){ $toInsert[]='`kunde`'; $params[]='Demo Kunde'; }
        if(in_array('betrag',$colNames)){ $toInsert[]='`betrag`'; $params[]='0.00'; }
        if(in_array('status',$colNames)){ $toInsert[]='`status`'; $params[]='open'; }
        if(in_array('created_at',$colNames)){ $toInsert[]='`created_at`'; $params[]=date('Y-m-d H:i:s'); }
    }
    if(empty($toInsert)){
        http_response_code(400); echo json_encode(['success'=>false,'error'=>'no_insertable_columns']); exit;
    }
    $placeholders = implode(',', array_fill(0,count($toInsert),'?'));
    $colsSql = implode(',', $toInsert);
    $sql = "INSERT INTO `$table` ($colsSql) VALUES ($placeholders)";
    $stmt=$pdo->prepare($sql);
    $stmt->execute($params);
    $id=(int)$pdo->lastInsertId();
    echo json_encode(['success'=>true,'id'=>$id]);
    exit;
} catch(Throwable $e){
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'db','message'=>$e->getMessage()]);
    exit;
}
