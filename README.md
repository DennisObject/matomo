# Matomo Laravel

This repository contains the Laravel port of Matomo, a free and open analytics platform. The port keeps Matomo's public URLs, API contracts, database schema, plugin behavior, privacy controls, and access rules while moving the runtime to Laravel.

The migration is delivered in tested vertical slices. The Laravel application is in [`laravel/`](laravel/), and the compatibility plan and current migration order are in [`LARAVEL_MIGRATION.md`](LARAVEL_MIGRATION.md).

## Requirements

- PHP 8.3 or later for the Laravel runtime
- MySQL 8.0 or later, or MariaDB 10.6 or later
- Composer 2
- Node.js 24 and npm for frontend assets
- The PHP PDO MySQL extension

The legacy Matomo runtime keeps its PHP 8.1 compatibility until the final Laravel cutover.

## Setup

Install and configure the Laravel application from the repository root:

```bash
cd laravel
composer install
npm ci
cp .env.example .env
php artisan key:generate
npm run build
```

Set the Matomo database and application values in `laravel/.env`. The Laravel runtime reads the existing Matomo installation configuration and database; it does not introduce a second analytics data store.

For the existing DDEV development environment, see [`.ddev/README.md`](.ddev/README.md).

## Run

For local Laravel development:

```bash
cd laravel
composer run dev
```

The public compatibility endpoint remains `index.php`. Only API methods that have passed their parity checks are handled by Laravel during the staged migration.

## Quality

Run the Laravel checks from `laravel/`:

```bash
composer quality
composer audit --locked
npm audit --audit-level=high
npm run build
```

The existing Matomo checks remain required for legacy code that is still present. See [`tests/README.md`](tests/README.md) and [`CONTRIBUTING.md`](CONTRIBUTING.md).

## Migration rules

- Keep public request and response behavior compatible.
- Preserve authentication, authorization, CSRF, privacy, and consent controls.
- Reuse the existing database schema and archive format.
- Avoid unbounded queries and N+1 work on large installations.
- Keep each slice reversible until its parity tests pass.
- Remove a legacy path only with its tested Laravel replacement.

## License

Matomo Laravel is free software released under the GNU General Public License v3 or later. See [`LICENSE`](LICENSE).

Existing copyright notices, third-party licenses, and attribution files remain part of the repository and continue to apply.
