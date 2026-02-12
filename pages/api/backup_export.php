<?php
// pages/api/backup_export.php
// Export selected tables as JSON for a quick backup

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../config/db.php';
// Ensure $pdo exists (config/db.php should create it, but guard anyway)
if (!isset($pdo) || !($pdo instanceof PDO)) {
    if (isset($dsn, $db_user, $db_pass)) {
        $pdo = new PDO($dsn, $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } else {
        throw new RuntimeException('DB connection not available');
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

$tables = [
  'settings_company',
  'clients',
  'angebote',
  'rechnungen',
  'mahnungen',
  'lieferscheine'
];

$data = [
  'exported_at' => gmdate('c'),
  'app' => 'rechnung-app',
  'version' => 1,
  'tables' => []
];

foreach ($tables as $t) {
    try {
        // Check table exists
        $exists = $pdo->query("SHOW TABLES LIKE '".$t."'")->rowCount() > 0;
        if (!$exists) { $data['tables'][$t] = ['__error' => 'table not found']; continue; }
        $stmt = $pdo->query("SELECT * FROM `{$t}`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $data['tables'][$t] = $rows;
    } catch (Throwable $e) {
        $data['tables'][$t] = ['__error' => $e->getMessage()];
    }
}

// Persist last backup timestamp for dashboard/status (best-effort)
try {
    $metaDir = __DIR__ . '/../../data';
    if (!is_dir($metaDir)) { @mkdir($metaDir, 0755, true); }
    $stampPath = $metaDir . '/last_backup.json';
    @file_put_contents($stampPath, json_encode(['exported_at'=>$data['exported_at']], JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) { /* ignore */ }

header('Content-Disposition: attachment; filename="backup.json"');
echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
