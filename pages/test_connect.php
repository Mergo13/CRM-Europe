<?php
// test_connect.php
// Simple endpoint to verify DB connectivity (include config/db.php)
global $pdo;
require __DIR__ . '/config/db.php'; // ensures $pdo exists or exits

try {
    $stmt = $pdo->query('SELECT 1 as ok');
    $row = $stmt->fetch();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'db_ping' => $row['ok'] === 1], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Query failed', 'details' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
