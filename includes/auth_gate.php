<?php

declare(strict_types=1);

/**
 * Auth gate:
 * - If no users exist yet: allow register page (redirect others to register)
 * - Else if not logged in: send to login page
 * - Exempt auth pages (login/register/logout) and when NO_AUTH_REDIRECT is defined
 * - Else: allow request
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Respect pages that explicitly disable auth redirect (e.g., login)
if (defined('NO_AUTH_REDIRECT') && NO_AUTH_REDIRECT === true) {
    return; // do not enforce auth on this request
}

function redirectTo(string $path): never
{
    // Normalize to absolute-path redirect to avoid /api/... prefix loops
    if ($path !== '' && $path[0] !== '/' && !preg_match('~^https?://~i', $path)) {
        $path = '/' . $path;
    }

    header('Location: ' . $path, true, 302);
    exit;
}

function ag_is_logged_in(): bool
{
    return !empty($_SESSION['user_id']); // adjust to your session key
}

function ag_has_any_user(PDO $pdo): bool
{
    $stmt = $pdo->query('SELECT 1 FROM users LIMIT 1');
    return (bool)$stmt->fetchColumn();
}

// Determine current script and base path (works in subdirectories)
$script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$base   = rtrim(str_replace('\\', '/', dirname($script)), '/');
$loginPath    = ($base === '' ? '' : $base) . '/pages/login.php';
$registerPath = ($base === '' ? '' : $base) . '/pages/register.php';
$logoutPath   = ($base === '' ? '' : $base) . '/pages/logout.php';

// Allow auth pages without enforcement
$uri = (string)($_SERVER['REQUEST_URI'] ?? '');
if (str_ends_with($script, '/pages/login.php') || str_ends_with($script, '/pages/register.php') || str_ends_with($script, '/pages/logout.php')) {
    return;
}

// Ensure we have a PDO; try to load it if missing
if (!isset($pdo) || !($pdo instanceof PDO)) {
    // Best effort: try to include db.php from project config
    $dbFile = __DIR__ . '/../config/db.php';
    if (is_file($dbFile)) {
        try { require_once $dbFile; } catch (Throwable $e) { /* ignore */ }
    }
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    // If DB is still unavailable, do not block installation pages (register/login already allowed)
    // Fail open to avoid white screens during setup
    return;
}

// Read public registration flag from app config
$allowPublic = false;
try {
    $appCfgPath = __DIR__ . '/../config/app-config.php';
    if (is_file($appCfgPath)) {
        $retCfg = include $appCfgPath; // may define CRMConfig or return array
        if (class_exists('CRMConfig')) {
            $cfgAuth = \CRMConfig::$auth ?? null;
            if (is_array($cfgAuth) && isset($cfgAuth['allow_public_registration'])) {
                $allowPublic = (bool)$cfgAuth['allow_public_registration'];
            }
        } elseif (is_array($retCfg) && isset($retCfg['allow_public_registration'])) {
            $allowPublic = (bool)$retCfg['allow_public_registration'];
        }
    }
} catch (Throwable $e) { /* ignore */ }

// If no registered users exist yet => allow access but redirect non-register pages to register
try {
    if (!ag_has_any_user($pdo)) {
        if (!str_ends_with($script, '/pages/register.php')) {
            ag_redirect($registerPath);
        }
        return; // allow register page
    }
} catch (Throwable $e) {
    // If users table missing or query fails, assume setup phase and allow register
    if (!str_ends_with($script, '/pages/register.php')) {
        ag_redirect($registerPath);
    }
    return;
}

// If users exist: optionally allow public registration page without login
if ($allowPublic && str_ends_with($script, '/pages/register.php')) {
    return; // allow reaching register directly
}

// Otherwise require login for all other pages
if (!ag_is_logged_in()) {
    ag_redirect($loginPath);
}