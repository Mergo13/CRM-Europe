<?php
if (!isset($pageTitle)) $pageTitle = 'Vision L&T CRM';
$current_user = $current_user ?? ['name' => 'Mergim Izairi'];

$basePath = dirname($_SERVER['PHP_SELF']);
$assetPrefix = strpos($basePath, '/pages') !== false ? '../' : '';
$current = basename($_SERVER['PHP_SELF']);

function nav_active($needle, $current) {
    return $needle === $current ? 'active' : '';
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?></title>

    <script>
      (function(){
        try {
          var saved = localStorage.getItem('theme');
          if (!saved) {
            saved = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
          }
          document.documentElement.setAttribute('data-theme', saved);
        } catch (e) {
          document.documentElement.setAttribute('data-theme', 'light');
        }
      })();
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= $assetPrefix ?>public/css/theme.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/list-pages.css">

</head>

<body>

<nav class="navbar navbar-expand-lg shadow-sm sticky-top crm-navbar">
    <div class="container-fluid">

        <!-- BRAND -->
        <a class="navbar-brand d-flex align-items-center fw-semibold"
           href="<?= $assetPrefix ?>pages/dashboard.php">
            <img src="<?= $assetPrefix ?>assets/logo_white.png"
                 alt="Vision L&T Logo"
                 height="44"
                 class="me-2 rounded-2 brand-animated">
            <span>Vision&nbsp;L&amp;T</span>
        </a>

        <!-- MOBILE TOGGLE -->
        <button class="navbar-toggler border-0"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#navbarCRM"
                aria-controls="navbarCRM"
                aria-expanded="false"
                aria-label="Navigation umschalten">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- NAV CONTENT -->
        <div class="collapse navbar-collapse" id="navbarCRM">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-lg-center gap-lg-2">

                <!-- DASHBOARD -->
                <li class="nav-item">
                    <a class="nav-link px-3 <?= nav_active('dashboard.php',$current) ?>"
                       href="<?= $assetPrefix ?>pages/dashboard.php">
                        <i class="bi bi-speedometer2 me-1"></i>Dashboard
                    </a>
                </li>

                <!-- THEME TOGGLE (IMPORTANT) -->
                <li class="nav-item">
                    <button id="themeToggle"
                            class="theme-toggle px-3"
                            type="button"
                            title="Theme umschalten">
                        <i class="bi bi-sun-fill sun"></i>
                        <i class="bi bi-moon-stars-fill moon"></i>
                        <span class="visually-hidden label">Dark</span>
                    </button>
                </li>

                <!-- USER -->
                <li class="nav-item">
                    <div class="user-chip">
                        <i class="bi bi-person-circle"></i>
                        <?= htmlspecialchars($current_user['name']) ?>
                    </div>
                </li>

                <!-- SETTINGS -->
                <li class="nav-item">
                    <a class="nav-link px-3"
                       href="<?= $assetPrefix ?>pages/settings.php"
                       title="Settings">
                        <i class="bi bi-gear"></i>
                    </a>
                </li>

                <!-- LOGOUT -->
                <li class="nav-item">
                    <a class="nav-link px-3 text-danger"
                       href="<?= $assetPrefix ?>pages/logout.php"
                       title="Logout">
                        <i class="bi bi-box-arrow-right"></i>
                    </a>
                </li>

            </ul>
        </div>
    </div>
</nav>

<script>
  document.addEventListener('DOMContentLoaded', function() {
    const btn = document.getElementById('themeToggle');
    if (!btn) return;
    const sun = btn.querySelector('.sun');
    const moon = btn.querySelector('.moon');
    const label = btn.querySelector('.label');
    const refresh = () => {
      const t = document.documentElement.getAttribute('data-theme') || 'light';
      if (t === 'dark') {
        if (label) label.textContent = 'Light';
        if (sun) sun.style.display = 'inline';
        if (moon) moon.style.display = 'none';
      } else {
        if (label) label.textContent = 'Dark';
        if (sun) sun.style.display = 'none';
        if (moon) moon.style.display = 'inline';
      }
    };
    refresh();
    btn.addEventListener('click', function() {
      const curr = document.documentElement.getAttribute('data-theme') || 'light';
      const next = curr === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      try { localStorage.setItem('theme', next); } catch (e) {}
      refresh();
    });
  });
</script>
