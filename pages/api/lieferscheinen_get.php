<?php
// pages/api/lieferscheinen_get.php - get single
require_once __DIR__ . '/../../config/db.php';
header('Content-Type: application/json; charset=utf-8');
$id = $_GET['id'] ?? null; if(!$id){ http_response_code(400); echo json_encode(['error'=>'missing_id']); exit; }
$table = 'lieferscheinen';
try {
    $colsStmt = $pdo->prepare("SHOW COLUMNS FROM `$table`"); $colsStmt->execute(); $cols = $colsStmt->fetchAll(PDO::FETCH_ASSOC); $colNames = array_column($cols,'Field');
    $selectCols = implode(', ', array_map(function($c){ return "`$c`"; }, $colNames));
    $stmt = $pdo->prepare("SELECT $selectCols FROM `$table` WHERE id = :id LIMIT 1");
    $stmt->execute([':id'=>$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!$row){ http_response_code(404); echo json_encode(['error'=>'not_found']); exit; }
    $row = (array)$row; $row['file_url']=null;
    foreach($colNames as $c){ $lc=strtolower($c); if(in_array($lc, ['file','filename','file_name','file_path','filepath','attachment','pdf','document','file_url','path','url'])){ if(!empty($row[$c])){ $val = $row[$c]; if(preg_match('#^https?://#i',$val)) $row['file_url']=$val; elseif(is_string($val) && strlen($val)>0 && $val[0]==='/') $row['file_url']=$val; else $row['file_url']='/uploads/'.ltrim($val,'/'); break; } } }
    echo json_encode($row, JSON_UNESCAPED_UNICODE);
    exit;
} catch(Throwable $e){ http_response_code(500); echo json_encode(['error'=>'db']); exit; }
