<?php
// pages/api/mahnungen_send.php
// Marks selected Mahnungen as "gesendet" (sent) and timestamps them when columns exist.
// Accepts JSON or form data: { ids: [1,2,3] }

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
header('Content-Type: application/json; charset=utf-8');
if (isset($GLOBALS['pdo'])) { $pdo = $GLOBALS['pdo']; }

try {
    // Parse input
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

    // Inspect mahnungen table columns to build a safe update
    $colsStmt = $pdo->query("SHOW COLUMNS FROM `mahnungen`");
    $cols = $colsStmt->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_map(static function ($c) { return strtolower((string)$c['Field']); }, $cols);

    $sets = [];
    $params = [];

    // Prefer explicit status column
    if (in_array('status', $colNames, true)) {
        $sets[] = "`status` = :status";
        $params[':status'] = 'gesendet';
    }
    // Common timestamp variants
    if (in_array('sent_at', $colNames, true)) {
        $sets[] = "`sent_at` = NOW()";
    } elseif (in_array('versendet_am', $colNames, true)) {
        $sets[] = "`versendet_am` = NOW()";
    } elseif (in_array('updated_at', $colNames, true)) {
        $sets[] = "`updated_at` = NOW()";
    }

    if (!$sets) {
        // Fallback: if no known columns to update, still consider it OK (no-op), to satisfy UI flow.
        echo json_encode(['success' => true, 'updated' => 0, 'note' => 'no_updatable_columns']);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "UPDATE `mahnungen` SET " . implode(', ', $sets) . " WHERE `id` IN ($placeholders)";
    $stmt = $pdo->prepare($sql);

    // Bind named params first (e.g., :status), then positional ids
    foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
    $i = 1;
    foreach ($ids as $id) { $stmt->bindValue($i, $id, PDO::PARAM_INT); $i++; }

    $stmt->execute();
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
