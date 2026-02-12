<?php
// pages/api/angeboten_bulk.php - bulk actions for Angebote (offers)

declare(strict_types=1);
session_start();
if (
    empty($_SERVER['HTTP_X_CSRF_TOKEN']) ||
    $_SERVER['HTTP_X_CSRF_TOKEN'] !== ($_SESSION['csrf_token'] ?? '')
) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'csrf']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// Bootstrap DB and get $pdo
$pdo = require_once __DIR__ . '/../../config/db.php';
if (!($pdo instanceof PDO) && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
    $pdo = $GLOBALS['pdo'];
}
if (!($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'db_bootstrap']);
    exit;
}

$table = 'angebote';

// Parse inputs (form-encoded or JSON)
$action = $_POST['action'] ?? '';
$ids    = $_POST['ids'] ?? ($_POST['selected'] ?? []);
$status = isset($_POST['status']) ? trim((string)$_POST['status']) : '';

$ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
if (stripos($ct, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $action = $json['action'] ?? $action;
        $ids    = $json['ids'] ?? $ids;
        $status = isset($json['status']) ? trim((string)$json['status']) : $status;
    }
}

if (!is_array($ids)) {
    if (is_string($ids)) {
        $ids = array_filter(array_map('trim', explode(',', $ids)), 'strlen');
    } elseif (is_numeric($ids)) {
        $ids = [(int)$ids];
    } else {
        $ids = [];
    }
}
$ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));

if ($action === '' || empty($ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_request']);
    exit;
}

$allowed = ['delete','set_status','accept_offer'];
if (!in_array($action, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_action']);
    exit;
}

try {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    if ($action === 'delete') {
        $pdo->beginTransaction();
        try {
            // 1) delete children first to satisfy FK constraints
            $sql1 = "DELETE FROM angebot_positionen WHERE angebot_id IN ($placeholders)";
            $stmt1 = $pdo->prepare($sql1);
            $stmt1->execute($ids);

            // 2) delete parent rows
            $sql2 = "DELETE FROM `$table` WHERE id IN ($placeholders)";
            $stmt2 = $pdo->prepare($sql2);
            $stmt2->execute($ids);

            $pdo->commit();
            echo json_encode([
                'success' => true,
                'deleted' => $stmt2->rowCount(),
                'deleted_positions' => $stmt1->rowCount(),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    if ($action === 'accept_offer') {
        // Map to schema value 'angenommen'
        $sql = "UPDATE `$table` SET status = 'angenommen' WHERE id IN ($placeholders)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);
        exit;
    }

    if ($action === 'set_status') {
        // Only supported enum values in schema
        $valid = ['offen','angenommen','abgelehnt'];
        if ($status === '' || !in_array(strtolower($status), $valid, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_status', 'allowed' => $valid]);
            exit;
        }
        $status = strtolower(trim($status));

        $sql = "UPDATE `$table`
        SET status = ?
        WHERE status != ?
          AND id IN ($placeholders)";

        $params = array_merge([$status, $status], $ids);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['success' => true, 'updated' => $stmt->rowCount(), 'status' => $status]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'unsupported_action']);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'db', 'message' => $e->getMessage()]);
    exit;
}