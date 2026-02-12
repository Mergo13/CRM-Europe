<?php
// pages/api/mahnungen_escalate.php
// Escalate reminder level (stufe) for given Mahnung IDs.
// Accepts JSON or form data: { ids: [1,2,3] }

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
header('Content-Type: application/json; charset=utf-8');
if (isset($GLOBALS['pdo'])) { $pdo = $GLOBALS['pdo']; }

try {
    // Parse input (JSON preferred)
    $raw = file_get_contents('php://input');
    $data = [];
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if ($raw && stripos($ct, 'application/json') !== false) {
        $json = json_decode($raw, true);
        if (is_array($json)) { $data = $json; }
    }
    if (empty($data)) { $data = $_POST; }

    // Collect IDs
    $ids = $data['ids'] ?? [];
    if (!is_array($ids)) {
        if (is_string($ids)) {
            $ids = array_filter(array_map('trim', explode(',', $ids)), 'strlen');
        } else {
            $ids = [];
        }
    }
    $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));

    if (!$ids) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'missing_ids']);
        exit;
    }

    // Build placeholders
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    // Increment stufe; cap at 3 to avoid runaway escalation
    $sql = "UPDATE mahnungen SET stufe = LEAST(COALESCE(stufe, 0) + 1, 3) WHERE id IN ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);
    $affected = $stmt->rowCount();

    echo json_encode(['success' => true, 'updated' => $affected]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'server',
        'message' => $e->getMessage(),
    ]);
    exit;
}
