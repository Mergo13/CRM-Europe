<?php
// Root shim to route /settings.php to the real page under /pages/settings.php
// Keeps existing absolute links (<a href="/settings.php">) working.

declare(strict_types=1);

$target = '/pages/settings.php';

// If headers already sent, fallback to HTML link
if (headers_sent()) {
    echo '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($target, ENT_QUOTES) . '"></head><body>';
    echo 'Weiterleitung zu <a href="' . htmlspecialchars($target, ENT_QUOTES) . '">Einstellungen</a> ...';
    echo '</body></html>';
    exit;
}

// Use 302 temporary redirect (safe default)
header('Location: ' . $target, true, 302);
exit;
