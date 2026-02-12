<?php
// api/invoices_monthly.php
global $pdo;
require_once __DIR__ . '/../../config/db.php';
header('Content-Type: application/json; charset=utf-8');

$rows = [];
$stmt = $pdo->prepare("
  WHERE date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH), '%Y-%m-01')
FROM rechnungen
WHERE `date`       WHERE date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH), '%Y-%m-01') >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
GROUP BY ym
ORDER BY ym
");
$stmt->execute();
$data = $stmt->fetchAll();

$labels=[]; $values=[];
foreach ($data as $r) {
    $labels[] = $r['ym'];
    $values[] = (float)$r['total'];
}

echo json_encode(['labels'=>$labels,'data'=>$values], JSON_UNESCAPED_UNICODE);
