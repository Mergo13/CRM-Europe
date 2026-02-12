# Deployment guide

This project is a lightweight PHP app with PDF generation. There is no build step beyond `composer install`.

## Prerequisites
- PHP 8.1+ with extensions: pdo, iconv
- Web server (Apache/Nginx) or PHP built-in server for local
- MySQL/MariaDB (recommended)
- Write permissions for: logs/, pdf/, pdfs/, and subfolders

## Steps
1. Copy repository to server
2. Run Composer in project root:
   - `composer install --no-dev --optimize-autoloader`
3. Configure environment:
   - Copy `.env.example` to `.env` and set DB credentials
   - Or set environment variables in your server config
4. Database:
   - Import `schema.sql` (or `install.sql` for demo tables)
   - For inventory: import `scripts/migrations/2026_01_31_inventory.sql`
5. Permissions:
   - Ensure the PHP/web user can write to `logs/`, `pdf/` and nested year/month folders
6. Server config:
   - Apache: set DocumentRoot to project root (or to `public/` for assets) and enable URL access to `/pages/`
   - Nginx: proxy PHP to FPM; serve `/assets` and `/public` statically
7. Production settings:
   - Set `APP_ENV=production` and disable display_errors at the PHP level
   - Configure proper timezone and memory limits

## Smoke test
- Run `php scripts/smoke_test.php` on the server to verify environment readiness (no DB writes).

## Backups & logs
- Schedule backups for the database and the `pdf/` output directory
- Rotate `logs/` regularly
