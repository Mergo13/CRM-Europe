# Project Guidelines

This document gives Junie a concise project overview and clear operating rules for this repository.

## 1) Project overview
Rechnung-app is a small PHP application for managing clients and documents:
- Angebote (quotes), Lieferscheine (delivery notes), Rechnungen (invoices), Mahnungen (reminders)
- Generates PDFs and stores logs/data locally

Primary technologies:
- PHP 8+ with no framework
- Composer for dependencies (e.g., mpdf, guzzle, google analytics client, jwt)
- MySQL/MariaDB for persistence

## 2) Directory layout (high level)
- `index.php` — main entry point (root)
- `config/` — app configuration (database and app settings)
- `pages/` — UI pages (lists, forms, actions)
  - `pages/api/` — simple API endpoints
  - `pages/shared/` — shared page components
- `includes/` — shared includes like `footer.php`
- `assets/` — static assets (css/js)
- `vendor/` — Composer dependencies
- `pdf/`, `pdfs/` — PDF generation resources and output
- `data/`, `logs/` — app data and logs
- `public/` — public assets (css/js) for web servers that use a public doc root
- `scripts/` — helper scripts
- `schema.sql`, `install.sql` — database schema

Useful reference files:
- `README.txt`, `INTEGRATION_README.txt` — legacy notes
- `composer.json` — PHP dependencies and metadata

## 3) Configuration
- Copy and edit files in `config/` as needed (e.g., database credentials, base URL). Look for `config/app-config.php`.
- Import `schema.sql` (or `install.sql`) into your MySQL database before first run.

## 4) How to run locally
Prerequisites: PHP 8.1+, Composer, MySQL/MariaDB.

Steps:
1. Install dependencies: `composer install`
2. Create database and import `schema.sql`
3. Configure `config/app-config.php`
4. Start a local server. Two common options:
   - PHP built-in server from project root:
     `php -S localhost:8000`
     Then open http://localhost:8000/
   - Or point Apache/Nginx to the project root (or `public/` if you prefer a dedicated doc root for assets).

## 5) Tests
- There is no dedicated automated test suite in this repository at the moment.
- Junie should not attempt to run tests unless a test suite is added in the future.
- Validate changes by exercising affected pages under `pages/` in a local run and checking logs/PDF outputs.

## 6) Build/CI
- No build step is required beyond `composer install`.
- Ensure writable permissions for `logs/`, `pdfs/`, and any directories that need to write files.

## 7) Coding style and conventions
- PHP code: follow PSR-12 coding style where practical.
- Naming: prefer descriptive snake_case for files under `pages/` (as existing), and camelCase for variables/functions within PHP.
- Keep includes and requires relative and consistent with existing patterns.
- Avoid introducing new frameworks; stay close to the current lightweight approach unless requested.

## 8) How Junie should operate on this project
- Prefer minimal, targeted changes.
- Do not introduce or rely on a test runner (none present).
- If a change affects routing or shared includes, review `index.php`, `includes/`, and the relevant `pages/` to keep links and actions consistent.
- If adding dependencies, update `composer.json` and run `composer install`.

## 9) Troubleshooting
- If pages render blank, enable/display PHP errors in your local environment and check `logs/`.
- PDF issues: verify fonts (`DejaVuSans.php`, `DejaVuSans.z`) and write permissions for output directories.


---

Advanced project-specific notes for future development

1) Build and configuration nuances
- Composer and PHP: Project targets PHP >= 8.1. Composer dependencies are defined in composer.json and must be installed before any page is executed (vendor/autoload.php is expected). Some pages include vendor classes directly (e.g., mpdf/mpdf). If autoload is missing, various pages will fail late.
- Database configuration: config/db.php prefers environment variables and will attempt to create a PDO connection on include. It supports both DB_DSN and host/port/name/user/pass variables. Important vars: APP_ENV, DB_DSN, DB_HOST, DB_PORT, DB_NAME or DB_DATABASE, DB_USER or DB_USERNAME, DB_PASS or DB_PASSWORD, DB_CHARSET. In dev (APP_ENV in [dev, local, development]) it enables display_errors and full E_ALL; otherwise it suppresses display_errors.
- .env support: db.php includes a tiny loader that reads a project-root .env into getenv/$_ENV/$_SERVER. No dependency on vlucas/phpdotenv is required for this loader, though the package exists in composer for broader use.
- PDF paths and permissions: includes/helpers.php defines PDF_BASE_FS and PDF_BASE_WEB, and the pdf_paths() helper which creates target directories on demand (year/month subfolders; Mahnung supports extra subfolder). Ensure the following are writable by the web/PHP user: logs/, pdf/ and all nested folders. Font files (DejaVuSans*, included at project root) and mpdf must be available.
- URL building: page_url() in includes/helpers.php derives the base path from the executing script. When serving from a subdirectory or through a public/ doc-root, verify routing so that /pages/* links resolve correctly.
- App config: config/app-config.php exposes CRMConfig with UI theming, quick actions and dunning (mahnung) timing. Monetary additions for dunning are disabled per current requirements.

2) Testing information (no formal test suite; use CLI smoke checks)
- Philosophy: The repo does not include a PHPUnit or similar test suite. Use small, CLI-run smoke scripts under scripts/ to validate environment readiness and critical assumptions without requiring a database.
- Example: A working smoke test
  Create scripts/smoke_test.php with the following minimal checks:

  Example code (abbreviated):
  - Verify PHP version >= 8.1 and required extensions (pdo, iconv)
  - Require vendor/autoload.php and assert Mpdf\Mpdf exists
  - Require includes/helpers.php and check page_url() function exists
  - Verify write access to logs/ and pdf/ (create/delete a temp file)
  - Output APP_ENV

  How to run:
  - composer install
  - php scripts/smoke_test.php

  Verified output on a working environment (your versions/paths may differ):
  [OK]  PHP >= 8.1 (8.4.14)
  [OK]  Required PHP extensions present: pdo, iconv
  [OK]  mpdf available via Composer
  [OK]  includes/helpers.php loaded
  [OK]  Writable: .../logs
  [OK]  Writable: .../pdf
  [OK]  Writable: .../pdf/rechnungen
  [OK]  page_url() is available
  [OK]  APP_ENV = development (default)
  Smoke test completed.

- Adding more checks:
  - To probe database connectivity locally, you can optionally include config/db.php inside a try/catch and test a lightweight query like SELECT 1, but keep this disabled by default to avoid coupling tests to developer DBs. Example snippet:
    try { require __DIR__.'/../config/db.php'; $pdo->query('SELECT 1'); echo "[OK] DB reachable\n"; } catch (Throwable $e) { echo "[WARN] DB not configured: {$e->getMessage()}\n"; }
  - When exercising PDF generation, prefer a dry-run that does not persist output, or write into a temp subdirectory and clean up.
  - Place one-off test scripts under scripts/ and do not commit them long-term; keep .junie/guidelines.md as the living documentation.

3) Additional development/debugging information
- Error visibility:
  - For CLI and local dev, set APP_ENV=development to enable display_errors and full E_ALL in config/db.php.
  - Some pages explicitly set error_reporting(E_ALL) and ini_set('display_errors','1') during development.
- Routing and includes:
  - index.php is the standard entry point. Pages under pages/ often include includes/helpers.php and config/db.php directly. Be cautious about side effects (db.php will try to connect).
  - Shared partials are under pages/shared and pages/partials. Public assets live under public/ with css/js subfolders; assets/ also contains static files referenced by pages.
- Data model:
  - schema.sql and install.sql contain MySQL DDL. Import one of them to bootstrap a database. Table names used around the app include clients, rechnungen, angebote, mahnungen, lieferscheine.
- Internationalization/formatting:
  - Money formatting and date handling appear in various pages (e.g., pages/dashboard.php has a money() helper). Keep outputs consistent with German locale defaults (e.g., number_format with ',' as decimal and '.' as thousands separator) unless requirements change.
- PDF generation tips:
  - mpdf relies on fonts packaged at the project root (DejaVuSans.*). Ensure correct file permissions and that output directories under pdf/ exist or can be created by the running user.
- Security/auth:
  - Some pages rely on a current_user() helper; ensure sessions are correctly configured when enabling auth flows. Invite secret and registration flags live in CRMConfig::$auth.

4) Quick setup checklist for contributors
- php -v should report >= 8.1
- composer install
- Ensure logs/ and pdf/ are writable by the web server/PHP process
- Create and configure a MySQL database; import schema.sql; set env via .env or server variables matching config/db.php
- Start with php -S localhost:8000 from project root and browse to /pages/

Note: Keep this document project-specific and concise. Avoid committing throwaway test scripts; document the process here and verify locally before pushing changes.
