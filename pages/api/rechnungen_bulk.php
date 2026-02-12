<?php

// pages/api/rechnungen_bulk.php
// Robust bulk actions endpoint with improved error handling and duplicate mapping.

$appEnv = getenv('APP_ENV') ?: (defined('APP_ENV') ? APP_ENV : 'production');
$devMode = in_array(strtolower((string)$appEnv), ['dev','local','development'], true);

if ($devMode) {
    ini_set('display_errors','0');
    ini_set('display_startup_errors','1');
    error_reporting(E_ALL);
}

header('Content-Type: application/json; charset=utf-8');

$logFile = __DIR__ . '/../../logs/api_rechnungen_bulk_debug.log';
@mkdir(dirname($logFile), 0750, true);

function respond($data, $http = 200) {
    http_response_code($http);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function log_error($msg) {
    global $logFile;
    @file_put_contents($logFile, "[".date('c')."] ".$msg."\n", FILE_APPEND|LOCK_EX);
}

function has_column(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

function has_table(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

try {
    // Expect config/db.php to RETURN the PDO instance: $pdo = require_once(...);
    $pdo = require_once __DIR__ . '/../../config/db.php';
    // fallback: maybe config set $GLOBALS['pdo']
    if (!($pdo instanceof PDO) && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $pdo = $GLOBALS['pdo'];
    }
    if (!($pdo instanceof PDO)) throw new RuntimeException('No PDO instance returned from config/db.php');
} catch (Throwable $e) {
    log_error("DB bootstrap error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    respond([
        'success' => false,
        'error' => 'db_bootstrap',
        'message' => $devMode ? $e->getMessage() : 'Database unavailable'
    ], 500);
}

// ---- Parse input (JSON or form)
$raw = file_get_contents('php://input');
$ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';

$payload = null;
if ($raw && stripos($ct, 'application/json') !== false) {
    $payload = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // try to be lenient: return error
        respond(['success'=>false,'error'=>'invalid_json','message'=>json_last_error_msg()], 400);
    }
}

// normalize payload to array (avoid null warnings)
if (!is_array($payload)) {
    $payload = is_string($payload) && $payload !== '' ? (json_decode($raw, true) ?: []) : [];
    if (!is_array($payload)) $payload = [];
}

// Accept common aliases for action (clear, no deep nesting)
$action = $_POST['action'] ?? $payload['action'] ?? $_POST['cmd'] ?? $payload['cmd'] ?? '';

// Accept multiple possible keys for IDs (flattened, readable)
$idsRaw = $_POST['ids']
    ?? $payload['ids']
    ?? $_POST['rechnung_ids']
    ?? $payload['rechnung_ids']
    ?? $_POST['selected']
    ?? $payload['selected']
    ?? $_POST['id']
    ?? $payload['id']
    ?? [];

// Normalize to array of strings/numbers
if (!is_array($idsRaw)) {
    if (is_string($idsRaw)) {
        $idsRaw = array_filter(array_map('trim', explode(',', $idsRaw)), 'strlen');
    } elseif (is_numeric($idsRaw)) {
        $idsRaw = [(int)$idsRaw];
    } else {
        $idsRaw = [];
    }
}
$ids = array_values(array_map('intval', $idsRaw));
$ids = array_filter($ids, fn($v) => $v > 0);

// normalize ints and remove non-positive
$ids = array_values(array_filter(array_map('intval', $idsRaw), fn($v)=> $v > 0));

// Validate
if (empty($ids)) respond(['success'=>false,'error'=>'missing_ids'], 400);
if (empty($action)) respond(['success'=>false,'error'=>'missing_action'], 400);

// Map legacy/alias actions to canonical names
$aliases = [
    // Map various UI variants to canonical actions
    'Mark' => 'mark_paid',
    'mark' => 'mark_paid',
    'mark_done' => 'mark_paid',
    'bezahlt' => 'mark_paid',
    'Bezahlt' => 'mark_paid',
    'Delete' => 'delete',
    'remove' => 'delete',
    'open' => 'mark_open',
    'MarkOpen' => 'mark_open',
    'mark_open' => 'mark_open'
];
if (isset($aliases[$action])) $action = $aliases[$action];

$allowed = ['delete','mark_paid','duplicate','mark_open'];
if (!in_array($action, $allowed, true)) respond(['success'=>false,'error'=>'invalid_action','action'=>$action], 400);

$table = 'rechnungen'; // adapt if different

// Preflight: ensure table exists
try {
    $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
} catch (Throwable $e) {
    log_error("Preflight failed for table `$table`: " . $e->getMessage());
    respond(['success'=>false,'error'=>'missing_table','message'=>$devMode ? $e->getMessage() : 'Table missing'], 500);
}

try {
    // Use transaction for safety on multi-row ops
    $pdo->beginTransaction();

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    if ($action === 'delete') {
        // Delete child rows first to satisfy potential FK constraints
        $sql1 = "DELETE FROM rechnungs_positionen WHERE rechnung_id IN ($placeholders)";
        $stmt1 = $pdo->prepare($sql1);
        $stmt1->execute($ids);

        // Then delete the invoices themselves
        $sql2 = "DELETE FROM `$table` WHERE id IN ($placeholders)";
        $stmt2 = $pdo->prepare($sql2);
        $stmt2->execute($ids);
        $affected = $stmt2->rowCount();

        $pdo->commit();
        respond(['success'=>true,'deleted'=>$affected,'deleted_positions'=>$stmt1->rowCount()]);
    }

    if ($action === 'mark_paid') {

        $sql = "UPDATE `$table`
            SET status = 'bezahlt',
                paid_at = COALESCE(paid_at, NOW())
            WHERE id IN ($placeholders)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);

        $affected = $stmt->rowCount();
        $pdo->commit();

        respond([
            'success' => true,
            'updated' => $affected
        ]);
    }




    if ($action === 'mark_open') {
        // set back to open and clear paid_at
        // Ensure column exists (not strictly required to clear NULL)
        try { $pdo->exec("ALTER TABLE `$table` ADD COLUMN paid_at DATETIME NULL"); } catch (Throwable $e) {}
        $sql = "UPDATE `$table` SET status = 'offen', paid_at = NULL WHERE id IN ($placeholders)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $affected = $stmt->rowCount();
        $pdo->commit();
        respond(['success'=>true,'updated'=>$affected]);
    }

    if ($action === 'duplicate') {
        // Fetch columns and primary key
        $colsStmt = $pdo->query("SHOW COLUMNS FROM `$table`");
        $cols = $colsStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$cols) throw new RuntimeException('Unable to read table columns');

        $allCols = array_map(fn($c) => $c['Field'], $cols);
        $pk = null;
        foreach ($cols as $c) {
            if (stripos($c['Extra'] ?? '', 'auto_increment') !== false) {
                $pk = $c['Field'];
                break;
            }
        }
        if (!$pk) $pk = 'id';

        // Select all columns including PK for mapping
        $selSql = "SELECT `" . implode("`,`", $allCols) . "` FROM `$table` WHERE `$pk` IN ($placeholders)";
        $selStmt = $pdo->prepare($selSql);
        $selStmt->execute($ids);
        $rows = $selStmt->fetchAll(PDO::FETCH_ASSOC);

        $insertCols = array_values(array_filter($allCols, fn($c)=> $c !== $pk));
        if (empty($insertCols)) throw new RuntimeException('No insertable columns found');

        // Prepare insert (single row per prepared execute)
        $qMarks = implode(',', array_fill(0, count($insertCols), '?'));
        $insSql = "INSERT INTO `$table` (`" . implode('`,`', $insertCols) . "`) VALUES ($qMarks)";
        $insStmt = $pdo->prepare($insSql);

        $mapping = [];
        $inserted = 0;
        foreach ($rows as $r) {
            $oldId = $r[$pk] ?? null;
            // transform row: override desired fields
            if (array_key_exists('status', $r)) $r['status'] = 'draft';
            if (array_key_exists('datum', $r)) $r['datum'] = date('Y-m-d');
            if (array_key_exists('created_at', $r)) $r['created_at'] = date('Y-m-d H:i:s');
            if (array_key_exists('rechnungsnummer', $r)) $r['rechnungsnummer'] = (string)$r['rechnungsnummer'] . '-COPY';

            $values = [];
            foreach ($insertCols as $c) $values[] = $r[$c] ?? null;

            $insStmt->execute($values);
            $newId = (int)$pdo->lastInsertId();
            $mapping[$oldId] = $newId;
            $inserted++;
        }

        $pdo->commit();
        respond(['success'=>true,'duplicated'=>$inserted,'map'=>$mapping]);
    }

    // Unsupported action fallback
    $pdo->commit();
    respond(['success'=>false,'error'=>'unsupported_action'], 400);

} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    $debugInfo = null;
    if ($devMode) {
        $debugInfo = ['trace' => $e->getTrace()];
        if ($e instanceof PDOException && isset($e->errorInfo) && is_array($e->errorInfo)) {
            $debugInfo['sqlstate'] = $e->errorInfo[0] ?? null;
            $debugInfo['driver_code'] = $e->errorInfo[1] ?? null;
            $debugInfo['driver_message'] = $e->errorInfo[2] ?? null;
        }
    }
    $msg = "Exception: " . $e->getMessage();
    if ($debugInfo && isset($debugInfo['sqlstate'])) { $msg .= " | SQLSTATE: " . $debugInfo['sqlstate']; }
    log_error($msg . "\n" . $e->getTraceAsString());
    respond([
        'success' => false,
        'error' => 'db',
        'message' => $devMode ? $e->getMessage() : 'Database error',
        'debug' => $debugInfo
    ], 500);
}
