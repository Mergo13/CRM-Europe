<?php
// pages/api/backup_import.php
// Restore data from a JSON backup created by backup_export.php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../config/db.php';
// Ensure $pdo exists
if (!isset($pdo) || !($pdo instanceof PDO)) {
    if (isset($dsn, $db_user, $db_pass)) {
        $pdo = new PDO($dsn, $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success'=>false,'error'=>'DB connection not available']);
        exit;
    }
}
$auth = __DIR__ . '/../../includes/auth.php';
if (is_file($auth)) require_once $auth;

// Require login if auth is available, but allow in development
$envDev = isset($is_dev) ? $is_dev : in_array(strtolower((string)(getenv('APP_ENV') ?: 'development')), ['dev','local','development'], true);
if (function_exists('current_user') && !$envDev) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $user = current_user($pdo);
    if (!$user) { http_response_code(401); echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }
}

if (empty($_FILES['backup']) || ($_FILES['backup']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'Keine gültige Backup-Datei übermittelt']);
    exit;
}

$raw = @file_get_contents($_FILES['backup']['tmp_name']);
$json = json_decode((string)$raw, true);
if (!$json || !is_array($json) || empty($json['tables']) || !is_array($json['tables'])) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'Ungültiges Backup-Format']);
    exit;
}

$tables = $json['tables'];

try {
    $pdo->beginTransaction();

    $upsert = function(string $table, array $rows) use ($pdo) {
        if (!$rows) return;
        // Build column list from union of keys across rows
        $cols = [];
        foreach ($rows as $r) { foreach (array_keys($r) as $k) { if (!in_array($k, $cols, true)) $cols[] = $k; } }
        if (!$cols) return;
        $hasId = in_array('id', $cols, true);
        $ph = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $sql = "INSERT INTO `{$table}` (`" . implode('`,`', $cols) . "`) VALUES {$ph}";
        if ($hasId) {
            $updates = [];
            foreach ($cols as $c) { if ($c === 'id') continue; $updates[] = "`$c` = VALUES(`$c`)"; }
            if ($updates) $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(',', $updates);
        }
        $stmt = $pdo->prepare($sql);
        foreach ($rows as $row) {
            $vals = [];
            foreach ($cols as $c) { $vals[] = array_key_exists($c, $row) ? $row[$c] : null; }
            $stmt->execute($vals);
        }
    };

    // Order to respect common dependencies
    $order = ['settings_company','clients','angebote','rechnungen','mahnungen','lieferscheine'];
    foreach ($order as $t) {
        if (!isset($tables[$t]) || !is_array($tables[$t])) continue;
        if (isset($tables[$t]['__error'])) continue;
        // Skip if table doesn't exist
        $exists = $pdo->query("SHOW TABLES LIKE '".$t."'")->rowCount() > 0;
        if (!$exists) continue;
        $upsert($t, $tables[$t]);
    }

    $pdo->commit();
    echo json_encode(['success'=>true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
