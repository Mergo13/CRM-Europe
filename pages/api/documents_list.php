<?php
// pages/api/documents_list.php
// Generic list endpoint for documents (rechnungen, angebote, mahnungen, lieferscheine)

declare(strict_types=1);

global $pdo;

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/docs_common.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: application/json; charset=utf-8');

try {
    // doc_type can be passed via GET or pre-set by including shims
    $docType = isset($DOC_TYPE) ? (string)$DOC_TYPE : (string)($_GET['doc_type'] ?? 'rechnungen');
    $cfg = doc_type_config($docType);
    $table = $cfg['table'];

    // Parameters
    $page       = max(1, (int)($_GET['page']      ?? 1));
    $perPage    = max(1, min(500, (int)($_GET['per_page'] ?? 25)));
    $sortParam  = (string)($_GET['sort'] ?? 'datum');
    // Support both 'dir' and 'direction' params
    $dirParam   = strtolower((string)($_GET['dir'] ?? ($_GET['direction'] ?? 'desc')));
    $dir        = $dirParam === 'asc' ? 'ASC' : 'DESC';
    // Support both 'q' and 'search' params
    $q          = trim((string)($_GET['q'] ?? ($_GET['search'] ?? '')));
    $status     = trim((string)($_GET['status'] ?? ''));
    $validity   = trim((string)($_GET['validity'] ?? ''));
    // Specific filters (e.g., stage for mahnungen)
    $stageParam = trim((string)($_GET['stage'] ?? ($_GET['stufe'] ?? '')));

    // Introspect columns
    $colsStmt = $pdo->prepare("SHOW COLUMNS FROM `$table`");
    $colsStmt->execute();
    $cols = $colsStmt->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_map(static function($r){ return (string)$r['Field']; }, $cols);

    // sortable mapping with fallback
    $sortable = (array)($cfg['sortable'] ?? []);
    // If mapping refers to non-existing fields, ignore
    foreach ($sortable as $k => $expr) {
        // keep exprs; only validate simple t.col style by checking presence
        if (preg_match('~^t\.(\w+)$~', $expr, $m)) {
            if (!in_array($m[1], $colNames, true)) unset($sortable[$k]);
        }
    }

    $orderBy = $sortable[$sortParam] ?? null;
    if ($orderBy === null) {
        // try direct column name
        if (in_array($sortParam, $colNames, true)) {
            $orderBy = 't.' . $sortParam;
        } elseif (in_array('created_at', $colNames, true)) {
            $orderBy = 't.created_at';
        } elseif (in_array($cfg['date_field'], $colNames, true)) {
            $orderBy = 't.' . $cfg['date_field'];
        } else {
            $orderBy = 't.' . $colNames[0];
        }
    }

    // Build WHERE
    $where  = [];
    $params = [];
    if ($q !== '') {
        $likeParts = build_like_filter($colNames, (array)$cfg['search_fields'], ':q');
        if ($likeParts) { $where[] = '(' . implode(' OR ', $likeParts) . ')'; $params[':q'] = '%' . $q . '%'; }
    }
    if ($status !== '' && in_array($cfg['status_field'], $colNames, true)) {
        // Map common status synonyms across languages for better UX
        $map = [
            'open'       => ['open','offen','draft','entwurf'],
            'offen'      => ['open','offen','draft','entwurf'],
            'paid'       => ['paid','bezahlt'],
            'bezahlt'    => ['paid','bezahlt'],
            'inkasso'    => ['inkasso','collection','collections','inkasso_buero','inkasso-buero'],
            'accepted'   => ['accepted','angenommen'],
            'angenommen' => ['accepted','angenommen'],
            'rejected'   => ['rejected','abgelehnt'],
            'abgelehnt'  => ['rejected','abgelehnt'],
            'expired'    => ['expired','abgelaufen'],
            'abgelaufen' => ['expired','abgelaufen'],
            'overdue'    => ['overdue','ueberfaellig','überfällig','mahnung'],
            'mahnung'    => ['overdue','ueberfaellig','überfällig','mahnung'],
        ];
        $statusLower = strtolower($status);
        $vals = $map[$statusLower] ?? [$status];
        // build IN clause with unique placeholders
        $placeholders = [];
        foreach ($vals as $i => $val) {
            $ph = ":status_$i";
            $placeholders[] = $ph;
            $params[$ph] = $val;
        }
        $where[] = 't.' . $cfg['status_field'] . ' IN (' . implode(',', $placeholders) . ')';
    }
    // Stage filter for Mahnungen (exact match on stufe)
    if ($stageParam !== '' && ($table === 'mahnungen') && in_array('stufe', $colNames, true)) {
        $where[] = 't.stufe = :stufe';
        $params[':stufe'] = (int)$stageParam;
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // COUNT
    $sqlCount = "SELECT COUNT(*) FROM `$table` t $whereSql";
    $stmt = $pdo->prepare($sqlCount);
    foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
    $stmt->execute();
    $total = (int)$stmt->fetchColumn();

    // Paging
    $offset = ($page - 1) * $perPage;

    // Build SELECT: all table cols + client name if join available
    $select = 't.*';
    $join = '';
    if (!empty($cfg['client_join']['join']) && !empty($cfg['client_join']['select'])) {
        $join = ' ' . $cfg['client_join']['join'] . ' ';
        $select .= ', ' . $cfg['client_join']['select'];
    }

    $sql = "SELECT $select FROM `$table` t $join $whereSql ORDER BY $orderBy $dir LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
    $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Post-process rows for convenience fields (faelligkeit_formatted)
    $dueField = $cfg['due_field'];
    if ($dueField && in_array($dueField, $colNames, true)) {
        foreach ($rows as &$r) {
            $val = $r[$dueField] ?? null;
            $r['faelligkeit_formatted'] = (!empty($val) && $val !== '0000-00-00') ? date('d.m.Y', strtotime((string)$val)) : '';
        }
        unset($r);
    }

    // Build UI-friendly aliases expected by some pages
    $numberField = $cfg['number_field'] ?? null;
    $items = [];
    foreach ($rows as $r) {
        $alias = $r;
        if ($numberField && isset($r[$numberField])) {
            $alias['nummer'] = $r[$numberField];
        }
        // Kunde name/email aliases from join
        if (isset($r['kunde']))       $alias['kunde_name']  = $r['kunde'];
        if (isset($r['kunde_email'])) $alias['kunde_email'] = $r['kunde_email'];
        // valid_until alias (Angebote use gueltig_bis)
        if (isset($r['gueltig_bis'])) {
            $alias['valid_until'] = $r['gueltig_bis'];
        } elseif ($dueField && isset($r[$dueField])) {
            $alias['valid_until'] = $r[$dueField];
        }
        $items[] = $alias;
    }

    // Pagination meta (in addition to legacy fields)
    $totalPages = (int)max(1, ceil($total / $perPage));
    $pagination = [
        'current_page' => $page,
        'per_page'     => $perPage,
        'total_items'  => $total,
        'total_pages'  => $totalPages,
    ];

    $response = [
        // Legacy keys used by older pages
        'data'      => $rows,
        'page'      => $page,
        'per_page'  => $perPage,
        'total'     => $total,
        // Newer keys used by enhanced list pages
        'items'     => $items,
        'pagination'=> $pagination,
    ];

    // Stats for known types
    if (in_array($table, ['rechnungen'], true)) {
        $sqlStats = "
            SELECT
                COUNT(*) AS gesamt,
                SUM(CASE WHEN t.status IN ('bezahlt','paid') THEN 1 ELSE 0 END) AS paid,
                SUM(CASE WHEN t.status IN ('offen','open','entwurf','draft') THEN 1 ELSE 0 END) AS offen,
                SUM(CASE WHEN t.status IN ('überfällig','ueberfaellig','overdue','mahnung') THEN 1 ELSE 0 END) AS overdue
            FROM `$table` t
        ";
        $stmtStats = $pdo->query($sqlStats);
        $statsRow  = $stmtStats->fetch(PDO::FETCH_ASSOC) ?: [
            'gesamt'  => 0,
            'paid'    => 0,
            'offen'   => 0,
            'overdue' => 0,
        ];
        $response['stats'] = [
            'gesamt'  => (int)($statsRow['gesamt'] ?? 0),
            'paid'    => (int)($statsRow['paid'] ?? 0),
            'offen'   => (int)($statsRow['offen'] ?? 0),
            'overdue' => (int)($statsRow['overdue'] ?? 0),
        ];
    } elseif (in_array($table, ['angebote'], true)) {
        $sqlStats = "
            SELECT
                COUNT(*) AS total_items,
                SUM(CASE WHEN t.status IN ('open','offen') THEN 1 ELSE 0 END) AS open,
                SUM(CASE WHEN t.status IN ('accepted','angenommen') THEN 1 ELSE 0 END) AS accepted,
                SUM(CASE WHEN t.status IN ('expired','abgelaufen') THEN 1 ELSE 0 END) AS expired
            FROM `$table` t
        ";
        $stmtStats = $pdo->query($sqlStats);
        $s = $stmtStats->fetch(PDO::FETCH_ASSOC) ?: ['total_items'=>0,'open'=>0,'accepted'=>0,'expired'=>0];
        $response['stats'] = [
            'total'    => (int)($s['total_items'] ?? 0),
            'open'     => (int)($s['open'] ?? 0),
            'accepted' => (int)($s['accepted'] ?? 0),
            'expired'  => (int)($s['expired'] ?? 0),
        ];
    }

    echo json_encode($response);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error'   => true,
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
}
