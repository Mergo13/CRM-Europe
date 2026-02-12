<?php
// pages/api/clients_list.php
// Returns clients with optional invoice statistics for realtime UI updates
// GET parameters:
//   page: int (default 1)
//   per_page: int (default 25, max 100)
//   q: string (search firma|name|email|telefon)
//   sort_by: one of id|firma|name|email|telefon|last_invoice_date|paid_amount|open_amount|total_rechnungen|open_rechnungen
//   sort_dir: asc|desc (default desc for id)
//   include_stats: 0|1 include aggregate stats block
// Response JSON:
//   { success: true, data: [...], pagination: {...}, stats: {...}, server_time: 'YYYY-MM-DD HH:MM:SS' }

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/init.php';

header('Content-Type: application/json; charset=utf-8');

function json_error(string $message, int $status = 500): void {
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    global $pdo;
    if (!($pdo instanceof PDO)) {
        json_error('no_db', 500);
    }

    // Read and sanitize params
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = (int)($_GET['per_page'] ?? 25);
    if ($perPage < 1) $perPage = 25;
    if ($perPage > 100) $perPage = 100;
    $q = trim((string)($_GET['q'] ?? ''));
    $includeStats = isset($_GET['include_stats']) && (int)$_GET['include_stats'] === 1;

    $allowedSort = [
        'id' => 'c.id',
        'firma' => 'c.firma',
        'name' => 'c.name',
        'email' => 'c.email',
        'telefon' => 'c.telefon',
        'last_invoice_date' => 'last_invoice_date',
        'paid_amount' => 'paid_amount',
        'open_amount' => 'open_amount',
        'total_rechnungen' => 'total_rechnungen',
        'open_rechnungen' => 'open_rechnungen',
    ];
    $sortByParam = (string)($_GET['sort_by'] ?? 'id');
    $sortBy = $allowedSort[$sortByParam] ?? 'c.id';
    $sortDir = strtolower((string)($_GET['sort_dir'] ?? 'desc')); // default latest first
    $sortDir = $sortDir === 'asc' ? 'ASC' : 'DESC';

    // Check if rechnungen table exists
    $hasInvoices = true;
    try {
        $pdo->query('SELECT 1 FROM rechnungen LIMIT 1');
    } catch (Throwable $e) {
        $hasInvoices = false;
    }

    // Ensure required base table exists: clients
    $hasClients = true;
    try {
        $pdo->query('SELECT 1 FROM clients LIMIT 1');
    } catch (Throwable $e) {
        $hasClients = false;
    }
    if (!$hasClients) {
        // Return structured error so UI can notify and operator can run install.sql
        echo json_encode([
            'success' => false,
            'error' => 'no_clients_table',
            'message' => 'Base table \'clients\' not found. Import install.sql and configure DB connection.',
            'server_time' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Build base SELECT
    if ($hasInvoices) {
        $select = "
            SELECT 
                c.*,
                (SELECT COUNT(DISTINCT r1.id) FROM rechnungen r1 WHERE r1.client_id = c.id) AS total_rechnungen,
                (SELECT COUNT(DISTINCT r2.id) FROM rechnungen r2 WHERE r2.client_id = c.id AND r2.paid_at IS NULL) AS open_rechnungen,
                COALESCE((SELECT SUM(COALESCE(r3.gesamt, r3.betrag, r3.total)) FROM rechnungen r3 WHERE r3.client_id = c.id AND r3.paid_at IS NOT NULL), 0) AS paid_amount,
                COALESCE((SELECT SUM(COALESCE(r4.gesamt, r4.betrag, r4.total)) FROM rechnungen r4 WHERE r4.client_id = c.id AND r4.paid_at IS NULL), 0) AS open_amount,
                (SELECT MAX(r5.datum) FROM rechnungen r5 WHERE r5.client_id = c.id) AS last_invoice_date
            FROM clients c
        ";
    } else {
        $select = "
            SELECT 
                c.*,
                0 AS total_rechnungen,
                0 AS open_rechnungen,
                0.0 AS paid_amount,
                0.0 AS open_amount,
                NULL AS last_invoice_date
            FROM clients c
        ";
    }

    // Filtering
    $where = [];
    $params = [];
    if ($q !== '') {
        $where[] = '(c.firma LIKE :q OR c.name LIKE :q OR c.email LIKE :q OR c.telefon LIKE :q)';
        $params[':q'] = '%' . $q . '%';
    }

    $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

    // Total count for pagination (without LIMIT)
    $countSql = 'SELECT COUNT(*) FROM clients c' . $whereSql;
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $k => $v) { $countStmt->bindValue($k, $v, PDO::PARAM_STR); }
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    $totalPages = (int)max(1, ceil($total / $perPage));
    if ($page > $totalPages) { $page = $totalPages; }
    $offset = ($page - 1) * $perPage;

    // Final list query with ordering and pagination
    $sql = $select . $whereSql . " ORDER BY $sortBy $sortDir, c.id DESC LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) { $stmt->bindValue($k, $v, PDO::PARAM_STR); }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Aggregate stats (optional)
    $stats = null;
    if ($includeStats) {
        if ($hasInvoices) {
            // Compute using a single pass over current page? Better compute for all with subqueries
            $statsSql = "
                SELECT 
                    (SELECT COUNT(*) FROM clients) AS total,
                    (SELECT COUNT(DISTINCT r.client_id) FROM rechnungen r) AS with_invoices,
                    (SELECT COUNT(DISTINCT r2.client_id) FROM rechnungen r2 WHERE r2.status = 'offen') AS with_open,
                    COALESCE((SELECT SUM(r3.betrag) FROM rechnungen r3 WHERE r3.status = 'bezahlt'), 0) AS total_revenue,
                    COALESCE((SELECT SUM(r4.betrag) FROM rechnungen r4 WHERE r4.status = 'offen'), 0) AS open_amount
            ";
            $stats = $pdo->query($statsSql)->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($stats) {
                $stats['total'] = (int)$stats['total'];
                $stats['with_invoices'] = (int)$stats['with_invoices'];
                $stats['with_open'] = (int)$stats['with_open'];
                $stats['total_revenue'] = (float)$stats['total_revenue'];
                $stats['open_amount'] = (float)$stats['open_amount'];
            }
        } else {
            $totalClients = (int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn();
            $stats = [
                'total' => $totalClients,
                'with_invoices' => 0,
                'with_open' => 0,
                'total_revenue' => 0.0,
                'open_amount' => 0.0,
            ];
        }
    }

    $payload = [
        'success' => true,
        'data' => $rows,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
        ],
        'stats' => $stats,
        'server_time' => date('Y-m-d H:i:s'),
    ];

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    if (defined('APP_DEBUG') && APP_DEBUG && function_exists('debug_log')) {
        debug_log('clients_list_api error: ' . $e->getMessage());
    }
    json_error('server_error', 500);
}
