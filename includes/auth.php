<?php
// includes/auth.php
// Auth helper for your app (seller accounts).
// - Place this file at Rechnung-app/includes/auth.php
// - Expects ../config/db.php to have been included earlier or to be available as $pdo
// - Provides: login_user($pdo,$user_id,$remember), logout_user($pdo), check_remember_me($pdo), current_user($pdo)
// - Uses selector:validator pattern for "remember me" tokens stored in remember_tokens table.
//
// Security notes:
// - Use HTTPS in production so cookies are Secure.
// - Remember tokens are rotated on use (prevents replay).
// - Make sure users & remember_tokens tables exist (see earlier SQL).

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Log a user in and optionally set a long-lived remember cookie.
 * @param PDO $pdo
 * @param int $user_id
 * @param bool $remember
 * @return void
 */
function login_user(PDO $pdo, int $user_id, bool $remember = false): void {
    // regenerate session id
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user_id;

    if ($remember) {
        // selector (16 bytes hex), validator (32 bytes hex)
        $selector = bin2hex(random_bytes(16)); // 32 chars
        $validator = bin2hex(random_bytes(32)); // 64 chars
        $validator_hash = hash('sha256', $validator);
        $expires = (new DateTime('+30 days'))->format('Y-m-d H:i:s');

        // store in DB
        $stmt = $pdo->prepare("INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (:uid, :sel, :vhash, :exp)");
        $stmt->execute([
            ':uid' => $user_id,
            ':sel' => $selector,
            ':vhash' => $validator_hash,
            ':exp' => $expires
        ]);

        // set cookie: selector:validator
        $cookieValue = $selector . ':' . $validator;
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        // httponly true to avoid JS access, samesite Lax
        setcookie('remember', $cookieValue, [
            'expires' => time() + 60*60*24*30,
            'path' => '/',
            'domain' => '', // default (current host). set if needed.
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
}

/**
 * Logout current user and remove remember cookie/token.
 * @param PDO $pdo
 * @return void
 */
function logout_user(PDO $pdo): void {
    // remove remember token from DB if present
    if (!empty($_COOKIE['remember'])) {
        $parts = explode(':', $_COOKIE['remember'], 2);
        $selector = $parts[0] ?? null;
        if ($selector) {
            $stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE selector = :sel");
            $stmt->execute([':sel' => $selector]);
        }
        // clear cookie
        setcookie('remember', '', time() - 3600, '/', '', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), true);
    }

    // destroy session
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

/**
 * Try to restore a login via remember cookie.
 * If successful: rotates token, sets session and returns user_id.
 * If not: returns null.
 * @param PDO $pdo
 * @return int|null
 */
function check_remember_me(PDO $pdo): ?int {
    if (!empty($_SESSION['user_id'])) return (int)$_SESSION['user_id'];
    if (empty($_COOKIE['remember'])) return null;

    $cookie = $_COOKIE['remember'];
    if (strpos($cookie, ':') === false) return null;
    list($selector, $validator) = explode(':', $cookie, 2);
    if (!$selector || !$validator) return null;

    // fetch token by selector
    $stmt = $pdo->prepare("SELECT id, user_id, validator_hash, expires_at FROM remember_tokens WHERE selector = :sel LIMIT 1");
    $stmt->execute([':sel' => $selector]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    // check expiry
    try {
        $now = new DateTime();
        if (new DateTime($row['expires_at']) < $now) {
            // expired: delete token and return
            $del = $pdo->prepare("DELETE FROM remember_tokens WHERE selector = :sel");
            $del->execute([':sel' => $selector]);
            return null;
        }
    } catch (Throwable $e) {
        // if date parsing fails, be safe and deny
        $del = $pdo->prepare("DELETE FROM remember_tokens WHERE selector = :sel");
        $del->execute([':sel' => $selector]);
        return null;
    }

    // verify validator (hash)
    $calc = hash('sha256', $validator);
    if (!hash_equals($row['validator_hash'], $calc)) {
        // possible theft or tampering: delete token(s) for this selector
        $del = $pdo->prepare("DELETE FROM remember_tokens WHERE selector = :sel");
        $del->execute([':sel' => $selector]);
        return null;
    }

    // valid: rotate token (delete old, insert new)
    $del = $pdo->prepare("DELETE FROM remember_tokens WHERE id = :id");
    $del->execute([':id' => $row['id']]);

    $newSelector = bin2hex(random_bytes(16));
    $newValidator = bin2hex(random_bytes(32));
    $newHash = hash('sha256', $newValidator);
    $expires = (new DateTime('+30 days'))->format('Y-m-d H:i:s');
    $ins = $pdo->prepare("INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (:uid, :sel, :h, :exp)");
    $ins->execute([':uid' => $row['user_id'], ':sel' => $newSelector, ':h' => $newHash, ':exp' => $expires]);

    // set cookie
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('remember', $newSelector . ':' . $newValidator, [
        'expires' => time() + 60*60*24*30,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    // set session
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$row['user_id'];

    return (int)$row['user_id'];
}

/**
 * Return current user data or null.
 * Attempts session first, then remember cookie.
 * @param PDO $pdo
 * @return array|null
 */
if (!function_exists('current_user')) {
    function current_user(PDO $pdo): ?array {

    $uid = null;
    if (!empty($_SESSION['user_id'])) {
        $uid = (int)$_SESSION['user_id'];
    } else {
        $uid = check_remember_me($pdo);
    }
    if (!$uid) return null;

    $stmt = $pdo->prepare("SELECT id, email, name, role, is_active FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $uid]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) return null;
    // optional: ensure active and role allowed
    if (isset($user['is_active']) && !$user['is_active']) return null;
    return $user;
}}
