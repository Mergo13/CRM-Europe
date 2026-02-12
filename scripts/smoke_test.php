<?php
// scripts/smoke_test.php
// Minimal environment readiness checks. No DB writes. Safe to run on any host.

declare(strict_types=1);

function ok(string $msg): void { echo "[OK]  $msg\n"; }
function warn(string $msg): void { echo "[WARN] $msg\n"; }
function fail(string $msg): void { echo "[ERR] $msg\n"; }

// PHP version
$ver = PHP_VERSION;
if (version_compare($ver, '8.1.0', '>=')) { ok("PHP >= 8.1 ($ver)"); } else { fail("PHP 8.1+ required. Current: $ver"); }

// Extensions
$exts = ['pdo','iconv'];
$missing = array_filter($exts, fn($e) => !extension_loaded($e));
if (empty($missing)) { ok("Required PHP extensions present: " . implode(', ', $exts)); }
else { fail("Missing extensions: " . implode(', ', $missing)); }

// Composer autoload + mpdf
try {
    require __DIR__ . '/../vendor/autoload.php';
    if (class_exists('Mpdf\\Mpdf')) { ok('mpdf available via Composer'); }
    else { warn('mpdf not found; run composer install'); }
} catch (Throwable $e) {
    fail('vendor/autoload.php missing; run composer install');
}

// Helpers
try {
    require __DIR__ . '/../includes/helpers.php';
    if (function_exists('page_url')) { ok('includes/helpers.php loaded; page_url() available'); }
    else { warn('helpers loaded but page_url() not found'); }
} catch (Throwable $e) {
    fail('Failed to load includes/helpers.php: ' . $e->getMessage());
}

// Writable directories
$dirs = [
    realpath(__DIR__ . '/../logs') ?: (__DIR__ . '/../logs'),
    realpath(__DIR__ . '/../pdf') ?: (__DIR__ . '/../pdf'),
    realpath(__DIR__ . '/../pdf/rechnungen') ?: (__DIR__ . '/../pdf/rechnungen'),
];
foreach ($dirs as $d) {
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    $test = rtrim($d, '/').'/.__writetest';
    $ok = @file_put_contents($test, (string)time());
    if ($ok !== false) { ok('Writable: ' . $d); @unlink($test); }
    else { warn('Not writable: ' . $d); }
}

// Optional DB reachability (disabled by default)
if (getenv('SMOKE_DB') === '1') {
    try {
        @require __DIR__ . '/../config/db.php';
        if (isset($pdo) && $pdo instanceof PDO) {
            $pdo->query('SELECT 1');
            ok('DB reachable');
        } else {
            warn('DB not configured or $pdo not available');
        }
    } catch (Throwable $e) {
        warn('DB not configured: ' . $e->getMessage());
    }
}

$env = getenv('APP_ENV') ?: 'development (default)';
ok('APP_ENV = ' . $env);

echo "Smoke test completed.\n";
