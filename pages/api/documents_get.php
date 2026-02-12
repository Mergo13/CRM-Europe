<?php
// pages/api/documents_get.php
// Generic GET endpoint for single document across types

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/docs_common.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // Ensure PDO instance
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('No PDO instance');
    }

    // Determine doc type and id
    $docType = isset($DOC_TYPE) ? (string)$DOC_TYPE : (string)($_GET['doc_type'] ?? 'rechnungen');
    $cfg = doc_type_config($docType);
    $table = $cfg['table'];
    $pk = $cfg['pk'] ?? 'id';

    $id = $_GET['id'] ?? null;
    if ($id === null || $id === '') {
        http_response_code(400);
        echo json_encode(['error' => 'missing_id']);
        exit;
    }

    // Introspect columns
    $colsStmt = $pdo->prepare("SHOW COLUMNS FROM `$table`");
    $colsStmt->execute();
    $cols = $colsStmt->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_map(static function($r){ return (string)$r['Field']; }, $cols);

    $selectCols = implode(', ', array_map(static function($c){ return "`$c`"; }, $colNames));
    $stmt = $pdo->prepare("SELECT $selectCols FROM `$table` WHERE `$pk` = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'not_found']);
        exit;
    }

    $row = (array)$row;

    // Derive file_url if a file-ish column exists
    $row['file_url'] = null;
    foreach ($colNames as $c) {
        $lc = strtolower($c);
        if (in_array($lc, ['file','filename','file_name','file_path','filepath','attachment','pdf','document','file_url','path','url'], true)) {
            $val = $row[$c] ?? '';
            if ($val) {
                if (preg_match('#^https?://#i', (string)$val)) {
                    $row['file_url'] = $val;
                } elseif (is_string($val) && strlen($val) > 0 && $val[0] === '/') {
                    $row['file_url'] = $val;
                } else {
                    $row['file_url'] = '/uploads/' . ltrim((string)$val, '/');
                }
            }
            break;
        }
    }

    // Preserve existing behavior: for invoices provide a computed PDF URL
    if ($table === 'rechnungen') {
        $row['pdf'] = '/pages/rechnung_pdf.php?id=' . urlencode((string)$id);
    }

    echo json_encode($row, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => $e->getMessage(),
    ]);
}
