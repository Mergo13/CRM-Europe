<?php
// pages/api_client_lookup.php
require __DIR__ . '/../config/db.php';
global $pdo;
header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    echo json_encode([]);
    exit;
}

// simple search: exact name first, then LIKE. Use parameterized queries to avoid injection.
try {
    $params = ['%'.$q.'%'];
    $stmt = $pdo->prepare("SELECT id, name, adresse, plz, ort, email, kundennummer FROM clients WHERE name LIKE ? ORDER BY name LIMIT 10");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // If no rows and the query exactly matches a name? not needed; return rows as-is
    echo json_encode($rows);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
