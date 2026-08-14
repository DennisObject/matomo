# Matomo Laravel Runtime

This Laravel 13 application is the new Matomo runtime. It stays isolated from the current Matomo entry points until each migrated slice passes its parity checks.

## Setup

```bash
composer install
npm ci
npm run build
```

Copy `.env.example` to `.env`, then run `php artisan key:generate` for local work.

## Quality

```bash
composer quality
composer audit --locked
npm audit --audit-level=high
```

Use `composer format` and `composer refactor` to apply automatic fixes.

See [`../LARAVEL_MIGRATION.md`](../LARAVEL_MIGRATION.md) for the compatibility contract and migration order.
