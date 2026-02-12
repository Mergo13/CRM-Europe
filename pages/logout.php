<?php
// pages/logout.php  — robust logout (no header included)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// avoid header auto-redirect if header is later included
if (!defined('NO_AUTH_REDIRECT')) define('NO_AUTH_REDIRECT', true);

// include config and auth helpers
$dbFile = __DIR__ . '/../config/db.php';
$authFile = __DIR__ . '/../includes/auth.php';

if (!file_exists($dbFile)) {
    echo "Missing DB config: {$dbFile}";
    exit;
}
require_once $dbFile;

if (!file_exists($authFile)) {
    echo "Missing auth helper: {$authFile}";
    exit;
}
require_once $authFile;

// If we have a logged-in session, try to remove remember tokens for that user;
// if not, still try to remove token from cookie (defensive)
try {
    // If session user exists, remove all tokens for that user to be safe
    if (!empty($_SESSION['user_id']) && isset($pdo)) {
        $stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE user_id = :uid");
        $stmt->execute([':uid' => (int)$_SESSION['user_id']]);
    }

    // If there's a selector in the cookie, delete that token too
    if (!empty($_COOKIE['remember'])) {
        $parts = explode(':', $_COOKIE['remember'], 2);
        $selector = $parts[0] ?? null;
        if ($selector && isset($pdo)) {
            $stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE selector = :sel");
            $stmt->execute([':sel' => $selector]);
        }
    }
} catch (Throwable $e) {
    // log or ignore; we will continue with cookie/session cleanup to ensure logout
    error_log("Logout cleanup warning: " . $e->getMessage());
}

// Clear PHP session safely
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
@session_destroy();

// Clear the remember cookie — must match path/domain used when setting it
// Use same attributes as in includes/auth.php login_user() for compatibility.
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
setcookie('remember', '', [
    'expires' => time() - 3600,
    'path' => '/',
    'domain' => '',    // if you set a domain explicitly when creating the cookie, use it here
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax'
]);

// Final redirect to login page (use absolute-safe path)
$script = str_replace('\\','/', $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
if (strpos($script, '/pages/') !== false) {
    $base = substr($script, 0, strpos($script, '/pages'));
} else {
    $dir = rtrim(dirname($script), '/');
    $base = ($dir === '/' ? '' : $dir);
}
$login = ($base === '') ? '/pages/login.php' : $base . '/pages/login.php';

header('Location: ' . $login);
exit;
