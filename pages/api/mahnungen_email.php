<?php
// pages/api/mahnungen_email.php - send a simple email containing a link to the Mahnung PDF
// Input (POST): id (mahnung id), to (email)

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
header('Content-Type: application/json; charset=utf-8');

$pdo = $GLOBALS['pdo'] ?? (isset($pdo) ? $pdo : null);
if (!($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'no_db']);
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$to = filter_var($_POST['to'] ?? '', FILTER_VALIDATE_EMAIL);
if ($id <= 0 || !$to) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'missing_params']);
    exit;
}

try {
    $table = 'mahnungen';
    $colsStmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
    $cols = $colsStmt->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_map(static function ($c) { return (string)$c['Field']; }, $cols);

    // Build a dynamic SELECT over all columns
    $selectCols = implode(', ', array_map(static function ($c) { return "`" . str_replace("`", "", $c) . "`"; }, $colNames));
    $stmt = $pdo->prepare("SELECT {$selectCols} FROM `{$table}` WHERE `id` = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'not_found']);
        exit;
    }

    // Try to detect a usable PDF URL/path
    $pdf = null;
    foreach ($colNames as $c) {
        $lc = strtolower($c);
        if (in_array($lc, ['pdf_path','pdf','file','filename','file_name','file_path','filepath','attachment','document','file_url','path','url'], true)) {
            $v = $row[$c] ?? '';
            if ($v !== '') {
                if (preg_match('#^https?://#i', $v)) {
                    $pdf = $v;
                } elseif ($v[0] === '/') {
                    $pdf = $v;
                } else {
                    $pdf = '/uploads/' . ltrim($v, '/');
                }
                break;
            }
        }
    }

    // Fallback: construct generator URL
    if ($pdf === null) {
        $pdf = '/pages/mahnung_pdf.php?id=' . $id;
    }

    $subject = 'Mahnung-Dokument';
    $message = "Hier ist Ihr Dokument: " . $pdf;
    $headers = 'From: noreply@example.com' . "\r\n";

    $sent = @mail($to, $subject, $message, $headers);
    if ($sent) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'mail_failed']);
    }
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server', 'message' => $e->getMessage()]);
    exit;
}
