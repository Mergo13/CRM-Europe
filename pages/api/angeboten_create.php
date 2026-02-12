<?php
// pages/api/angeboten_create.php - lightweight create for convenience (uses posted fields or defaults)
require_once __DIR__ . '/../../config/db.php';
header('Content-Type: application/json; charset=utf-8');
$table = 'angeboten';
$allowed = ['nummer','rechnungsnummer','kunde','client_id','betrag','total','beschreibung','datum','date','created_at','status','valid_until','level','empfaenger','items_count'];
try {
    $colsStmt = $pdo->prepare("SHOW COLUMNS FROM `$table`"); $colsStmt->execute(); $cols = $colsStmt->fetchAll(PDO::FETCH_ASSOC); $colNames = array_column($cols,'Field');
    $toInsert = []; $params = [];
    foreach($allowed as $f){ if(in_array($f,$colNames) && isset($_POST[$f])){ $toInsert[] = "`$f`"; $params[] = $_POST[$f]; } }
    if(empty($toInsert)){ if(in_array('nummer',$colNames)){ $toInsert[]='`nummer`'; $params[]='EX-'.time(); } if(in_array('kunde',$colNames)){ $toInsert[]='`kunde`'; $params[]='Demo Kunde'; } if(in_array('betrag',$colNames)){ $toInsert[]='`betrag`'; $params[]='0.00'; } if(in_array('status',$colNames)){ $toInsert[]='`status`'; $params[]='open'; } if(in_array('created_at',$colNames)){ $toInsert[]='`created_at`'; $params[]=date('Y-m-d H:i:s'); } }
    if(empty($toInsert)){ http_response_code(400); echo json_encode(['success'=>false,'error'=>'no_insertable_columns']); exit; }
    $placeholders = implode(',', array_fill(0,count($toInsert),'?')); $colsSql = implode(',', $toInsert);
    $sql = "INSERT INTO `$table` ($colsSql) VALUES ($placeholders)"; $stmt=$pdo->prepare($sql); $stmt->execute($params); $id=(int)$pdo->lastInsertId();
    echo json_encode(['success'=>true,'id'=>$id]); exit;
} catch(Throwable $e){ http_response_code(500); echo json_encode(['success'=>false,'error'=>'db','message'=>$e->getMessage()]); exit; }
