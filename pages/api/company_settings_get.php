<?php
// pages/api/company_settings_get.php
// Returns the single company settings row used for reusable PDF headers/footers.
// Table: settings_company (id PK)

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/../../config/db.php';

    // Ensure PDO exists before any auth calls
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        if (!isset($dsn, $db_user, $db_pass)) throw new Exception('DB connection not available');
        $pdo = new PDO($dsn, $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    // Optional auth (require login in production only)
    $auth = __DIR__ . '/../../includes/auth.php';
    if (is_file($auth)) require_once $auth;
    $envDev = isset($is_dev) ? $is_dev : in_array(strtolower((string)(getenv('APP_ENV') ?: 'development')), ['dev','local','development'], true);
    if (function_exists('current_user') && !$envDev) {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $user = current_user($pdo);
        if (!$user) { http_response_code(401); echo json_encode(['success'=>false,'error'=>'Unauthorized']); return; }
    }

    // Ensure there is at least a default row (id=1)
    $pdo->exec("INSERT INTO settings_company (id, company_name) VALUES (1, 'Ihre Firma') ON DUPLICATE KEY UPDATE company_name = company_name");

    // Fetch settings; select all to be tolerant to extra/missing columns
    try {
        $stmt = $pdo->prepare('SELECT * FROM settings_company WHERE id = 1');
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $row = null;
    }

    if (!$row) {
        $row = [
            'id' => 1,
            'company_name' => 'Ihre Firma',
            'address_line1' => null,
            'address_line2' => null,
            'phone' => null,
            'email' => null,
            'website' => null,
            'vat' => null,
            'iban' => null,
            'bic' => null,
            'logo_path' => null,
            'updated_at' => null,
        ];
    }

    // Map legacy single address/tax fields if newer fields missing
    if (!isset($row['address_line1']) && isset($row['address'])) {
        $row['address_line1'] = $row['address'];
    }
    if (!isset($row['vat']) && isset($row['tax_id'])) {
        $row['vat'] = $row['tax_id'];
    }

    // Merge extras from data/company_extra.json for fields that may not exist as DB columns
    try {
        $extraPath = __DIR__ . '/../../data/company_extra.json';
        if (is_file($extraPath) && is_readable($extraPath)) {
            $extra = json_decode((string)file_get_contents($extraPath), true) ?: [];
            foreach (['bank_name','iban','bic','logo_path','address_line1','address_line2','website','creditor_name','vat','phone','email','company_name'] as $k) {
                if (!isset($row[$k]) || $row[$k] === null || $row[$k] === '') {
                    if (array_key_exists($k, $extra)) $row[$k] = $extra[$k];
                }
            }
        }
    } catch (Throwable $e) { /* ignore */ }

    // Ensure keys exist in response
    foreach (['bank_name','company_name','creditor_name','address_line1','address_line2','phone','email','website','vat','iban','bic','logo_path','updated_at'] as $k) {
        if (!array_key_exists($k, $row)) $row[$k] = ($k === 'company_name' ? 'Ihre Firma' : null);
    }

    echo json_encode(['success' => true, 'data' => $row]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
