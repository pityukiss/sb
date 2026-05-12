# SHOBID Security Setup

## 1) Create local config
Copy `config.local.example.php` to `config.local.php` and fill in real values.

Required keys:
- `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`, `DB_NAME`
- `STRIPE_PUBLISHABLE_KEY`, `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`
- `ADMIN_PASSWORD_HASH` (recommended) or `ADMIN_PASSWORD` (temporary fallback)

Optional:
- `MIGRATION_TOKEN` (needed only when calling `migrate.php` from browser)

## 2) Generate admin password hash
Use a secure PHP hash (example):

```php
<?php
echo password_hash('your-strong-admin-password', PASSWORD_DEFAULT) . PHP_EOL;
```

Place the generated hash into `ADMIN_PASSWORD_HASH`.

## 3) Run migration once
Preferred:
- CLI: `php migrate.php`

Or through browser (only if `MIGRATION_TOKEN` set):
- `/migrate.php?token=YOUR_TOKEN`

## 4) Production checklist
- Remove all test Stripe keys and use live keys.
- Force HTTPS at web server level.
- Keep `config.local.php` outside version control.
- Rotate keys immediately if any secret was exposed before.
