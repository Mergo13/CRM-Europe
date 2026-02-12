<?php
// pages/profile.php — simple user profile page (requires login)
// Minimal implementation to satisfy navigation links and 404 issue.

// Do NOT disable auth redirect here; this page requires an authenticated user.

// Optionally set a page title for header
$pageTitle = 'Profil';

// Ensure helpers/DB/auth available before header so current_user() works reliably
$helpers = __DIR__ . '/../includes/helpers.php';
$dbFile  = __DIR__ . '/../config/db.php';
$auth    = __DIR__ . '/../includes/auth.php';
if (is_file($helpers)) require_once $helpers;
if (is_file($dbFile))  require_once $dbFile;   // should define $pdo
if (is_file($auth))    require_once $auth;     // defines current_user(), etc.

// Include the shared header (enforces auth redirect if not logged in)
include_once __DIR__ . '/../includes/header.php';

// $current_user is set by header.php when available; fall back to calling current_user()
if (empty($current_user) && function_exists('current_user') && isset($pdo) && $pdo instanceof PDO) {
    $current_user = current_user($pdo);
}
?>
<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8 col-xl-7">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center mb-3">
                        <i class="bi bi-person-circle me-2" style="font-size:1.6rem"></i>
                        <h4 class="mb-0">Profil</h4>
                    </div>

                    <?php if (!empty($current_user)): ?>
                        <dl class="row mb-0">
                            <dt class="col-sm-4">Name</dt>
                            <dd class="col-sm-8"><?= htmlspecialchars($current_user['name'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>

                            <dt class="col-sm-4">E-Mail</dt>
                            <dd class="col-sm-8"><a href="mailto:<?= htmlspecialchars($current_user['email'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                <?= htmlspecialchars($current_user['email'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a></dd>

                            <dt class="col-sm-4">Rolle</dt>
                            <dd class="col-sm-8"><span class="badge bg-secondary"><?= htmlspecialchars($current_user['role'] ?? 'seller', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></dd>
                        </dl>
                    <?php else: ?>
                        <div class="alert alert-warning">Kein Benutzerkontext verfügbar.</div>
                    <?php endif; ?>

                    <hr class="my-4" />
                    <div class="d-flex gap-2">
                        <a class="btn btn-primary" href="<?= htmlspecialchars(page_url('settings.php')) ?>">
                            <i class="bi bi-gear me-1"></i>Einstellungen
                        </a>
                        <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(page_url('dashboard.php')) ?>">
                            <i class="bi bi-speedometer2 me-1"></i>Zum Dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include_once __DIR__ . '/../includes/footer.php'; ?>
