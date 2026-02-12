<?php
// pages/login.php — robust login (replace existing file)
global $pdo;
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Prevent header auto redirect if header later included
if (!defined('NO_AUTH_REDIRECT')) define('NO_AUTH_REDIRECT', true);

// load helpers and db/auth
require_once __DIR__ . '/../includes/helpers.php'; // must exist (we created earlier)
require_once __DIR__ . '/../config/db.php';         // must create $pdo
require_once __DIR__ . '/../includes/auth.php';     // must define login_user()

// POST handling before including header
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');
    $remember = !empty($_POST['remember']);

    if ($email === '' || $pass === '') {
        $error = 'Bitte E-Mail und Passwort angeben.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, password_hash, is_active, role FROM users WHERE email = :e LIMIT 1");
            $stmt->execute([':e' => $email]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$u || !password_verify($pass, $u['password_hash'])) {
                $error = 'Ungültige Zugangsdaten.';
            } elseif (isset($u['is_active']) && !$u['is_active']) {
                $error = 'Account ist deaktiviert.';
            } elseif (!in_array($u['role'] ?? 'seller', ['seller','admin'])) {
                $error = 'Kein Zugriff (kein Seller-Konto).';
            } else {
                // success: set session and optional remember cookie
                login_user($pdo, (int)$u['id'], $remember);

                // redirect to dashboard using page_url() from helpers
                header('Location: ' . page_url('dashboard.php'));
                exit;
            }
        } catch (Throwable $e) {
            $error = 'Login error: ' . $e->getMessage();
        }
    }
}

// include header AFTER processing POST (prevents redirect races)
include_once __DIR__ . '/../includes/header.php';
?>
<div class="container py-5">
    <div class="row justify-content-center"><div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h4 class="mb-3">Login</h4>
                    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                    <form method="post" novalidate>
                        <div class="mb-2"><label class="form-label">E-Mail</label>
                            <input name="email" type="email" required class="form-control" value="<?=htmlspecialchars($_POST['email'] ?? '')?>"></div>
                        <div class="mb-2"><label class="form-label">Passwort</label>
                            <input name="password" type="password" required class="form-control"></div>
                        <div class="mb-3 form-check">
                            <input id="remember" name="remember" type="checkbox" class="form-check-input">
                            <label for="remember" class="form-check-label">Angemeldet bleiben</label>
                        </div>
                        <div class="d-flex justify-content-between">
                            <button class="btn btn-primary">Login</button>
                            <a class="btn btn-link" href="<?= htmlspecialchars(page_url('register.php')) ?>">Registrieren</a>
                        </div>
                    </form>
                </div>
            </div>
        </div></div>
</div>
<?php include_once __DIR__ . '/../includes/footer.php'; ?>
