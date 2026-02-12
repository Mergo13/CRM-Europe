# Security Policy

## Supported versions
We maintain the `main` branch. No formal backporting policy exists; critical security fixes may be cherry-picked to maintenance branches if needed.

## Reporting a vulnerability
If you discover a security issue:
- Do NOT file a public issue with exploit details.
- Email: security@vision-lt.de (or the maintainer email in composer.json)
- Include a clear description, reproduction steps, and potential impact.
- We will acknowledge within 5 working days and provide a remediation plan if confirmed.

## Guidelines
- Avoid committing secrets (.env, credentials). Use `.env.example` as reference.
- Keep dependencies updated via `composer update` when safe.
- For local/dev environments set `APP_ENV=development` to enable error visibility; disable in production.
