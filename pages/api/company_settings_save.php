<?php
// pages/api/company_settings_save.php
// Saves the company settings (id=1) from JSON or form data.

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/../../config/db.php';
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        if (!isset($dsn, $db_user, $db_pass)) throw new Exception('DB connection not available');
        $pdo = new PDO($dsn, $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    // Parse input (JSON or form-urlencoded)
    $raw = file_get_contents('php://input') ?: '';
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    $data = [];
    if ($raw && stripos($ct, 'application/json') !== false) {
        $data = json_decode($raw, true) ?: [];
    }
    if (!$data) { $data = $_POST; }

    $fields = [
        'company_name','creditor_name','address_line1','address_line2','phone','email','website','vat','iban','bic','logo_path'
    ];

    $vals = [];
    foreach ($fields as $f) {
        $vals[$f] = isset($data[$f]) ? trim((string)$data[$f]) : null;
        if ($vals[$f] === '') $vals[$f] = null;
    }

    // Ensure base row exists
    $pdo->exec("INSERT INTO settings_company (id, company_name) VALUES (1, 'Ihre Firma') ON DUPLICATE KEY UPDATE company_name = company_name");

    // Build UPDATE statement for known columns
    $setParts = [];
    $params = [];
    foreach ($fields as $f) {
        $setParts[] = "$f = :$f";
        $params[":$f"] = $vals[$f];
    }
    $setSql = implode(', ', $setParts) . ', updated_at = NOW()';

    $sql = "UPDATE settings_company SET $setSql WHERE id = 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Persist optional extras (bank_name) to data/company_extra.json without requiring DB schema changes
    try {
        $extraPath = __DIR__ . '/../../data/company_extra.json';
        $dir = dirname($extraPath);
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        $existing = [];
        if (is_file($extraPath) && is_readable($extraPath)) {
            $existing = json_decode((string)file_get_contents($extraPath), true) ?: [];
        }
        if (isset($data['bank_name'])) {
            $existing['bank_name'] = trim((string)$data['bank_name']);
        }
        // Only write if we have something to store
        if (!empty($existing)) {
            file_put_contents($extraPath, json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
    } catch (Throwable $e) {
        // ignore write errors; core settings are already saved in DB
    }

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
