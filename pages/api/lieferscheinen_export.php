<?php
// pages/api/lieferscheinen_export.php - CSV export
require_once __DIR__ . '/../../config/db.php';
$table = 'lieferscheinen';
$search = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$sort = trim($_GET['sort'] ?? 'date');
$dir = strtolower(trim($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
try {
    $colsStmt = $pdo->prepare("SHOW COLUMNS FROM `$table`"); $colsStmt->execute(); $cols=$colsStmt->fetchAll(PDO::FETCH_ASSOC); $colNames=array_column($cols,'Field');
} catch(Throwable $e){ http_response_code(500); echo 'Fehler'; exit; }
$where=[]; $params=[];
if($search!==''){ $where[]='(nummer LIKE :q OR kunde LIKE :q OR beschreibung LIKE :q)'; $params[':q']='%'.$search.'%'; }
if($status!=='' && in_array('status',$colNames)){ $where[]='status = :status'; $params[':status']=$status; }
$whereSql = $where? 'WHERE '.implode(' AND ',$where):'';
$allowedSort = []; foreach($colNames as $c) $allowedSort[$c]=$c;
$sortSql = $allowedSort[$sort] ?? (in_array('created_at',$colNames)?'created_at':$colNames[0]);
try {
    $selectCols = implode(', ', array_map(function($c){ return \"`$c`\"; }, $colNames));
    $sql = \"SELECT $selectCols FROM `$table` $whereSql ORDER BY $sortSql $dir\";
    $stmt = $pdo->prepare($sql);
    foreach($params as $k=>$v) $stmt->bindValue($k,$v);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=\"lieferscheinen_export.csv\"');
    $out = fopen('php://output','w');
    fputcsv($out, $colNames);
    foreach($rows as $r){ $line=[]; foreach($colNames as $c) $line[] = isset($r[$c]) ? $r[$c] : ''; fputcsv($out, $line); }
    exit;
} catch(Throwable $e){ http_response_code(500); echo 'Fehler'; exit; }
