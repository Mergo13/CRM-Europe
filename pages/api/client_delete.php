<?php
// pages/api/client_delete.php
// Deletes a client and related records (invoices, reminders, offers) in a safe order.

$appEnv = getenv('APP_ENV') ?: (defined('APP_ENV') ? APP_ENV : 'production');
$devMode = in_array(strtolower((string)$appEnv), ['dev','local','development'], true);

if ($devMode) {
    ini_set('display_errors','1');
    ini_set('display_startup_errors','1');
    error_reporting(E_ALL);
}

header('Content-Type: application/json; charset=utf-8');

function respond($data, $http = 200) {
    http_response_code($http);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Bootstrap DB (support both return and global $pdo styles)
try {
    $pdo = require_once __DIR__ . '/../../config/db.php';
    if (!($pdo instanceof PDO) && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $pdo = $GLOBALS['pdo'];
    }
    if (!($pdo instanceof PDO)) throw new RuntimeException('No PDO instance from config/db.php');
} catch (Throwable $e) {
    respond(['success' => false, 'error' => 'db_bootstrap', 'message' => $devMode ? $e->getMessage() : 'Database unavailable'], 500);
}

// Accept JSON or form-encoded
$raw = file_get_contents('php://input');
$ct  = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
$payload = null;
if ($raw && stripos($ct, 'application/json') !== false) {
    $payload = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        respond(['success'=>false,'error'=>'invalid_json','message'=>json_last_error_msg()], 400);
    }
}
if (!is_array($payload)) $payload = [];

$id = $_POST['id'] ?? $payload['id'] ?? null;
$id = is_numeric($id) ? (int)$id : 0;
if ($id <= 0) {
    respond(['success'=>false,'error'=>'invalid_id','message'=>'Missing or invalid client id'], 400);
}

try {
    // Ensure client exists
    $stmt = $pdo->prepare('SELECT id FROM clients WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $exists = $stmt->fetchColumn();
    if (!$exists) {
        respond(['success'=>false,'error'=>'not_found','message'=>'Client not found'], 404);
    }

    $pdo->beginTransaction();

    // Delete dependent data in safe order (schema lacks ON DELETE CASCADE)
    // 1) Reminders (mahnungen) -> join via rechnungen
    try {
        $sql = 'DELETE m FROM mahnungen m INNER JOIN rechnungen r ON r.id = m.rechnung_id WHERE r.client_id = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
    } catch (Throwable $e) {
        // If table not existing, ignore
    }

    // 2) Invoice positions (rechnungs_positionen) via join -> remove before deleting invoices
    try {
        $sql = 'DELETE rp FROM rechnungs_positionen rp INNER JOIN rechnungen r ON r.id = rp.rechnung_id WHERE r.client_id = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
    } catch (Throwable $e) {
        // ignore if positions table missing
    }

    // 3) Invoices (rechnungen)
    try {
        $stmt = $pdo->prepare('DELETE FROM rechnungen WHERE client_id = ?');
        $stmt->execute([$id]);
    } catch (Throwable $e) {
        // ignore if table missing in minimal installs
    }

    // 3b) Offer positions (angebot_positionen) via join -> remove before deleting angebote
    try {
        $sql = 'DELETE ap FROM angebot_positionen ap INNER JOIN angebote a ON a.id = ap.angebot_id WHERE a.client_id = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
    } catch (Throwable $e) {
        // ignore if positions table missing
    }

    // 3) Offers (angebote)
    try {
        $stmt = $pdo->prepare('DELETE FROM angebote WHERE client_id = ?');
        $stmt->execute([$id]);
    } catch (Throwable $e) {
        // ignore if table missing in minimal installs
    }

    // 4a) Delivery note positions (lieferschein_positionen) via join -> remove before deleting lieferscheine
    try {
        $sql = 'DELETE lp FROM lieferschein_positionen lp INNER JOIN lieferscheine l ON l.id = lp.lieferschein_id WHERE l.client_id = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
    } catch (Throwable $e) {
        // ignore if positions table missing
    }

    // 4) Delivery notes (lieferscheine)
    try {
        $stmt = $pdo->prepare('DELETE FROM lieferscheine WHERE client_id = ?');
        $stmt->execute([$id]);
    } catch (Throwable $e) {
        // ignore if table missing in minimal installs
    }

    // 5) Finally, the client
    $stmt = $pdo->prepare('DELETE FROM clients WHERE id = ?');
    $stmt->execute([$id]);

    $pdo->commit();

    respond(['success'=>true]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond([
        'success'=>false,
        'error'=>'exception',
        'message' => $devMode ? ($e->getMessage()) : 'Delete failed'
    ], 500);
}
