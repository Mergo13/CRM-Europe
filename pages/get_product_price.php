<?php
global $pdo;
header('Content-Type: application/json; charset=utf-8');
try {
    require '../config/db.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'database_unavailable', 'message' => 'Database connection failed. Please configure config/app-config.php and database credentials.', 'details' => $e->getMessage()]);
    exit;
}

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'missing_parameter', 'message' => 'Parameter id is required']);
    exit;
}

try {
    $produkt_id = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT menge, preis FROM produkt_preise WHERE produkt_id=? ORDER BY menge ASC");
    $stmt->execute([$produkt_id]);
    $tiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['data' => $tiers]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'query_failed', 'message' => 'Could not fetch tiers', 'details' => $e->getMessage()]);
}
