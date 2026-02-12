# Smoke test

A minimal CLI script is provided to verify environment readiness without requiring a database.

## Run
```
composer install
php scripts/smoke_test.php
```

Expected output (example):
```
[OK]  PHP >= 8.1 (8.4.x)
[OK]  Required PHP extensions present: pdo, iconv
[OK]  mpdf available via Composer
[OK]  includes/helpers.php loaded; page_url() available
[OK]  Writable: /path/to/project/logs
[OK]  Writable: /path/to/project/pdf
[OK]  Writable: /path/to/project/pdf/rechnungen
[OK]  APP_ENV = development (default)
Smoke test completed.
```

## Optional DB probe
Enable a lightweight DB check (SELECT 1) by setting an environment variable:
```
SMOKE_DB=1 php scripts/smoke_test.php
```

This will `require config/db.php` and attempt a trivial query if `$pdo` is available.
