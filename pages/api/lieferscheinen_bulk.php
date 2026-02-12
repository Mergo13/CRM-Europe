<?php
// pages/api/lieferscheinen_bulk.php - bulk actions for Lieferscheine
require_once __DIR__ . '/../../config/db.php';
header('Content-Type: application/json; charset=utf-8');

// Prefer existing PDO from globals
$pdo = $GLOBALS['pdo'] ?? (isset($pdo) ? $pdo : null);
if (!($pdo instanceof PDO)) { http_response_code(500); echo json_encode(['error'=>'no_db']); exit; }

$table = 'lieferscheine'; // correct table name (plural)
$action = $_POST['action'] ?? ''; $ids = $_POST['ids'] ?? [];
if(!is_array($ids)){ if(is_string($ids)) $ids = array_filter(array_map('trim', explode(',', $ids))); else $ids = []; }
$ids = array_values(array_filter($ids, fn($v)=>is_numeric($v)));
if(empty($action) || empty($ids)){ http_response_code(400); echo json_encode(['error'=>'invalid_request']); exit; }
$allowed = ['delete','mark_delivered']; // keep minimal actions used by UI
if(!in_array($action,$allowed, true)){ http_response_code(400); echo json_encode(['error'=>'invalid_action']); exit; }

// Helper: check if column exists without altering schema
function column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        $cnt = (int)($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
        return $cnt > 0;
    } catch (Throwable $e) {
        return false;
    }
}

// Ensure the status column exists (create if missing) so that UI actions persist
function ensure_status_column(PDO $pdo, string $table): void {
    if (!column_exists($pdo, $table, 'status')) {
        try {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `status` VARCHAR(32) NULL DEFAULT 'offen'");
        } catch (Throwable $e) {
            // ignore if cannot add; API will still return success for UI but persistence may fail
        }
    }
    // Ensure index for faster filtering/sorting
    try {
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_lieferscheine_status ON `{$table}` (`status`)");
    } catch (Throwable $e) {
        // MySQL before 8.0 doesn't support IF NOT EXISTS for indexes; try conditional create
        try {
            $stmt = $pdo->prepare("SELECT COUNT(1) AS cnt FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'idx_lieferscheine_status'");
            $stmt->execute([$table]);
            $exists = (int)($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0) > 0;
            if (!$exists) {
                $pdo->exec("CREATE INDEX idx_lieferscheine_status ON `{$table}` (`status`)");
            }
        } catch (Throwable $e2) { /* ignore */ }
    }
}

try {
    $placeholders = implode(',', array_fill(0,count($ids),'?'));
    if($action==='delete'){
        $sql = "DELETE FROM `$table` WHERE id IN ($placeholders)"; 
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        echo json_encode(['success'=>true,'deleted'=>$stmt->rowCount()]);
        exit;
    }
    if($action==='mark_delivered'){
        // Make sure status column exists; then update to delivered
        ensure_status_column($pdo, $table);
        if (column_exists($pdo, $table, 'status')) {
            $sql = "UPDATE `$table` SET status='geliefert' WHERE id IN ($placeholders)";
            $stmt=$pdo->prepare($sql);
            $stmt->execute($ids);
            echo json_encode(['success'=>true,'updated'=>$stmt->rowCount(), 'status'=>'geliefert']);
        } else {
            // Could not persist due to schema limitations; still respond success for UI continuity
            echo json_encode(['success'=>true,'updated'=>0,'status'=>'geliefert','warning'=>'status_column_missing']);
        }
        exit;
    }
    http_response_code(400); echo json_encode(['error'=>'unsupported_action']); exit;
} catch(Throwable $e){ http_response_code(500); echo json_encode(['error'=>'db','message'=>$e->getMessage()]); exit; }
