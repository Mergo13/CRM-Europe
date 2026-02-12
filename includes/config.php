<?php
// includes/config.php
// Project configuration. Prefer setting environment variables (DB_DSN, DB_USER, DB_PASS, APP_DEBUG)
// For quick local dev you can edit these defaults, but don't store production secrets here.

if (!defined('APP_ENV')) {
    define('APP_ENV', getenv('APP_ENV') ?: 'development');
}
if (!defined('APP_DEBUG')) {
    // set via env: APP_DEBUG=1
    define('APP_DEBUG', (bool) (getenv('APP_DEBUG') ?: true));
}

// DB: prefer env variables. If empty, sqlite fallback is used.
define('DB_DSN', getenv('DB_DSN') ?: '');
define('DB_USER', getenv('DB_USER') ?: '');
define('DB_PASS', getenv('DB_PASS') ?: '');

// Asset prefix: adjust if your app is served from a subdirectory
if (!defined('ASSET_PREFIX')) {
    define('ASSET_PREFIX', getenv('ASSET_PREFIX') ?: '/');
}
