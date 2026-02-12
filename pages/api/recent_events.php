<?php
// api/recent_events.php
require_once __DIR__ . '/../../config/db.php';
header('Content-Type: application/json; charset=utf-8');

$stmt = $pdo->query("
  SELECT 'invoice' AS type, id, number, date, betrage AS amount
  FROM rechnungen
  ORDER BY date DESC
  LIMIT 10
");
$rows = $stmt->fetchAll();

echo json_encode(['rows'=>$rows], JSON_UNESCAPED_UNICODE);
