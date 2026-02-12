<?php
// pages/api/lieferscheinen_list.php - dynamic list (introspects columns)
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/init.php';
header('Content-Type: application/json; charset=utf-8');

function json_error(string $error, string $message = '', int $status = 500): void {
    http_response_code($status);
    echo json_encode([
        'success' => false,
        'error' => $error,
        'message' => $message ?: null,
        'server_time' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$table = 'lieferscheine';
$search = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? '')); $status_lc = strtolower($status);
$sort = trim((string)($_GET['sort'] ?? 'date'));
$dir = strtolower(trim((string)($_GET['dir'] ?? 'desc'))) === 'asc' ? 'ASC' : 'DESC';
$page = max(1,(int)($_GET['page'] ?? 1));
$perPage = max(5,min(200,(int)($_GET['per_page'] ?? 25)));
$offset = ($page-1)*$perPage;

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        json_error('no_db', 'Database connection is not available.', 500);
    }

    // Verify base table exists
    try {
        $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
    } catch (Throwable $e) {
        echo json_encode([
            'success' => false,
            'error' => 'no_lieferscheine_table',
            'message' => "Base table '$table' not found. Import install.sql/schema.sql and configure DB connection.",
            'server_time' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Introspect columns
    $colsStmt = $pdo->prepare("SHOW COLUMNS FROM `$table`");
    $colsStmt->execute();
    $cols = $colsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $colNames = array_column($cols,'Field');
    if (!$colNames) { json_error('no_columns', 'No columns found for table.', 500); }

    // Detect file-like columns for convenience link generation
    $fileCandidates = [];
    foreach($colNames as $c){
        $lc=strtolower((string)$c);
        if (in_array($lc,['file','filename','file_name','file_path','filepath','attachment','pdf','document','file_url','path','url'], true)) {
            $fileCandidates[]=$c;
        }
        if (preg_match('/(_file|_path|_url|_pdf)$/', $lc) && !in_array($c,$fileCandidates,true)) {
            $fileCandidates[]=$c;
        }
    }

    // Build WHERE
    $where=[]; $params=[];
    if($search!==''){
        $likeParts=[];
        foreach(['nummer','lieferschein_nummer','kunde','beschreibung'] as $cand){ if(in_array($cand,$colNames,true)) $likeParts[] = "$cand LIKE :q"; }
        if($likeParts){ $where[]='(' . implode(' OR ',$likeParts) . ')'; $params[':q']='%'.$search.'%'; }
    }
    if($status!=='' && in_array('status',$colNames,true)){
            // Accept legacy English and German status values
            if (in_array($status_lc, ['offen','open'], true)) {
                $where[] = "status IN ('offen','open')";
            } elseif (in_array($status_lc, ['geliefert','delivered','done','erledigt'], true)) {
                $where[] = "status IN ('geliefert','delivered','done')";
            } elseif (in_array($status_lc, ['storniert','cancelled','canceled'], true)) {
                $where[] = "status IN ('storniert','cancelled','canceled')";
            } else {
                $where[]='status = :status'; $params[':status']=$status;
            }
        }
    $whereSql = $where? 'WHERE '.implode(' AND ',$where):'';

    // Sorting
    $allowedSort = []; foreach($colNames as $c){ $allowedSort[$c]=$c; }
    $sortSql = $allowedSort[$sort] ?? (in_array('created_at',$colNames,true)?'created_at':(in_array('datum',$colNames,true)?'datum':$colNames[0]));

    // Count
    $countSql = "SELECT COUNT(*) FROM `$table` $whereSql";
    $s = $pdo->prepare($countSql);
    foreach ($params as $k => $v) { $s->bindValue($k, $v); }
    $s->execute();
    $total = (int)$s->fetchColumn();

    // Data
    $selectCols = implode(', ', array_map(function($c){ return "`$c`"; }, $colNames));
    $sql = "SELECT $selectCols FROM `$table` $whereSql ORDER BY $sortSql $dir LIMIT :limit OFFSET :offset";
    $s = $pdo->prepare($sql);
    foreach($params as $k=>$v) { $s->bindValue($k,$v); }
    $s->bindValue(':limit',$perPage,PDO::PARAM_INT);
    $s->bindValue(':offset',$offset,PDO::PARAM_INT);
    $s->execute();
    $rows = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach($rows as &$r){
        $r = (array)$r;
        $r['_file_candidates']=$fileCandidates;
        $r['file_url']=null;
        foreach($fileCandidates as $fc){
            if(!empty($r[$fc])){
                $val=(string)$r[$fc];
                if(preg_match('#^https?://#i',$val)) { $r['file_url']=$val; }
                elseif(strlen($val)>0 && $val[0]=='/') { $r['file_url']=$val; }
                else { $r['file_url'] = '/uploads/' . ltrim($val,'/'); }
                break;
            }
        }
    }

    echo json_encode([
        'success' => true,
        'data' => $rows,
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'server_time' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE);
    exit;

} catch(Throwable $e){
    if (defined('APP_DEBUG') && APP_DEBUG && function_exists('debug_log')) {
        debug_log('lieferscheinen_list error: ' . $e->getMessage());
    }
    json_error('server_error', 'Unexpected error');
}
