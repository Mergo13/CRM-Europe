<?php
// pages/api/rechnungen_export.php - CSV export
header('Content-Type: text/csv; charset=utf-8');

// Bootstrap DB
try {
    $pdo = require_once __DIR__ . '/../../config/db.php';
    if (!($pdo instanceof PDO) && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $pdo = $GLOBALS['pdo'];
    }
    if (!($pdo instanceof PDO)) { throw new RuntimeException('No PDO instance'); }
} catch (Throwable $e) {
    http_response_code(500);
    echo "Fehler: DB";
    exit;
}

$table = 'rechnungen';
$search = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$sort = trim($_GET['sort'] ?? 'date');
$dir = strtolower(trim($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

try {
    $colsStmt = $pdo->prepare("SHOW COLUMNS FROM `$table`");
    $colsStmt->execute();
    $cols = $colsStmt->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'Field');
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Fehler';
    exit;
}

$where = [];
$params = [];
if ($search !== '') {
    $likeParts = [];
    foreach (['nummer','rechnungsnummer','kunde','beschreibung'] as $cand) {
        if (in_array($cand, $colNames, true)) { $likeParts[] = "$cand LIKE :q"; }
    }
    if ($likeParts) { $where[] = '(' . implode(' OR ', $likeParts) . ')'; $params[':q'] = '%' . $search . '%'; }
}
if ($status !== '' && in_array('status', $colNames, true)) {
    $where[] = 'status = :status';
    $params[':status'] = $status;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$allowedSort = [];
foreach ($colNames as $c) $allowedSort[$c] = $c;
$sortSql = $allowedSort[$sort] ?? (in_array('created_at', $colNames, true) ? 'created_at' : (in_array('datum', $colNames, true) ? 'datum' : $colNames[0]));

try {
    $selectCols = implode(', ', array_map(function ($c) { return '`' . $c . '`'; }, $colNames));
    $sql = "SELECT $selectCols FROM `$table` $whereSql ORDER BY $sortSql $dir";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Disposition: attachment; filename="rechnungen_export.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $colNames);
    foreach ($rows as $r) {
        $line = [];
        foreach ($colNames as $c) $line[] = isset($r[$c]) ? $r[$c] : '';
        fputcsv($out, $line);
    }
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Fehler';
    exit;
}
