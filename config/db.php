<?php
// config/db.php
declare(strict_types=1);

// -------------------------------------------------
// Optional .env loader (no external dependencies)
// -------------------------------------------------
(function () {
    $envPath = __DIR__ . '/../.env';
    if (!is_file($envPath)) {
        $envPath = dirname(__DIR__) . '/.env';
    }

    if (!is_file($envPath)) {
        return;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (!str_contains($line, '=')) continue;

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        // Remove surrounding quotes
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        putenv("$key=$value");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
})();

// -------------------------------------------------
// Environment detection
// -------------------------------------------------
$app_env = strtolower(getenv('APP_ENV') ?: 'development');
$is_dev  = in_array($app_env, ['dev', 'local', 'development'], true);

ini_set('display_errors', $is_dev ? '1' : '0');
error_reporting($is_dev ? E_ALL : (E_ALL & ~E_DEPRECATED & ~E_NOTICE));

// -------------------------------------------------
// Database configuration
// -------------------------------------------------
$dsn_env = getenv('DB_DSN') ?: '';

$host    = getenv('DB_HOST') ?: '127.0.0.1';
$port    = getenv('DB_PORT') ?: '3306';
$dbname  = getenv('DB_NAME') ?: (getenv('DB_DATABASE') ?: 'crm_app');
$db_user = getenv('DB_USER') ?: (getenv('DB_USERNAME') ?: 'root');
$db_pass = getenv('DB_PASS') ?: (getenv('DB_PASSWORD') ?: '');
$charset = getenv('DB_CHARSET') ?: 'utf8mb4';

// Build DSN (forces utf8mb4)
$dsn = $dsn_env !== ''
    ? $dsn_env
    : "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

// -------------------------------------------------
// PDO options (clean & correct)
// -------------------------------------------------
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// -------------------------------------------------
// Create PDO instance
// -------------------------------------------------
try {
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);

    // Optional extra safety for older MySQL setups
    $pdo->exec("SET NAMES utf8mb4");

    $GLOBALS['pdo'] = $pdo;
    return $pdo;

} catch (Throwable $e) {

    error_log('DB connect failed: ' . $e->getMessage());

    throw new RuntimeException(
        'Database connection failed. See server logs for details.'
    );
}
