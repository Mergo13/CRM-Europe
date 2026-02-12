# Contributing to Rechnung-app

Thanks for your interest in contributing! This project is a lightweight PHP app without a framework. To keep it stable and professional, please follow these guidelines.

## Getting started
- PHP >= 8.1, Composer, and MySQL/MariaDB are recommended.
- Install dependencies:
  - `composer install`
- Configure environment:
  - Copy `.env.example` to `.env` and adjust DB credentials.
  - Import `schema.sql` (or `install.sql`) into your DB.
- Run locally:
  - `php -S localhost:8000`
  - Open http://localhost:8000/

## Code style
- Follow PSR-12 where practical.
- Run lints before submitting:
  - `composer lint` (PHPCS)
  - `composer analyse` (PHPStan)
- Prefer minimal, targeted changes. Avoid introducing frameworks.

## Commit messages
- Use clear, descriptive messages in imperative mood:
  - Example: "Add inventory movement table migration".
- Reference related pages/files if applicable.

## Pull request checklist
- [ ] Business logic and routes remain backward compatible unless discussed.
- [ ] No secrets or environment-specific files included (.env, config/db.php, logs, pdfs).
- [ ] `composer.json` remains valid: run `composer validate`.
- [ ] Lint and static analysis pass or include rationale for ignores.
- [ ] Provide manual testing notes for changed pages under `pages/`.

## Security
- Please do not open public issues for sensitive security problems. See SECURITY.md for reporting.

## Documentation
- Update README/docs when behavior or setup changes.

## Licensing
- Contributions are made under the MIT license unless noted otherwise.
