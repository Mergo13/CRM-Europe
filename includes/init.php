<?php
// includes/init.php
// Central initializer: session, debugging, autoload, PDO, helpers.
// Usage: require_once __DIR__ . '/includes/init.php'; (adjust path as needed)

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// load config if present
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

// try to load Composer autoloader if present
$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

// set debug / error reporting based on APP_DEBUG
if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', true);
}
if (APP_DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

// global debug logger (writes to logs/debug.log)
function debug_log($msg) {
    $logdir = __DIR__ . '/../logs';
    if (!is_dir($logdir)) @mkdir($logdir, 0755, true);
    $date = date('Y-m-d H:i:s');
    $entry = "[$date] " . (is_string($msg) ? $msg : json_encode($msg, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)) . PHP_EOL;
    @file_put_contents($logdir . '/debug.log', $entry, FILE_APPEND | LOCK_EX);
}

// asset prefix detection (auto-adjust for subfolder installs)
$assetPrefix = defined('ASSET_PREFIX') ? ASSET_PREFIX : '/';

if (empty($assetPrefix) || $assetPrefix === '/') {
    // derive from SCRIPT_NAME (useful if app is in a subfolder)
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $assetPrefix = ($scriptDir === '' ? '/' : $scriptDir . '/');
}


// Establish PDO connection with fallbacks
// Respect an existing global $pdo (e.g., provided by config/db.php)
$pdo = $GLOBALS['pdo'] ?? null;
$pdo_error = null;

if (!($pdo instanceof PDO)) {
    try {
        $dsn = getenv('DB_DSN') ?: (defined('DB_DSN') ? DB_DSN : '');
        $user = getenv('DB_USER') ?: (defined('DB_USER') ? DB_USER : '');
        $pass = getenv('DB_PASS') ?: (defined('DB_PASS') ? DB_PASS : '');

        if ($dsn) {
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $pdo = new PDO($dsn, $user, $pass, $options);
        } else {
            // sqlite fallback inside data folder (outside webroot if you move it)
            $sqlitePath = __DIR__ . '/../data/app.sqlite';
            if (!is_dir(dirname($sqlitePath))) @mkdir(dirname($sqlitePath), 0755, true);
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ];
            $pdo = new PDO('sqlite:' . $sqlitePath, null, null, $options);
            // create minimal users table if missing
            $pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY,
        username TEXT NOT NULL UNIQUE,
        email TEXT,
        role TEXT DEFAULT 'user',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
");
        }

        // expose as global for consumers
        $GLOBALS['pdo'] = $pdo;
    } catch (Throwable $e) {
        $pdo_error = $e->getMessage();
        $pdo = null;
        if (defined('APP_DEBUG') && APP_DEBUG) {
            debug_log('PDO init error: ' . $pdo_error);
        }
    }
}

// helper functions
if (!function_exists('has_pdo')) {
    function has_pdo(): bool {
        global $pdo;
        return ($pdo instanceof PDO);
    }
}

// ---------------- Replace current_user block with this canonical version ----------------
if (!function_exists('current_user')) {
    /**
     * Return currently logged-in user or null.
     * Accepts optional PDO instance; otherwise uses global $pdo.
     */
    function current_user(?PDO $provided = null): ?array {
        // ensure session started
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        // pick PDO
        $db = $provided ?? ($GLOBALS['pdo'] ?? null);
        if (!($db instanceof PDO)) {
            // no DB available
            return null;
        }

        // determine user id: session first, then remember-me helper if available
        $uid = null;
        if (!empty($_SESSION['user_id'])) {
            $uid = (int) $_SESSION['user_id'];
        } elseif (function_exists('check_remember_me')) {
            try {
                $maybe = check_remember_me($db);
                $uid = $maybe ? (int) $maybe : null;
            } catch (Throwable $e) {
                if (defined('APP_DEBUG') && APP_DEBUG && function_exists('debug_log')) {
                    debug_log('check_remember_me error: ' . $e->getMessage());
                }
                $uid = null;
            }
        }

        if (!$uid) {
            return null;
        }

        try {
            $stmt = $db->prepare('SELECT id, username, name, email, role, is_active FROM users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $uid]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) return null;
            if (isset($user['is_active']) && !$user['is_active']) return null;
            return $user;
        } catch (Throwable $e) {
            if (defined('APP_DEBUG') && APP_DEBUG && function_exists('debug_log')) {
                debug_log('current_user error: ' . $e->getMessage());
            }
            return null;
        }
    }
}
// -----------------------------------------------------------------------------------------


// html escape helper
if (!function_exists('h')) {
    function h($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

// asset helper to build correct URLs
if (!function_exists('asset')) {
    function asset($path) {
        global $assetPrefix;
        $prefix = $assetPrefix ?? '/';
        return rtrim($prefix, '/') . '/' . ltrim($path, '/');
    }
}

// expose debug info helper
function debug_info(): array {
    global $pdo, $pdo_error, $assetPrefix;
    return [
        'assetPrefix' => $assetPrefix,
        'has_pdo' => ($pdo instanceof PDO),
        'pdo_error' => $pdo_error,
        'app_debug' => defined('APP_DEBUG') ? APP_DEBUG : null,
        'script_name' => $_SERVER['SCRIPT_NAME'] ?? null,
    ];
}
