<?php
// pages/register.php  (seller registration)
// Allows creating the first admin (bootstrap) OR using an invite token.
// After initial setup you should disable public registration and use invite tokens or manual admin creation.
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once __DIR__ . '/../config/db.php';
if (isset($GLOBALS['pdo'])) { $pdo = $GLOBALS['pdo']; }

$errors = [];
$success = false;

// Check if users exist
$countUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$allowRegistration = ($countUsers === 0); // true if bootstrap
// Optionally allow with invite token
$inviteCode = trim((string)($_GET['invite'] ?? $_POST['invite'] ?? ''));

// Read public registration flag from config/app-config.php if available
$allowPublic = false;
try {
    $appCfgPath = __DIR__ . '/../config/app-config.php';
    if (is_file($appCfgPath)) {
        $retCfg = include $appCfgPath; // may define CRMConfig
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

if ($allowPublic) {
    $allowRegistration = true;
}

// Resolve invite secret from multiple sources (env -> config/invite.secret -> config/app-config.php)
$inviteSecret = getenv('INVITE_SECRET') ?: '';
$secretFile = __DIR__ . '/../config/invite.secret';
if ($inviteSecret === '' && is_file($secretFile)) {
    $inviteSecret = trim((string)@file_get_contents($secretFile));
}
// Try config/app-config.php: it may return an array or define CRMConfig class
$appCfgPath = __DIR__ . '/../config/app-config.php';
if ($inviteSecret === '' && is_file($appCfgPath)) {
    $ret = include $appCfgPath; // may return array or just load class
    if (is_array($ret)) {
        $inviteSecret = (string)($ret['invite_secret'] ?? ($ret['auth']['invite_secret'] ?? ''));
    }
    if ($inviteSecret === '' && class_exists('CRMConfig')) {
        // Optional: if CRMConfig::$auth['invite_secret'] exists
        try {
            $auth = \CRMConfig::$auth ?? null;
            if (is_array($auth) && !empty($auth['invite_secret'])) {
                $inviteSecret = (string)$auth['invite_secret'];
            }
        } catch (Throwable $e) { /* ignore */ }
    }
}

$allowByInvite = false;
if ($inviteCode !== '') {
    if ($inviteSecret !== '' && hash_equals($inviteSecret, $inviteCode)) {
        $allowByInvite = true;
    } else {
        // Optional one-time token file at data/invites.json: [{"token":"...","expires_at":"2026-12-31T23:59:59Z","used":false}]
        $invFile = __DIR__ . '/../data/invites.json';
        if (is_file($invFile) && is_readable($invFile)) {
            $json = @file_get_contents($invFile);
            $list = json_decode((string)$json, true);
            if (is_array($list)) {
                $now = time();
                foreach ($list as $idx => $entry) {
                    $tok = (string)($entry['token'] ?? '');
                    $used = !empty($entry['used']);
                    $exp  = (string)($entry['expires_at'] ?? '');
                    $expTs = $exp ? strtotime($exp) : null;
                    $validExp = ($expTs === null || $expTs === false) ? true : ($expTs >= $now);
                    if ($inviteCode === $tok && !$used && $validExp) {
                        $allowByInvite = true;
                        // Mark as used (best-effort)
                        $list[$idx]['used'] = true;
                        @file_put_contents($invFile, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                        break;
                    }
                }
            }
        }
    }
}

if (!$allowRegistration && $allowByInvite) {
    $allowRegistration = true;
}

if (!$allowRegistration) {
    // show a small message; admin must create users directly in DB or use invite
    if ($inviteCode !== '') {
        $errors[] = 'Invite-Token ungültig oder abgelaufen.';
    } else {
        $errors[] = 'Registrierung deaktiviert. Erstellen Sie zunächst einen Benutzer über die Datenbank oder verwenden Sie ein gültiges Invite-Token.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $allowRegistration) {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $pass  = (string)($_POST['password'] ?? '');
    $name  = trim((string)($_POST['name'] ?? ''));
    $company = trim((string)($_POST['company'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Bitte gültige E-Mail.';
    if (strlen($pass) < 8) $errors[] = 'Passwort muss mindestens 8 Zeichen haben.';
    if (empty($errors)) {
        // check existing
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email'=>$email]);
        if ($stmt->fetch()) {
            $errors[] = 'E-Mail bereits registriert.';
        } else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            // first user becomes admin
            $role = ($countUsers === 0) ? 'admin' : 'seller';
            $ins = $pdo->prepare("INSERT INTO users (username,email,password_hash,name,company,role) VALUES (:username,:email,:hash,:name,:company,:role)");
            $ins->execute([
                ':username' => $username ?: null,
                ':email'    => $email,
                ':hash'     => $hash,
                ':name'     => $name ?: null,
                ':company'  => $company ?: null,
                ':role'     => $role
            ]);
            $success = true;
        }
    }
}
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Registrierung (Seller)</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light">
<div class="container py-5"><div class="row justify-content-center"><div class="col-md-6">
            <div class="card"><div class="card-body">
                    <h4><?= $countUsers===0 ? 'Initial Admin anlegen' : ($allowPublic ? 'Benutzer registrieren' : 'Seller Registrierung (Invite)') ?></h4>

                    <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err) echo '<li>'.htmlspecialchars($err).'</li>'; ?></ul></div><?php endif; ?>
                    <?php if ($success): ?><div class="alert alert-success">Benutzer angelegt. <a href="login.php">Login</a></div><?php endif; ?>

                    <?php if ($allowRegistration && !$success): ?>
                        <form method="post">
                            <div class="mb-2"><label class="form-label">Name</label><input name="name" class="form-control" value="<?=htmlspecialchars($_POST['name'] ?? '')?>"></div>
                            <div class="mb-2"><label class="form-label">Firma (optional)</label><input name="company" class="form-control" value="<?=htmlspecialchars($_POST['company'] ?? '')?>"></div>
                            <div class="mb-2"><label class="form-label">E-Mail</label><input name="email" type="email" required class="form-control" value="<?=htmlspecialchars($_POST['email'] ?? '')?>"></div>
                            <div class="mb-2"><label class="form-label">Passwort</label><input name="password" type="password" required class="form-control"></div>
                            <div class="mb-2"><label class="form-label">Benutzername (optional)</label><input name="username" class="form-control" value="<?=htmlspecialchars($_POST['username'] ?? '')?>"></div>
                            <?php if ($countUsers>0 && !$allowPublic): ?><input type="hidden" name="invite" value="<?=htmlspecialchars($inviteCode)?>"><?php endif; ?>
                            <div class="d-flex justify-content-between"><button class="btn btn-primary">Registrieren</button><a href="login.php" class="btn btn-link">Login</a></div>
                        </form>
                    <?php endif; ?>

                </div></div>
        </div></div></div>
</body></html>
