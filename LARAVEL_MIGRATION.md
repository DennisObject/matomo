# Laravel Migration

## Context

- Checkout root: the repository root.
- Matomo version: `6.0.0-b1`, from `core/Version.php`.
- Product/module: Matomo core and all bundled plugins. Supplied by the user.
- Current behavior: Web requests enter through `index.php` and `core/dispatch.php`, then use `Piwik\FrontController`. CLI requests enter through `console` and use `Piwik\Console`. Derived from the checkout.
- Expected behavior: Move the full web, API, tracking, archiving, console, installation, update, plugin, and scheduled-task lifecycles to Laravel with complete feature and compatibility parity. Supplied by the user.
- Relevant files/classes: `index.php`, `console`, `core/dispatch.php`, `core/FrontController.php`, `core/Console.php`, `core/API/Request.php`, `core/Tracker.php`, `plugins/`, and `tests/`. Derived from the checkout.
- Constraints: Keep existing URLs, query parameters, API response shapes, database schemas, plugin contracts, privacy controls, permissions, CSRF controls, and large-instance performance. Use staged, reversible changes. Supplied and derived.

Assumptions

- Laravel will first run beside Matomo under `laravel/`; no production entry point changes until a parity gate passes.
- Laravel 13 is the target because it is the current stable major at the start of the work.
- Each migration slice must keep the old path available until its parity checks pass.

Out of scope

- The foundation slice does not route production Matomo traffic through Laravel.
- The foundation slice does not change the Matomo database schema or stored state.
- The foundation slice does not copy or replace existing UI assets.

Version constraints

- Matomo runtime floor: PHP 8.1.0, from `core/testMinimumPhpVersion.php` and the root `composer.json`.
- Matomo static-analysis syntax: PHP 8.1, from `phpstan.neon` (`phpVersion: 80100`).
- Matomo tested PHP versions: 8.1, 8.2, and 8.5, from `.github/workflows/matomo-tests.yml`.
- Laravel runtime floor: PHP 8.3, from the Laravel 13 application package metadata.
- Current Matomo version: 6.0.0-b1, from `core/Version.php`.
- The two Composer projects stay separate. The foundation does not raise Matomo's PHP floor.

## Files likely to change

Foundation and policy

- `AGENTS.md` — repository policy — existing.
- `LARAVEL_MIGRATION.md` — migration contract — new.
- `.github/workflows/laravel.yml` — Laravel quality pipeline — new.
- `.github/dependabot.yml` — dependency update scope — existing, if Laravel is not covered.

Laravel application

- `laravel/composer.json` and `laravel/composer.lock` — application dependencies — new.
- `laravel/app/` — Laravel application layer — new.
- `laravel/bootstrap/` and `laravel/config/` — Laravel bootstrap and configuration — new.
- `laravel/public/` and `laravel/routes/` — future HTTP entry point and routes — new.
- `laravel/tests/` — valuable application-level regression checks — new.
- `laravel/phpstan.neon` — Larastan at level 8 — new.
- `laravel/rector.php` — safe automated refactor rules — new.
- `laravel/pint.json` — Laravel code style — new.
- `laravel/boost.json` and generated Boost agent files — new if the installer marks them as tracked project inputs.

Later parity slices

- `laravel/app/Matomo/` — ports of Matomo services, contracts, and adapters — new.
- `laravel/routes/web.php`, `laravel/routes/api.php`, and `laravel/routes/console.php` — compatible request and command entry points — new.
- Existing `core/`, `plugins/`, `index.php`, and `console` files change only when their replacement has passed parity checks.

The listed Matomo submodules stay separate. A later change to a submodule needs its own commit and push.

## Existing patterns to look for

- Web dispatch: keep the externally visible behavior defined by `index.php`, `core/dispatch.php`, and `Piwik\FrontController::dispatch()`.
- Console dispatch: keep command names and exit behavior defined by `console` and `Piwik\Console`.
- API compatibility: keep `Piwik\API\Request` method names, query parameters, permission checks, formats, and response metadata.
- Regression coverage: reuse the current system and API fixtures under `tests/PHPUnit/System/` before adding new fixtures.
- CI layout: follow the small, named workflows in `.github/workflows/phpstan.yml` and `.github/workflows/phpcs.yml`.
- No Laravel application exists in this checkout. Follow the Laravel 13 application skeleton and the official Laravel Boost and Pint setup.

## Proposed approach

1. `LARAVEL_MIGRATION.md` — architecture — record the compatibility contract, gates, and rollback rule before code moves.
2. `laravel/` — application — create a clean Laravel 13 application with its own lock file and no production routing change.
3. `laravel/composer.json` — tooling — add Laravel Boost, Larastan, Rector, and stable Composer scripts for format, analysis, refactor checks, tests, and security audit. Keep Pint from the Laravel skeleton.
4. `laravel/phpstan.neon` and `laravel/rector.php` — quality — set Larastan to level 8 and Rector to safe PHP 8.3 and code-quality rules. Do not create a baseline for new code.
5. `.github/workflows/laravel.yml` — CI — run Composer validation, dependency audit, Pint, Larastan, Rector dry-run, PHPUnit, and the frontend build with a pinned PHP and Node matrix.
6. `laravel/tests/Feature/FoundationTest.php` — test — prove that the application boots, the health route works, and accidental Matomo routing is not enabled.
7. Port one bounded vertical slice at a time. Start with read-only system information, then API routing, authentication and authorization, site management, reporting, tracking, archiving, scheduled tasks, plugins, installation and updates, and finally the UI shell.
8. For each slice, run the old and new paths against the same fixture data. Compare status, headers, body, database writes, emitted events, logs, and side effects. Switch traffic only after the comparison passes.
9. Keep query-parameter API compatibility at the public boundary. Laravel controllers and requests may use internal resource routes only when that does not change a public URL.
10. Remove a legacy path only in the same slice that proves its replacement and rollback path. Remove the final legacy bootstrap only after the full parity matrix is green.

## Edge cases

- PHP 8.1 runs Matomo but cannot run Laravel 13. Expected behavior: Matomo continues to run; Laravel CI and deployment fail early with a clear PHP 8.3 requirement.
- Both projects have `composer.json`. Expected behavior: commands use an explicit working directory and never update the wrong lock file.
- Requests contain repeated, empty, encoded, numeric-string, or array query values. Expected behavior: the Laravel boundary preserves Matomo parsing and validation semantics.
- Requests use GET or POST for legacy API calls. Expected behavior: supported Matomo request methods and precedence remain unchanged until a documented compatibility change.
- A request contains `token_auth`, passwords, or nonces. Expected behavior: secrets are never written to logs or error pages.
- A plugin is disabled, missing, or stored as a submodule. Expected behavior: discovery and failure behavior match Matomo.
- A large instance has many sites, segments, visits, or archives. Expected behavior: no new unbounded query, N+1 query, or in-memory full-data copy is introduced.
- A migration slice fails after a partial write. Expected behavior: the old path remains selected, and the slice defines transaction or retry behavior before cutover.
- A frontend request uses the current query-string API. Expected behavior: it continues to work without a client rewrite.

## Tests to add or update

- `laravel/tests/Feature/FoundationTest.php` — feature — new — proves the Laravel kernel boots and its health route returns a successful response. This gives fast certainty that the scaffold and CI are valid.
- Do not keep generated example tests that only restate framework defaults.
- Later slices add contract tests only for public behavior that can regress: request parsing, permission failures, response formats, database writes, event contracts, tracker throughput, archiving results, and visible UI flows.
- Prefer existing Matomo fixtures and expected outputs. Add new fixtures only when no existing fixture can prove the behavior.
- Use screenshot tests only when a visual result cannot be proved through DOM state.

## Risks or assumptions

- Laravel 13 raises the new application's runtime floor to PHP 8.3. Impact: a PHP 8.1-only host cannot run the new app. Mitigation: keep Matomo and Laravel deployments isolated until the final product requirement is raised. Accepted.
- A full port covers thousands of PHP files and plugin contracts. Impact: a big-bang rewrite would hide regressions. Mitigation: use vertical slices and parity gates. Accepted.
- Two containers and two service locators can create split state. Impact: auth, events, cache, and configuration can disagree. Mitigation: one owner per migrated slice and no dual writes without an explicit idempotency rule. Accepted.
- The checkout has no stable release tags. Impact: later core-helper availability checks cannot use local tag history. Mitigation: fetch tags before a slice depends on historical API availability. Accepted for the foundation; blocking for such a later slice.
- The foundation does not produce feature parity by itself. Impact: Laravel is not yet the production entry point. Mitigation: the old runtime remains active and the parity program continues through the ordered slices. Accepted.

## Review Readiness

Applied rule sets

- `matomo-implementation-planning` — this document records the checked boundary, versions, files, tests, risks, and verification before implementation.
- `matomo-security-rules` — the foundation has no request bridge, token logging, SQL, or state-changing controller; later bridges must preserve Matomo access and CSRF checks.
- `matomo-code-quality` — Matomo checks remain unchanged; Laravel gets Pint, Larastan level 8, and Rector dry-run checks.
- `matomo-test-runner` — the foundation adds one valuable Laravel boot check and does not duplicate Matomo tests.
- `matomo-review` — every branch is reviewed against `origin/6.x-dev` before push and merge.
- `matomo-api-development-rules` — Not applicable while the foundation adds no Matomo API method; required for each API slice.
- `matomo-plugin-architecture` — Not applicable while the foundation changes no plugin structure; required for plugin slices.
- `matomo-migrations-workflow` — Not applicable: no schema or stored-state change.
- `matomo-deprecation-rules` — Not applicable: no public Matomo behavior changes.
- `matomo-vue-development-rules` — Not applicable: no Matomo Vue source changes.
- `matomo-twig-development-rules` — Not applicable: no Twig changes.
- `matomo-i18n-development-rules` — Not applicable: no user-facing translation keys.
- `matomo-documentation` — Not applicable: no public PHP API or posted event changes.

Open review risks

- The final runtime and deployment floor must be set before production traffic moves to Laravel.

## Verification

Prerequisites

- PHP 8.3 or later runs the Laravel checks. If absent, Laravel cannot install or boot; Matomo stays unchanged.
- Composer 2 runs in `laravel/`. If run at the repository root, it targets Matomo instead of Laravel.
- Node 24 runs the frontend build in CI to match this repository's declared Node line. If absent, only the Laravel asset build fails.

Commands to run

- `composer --working-dir=laravel validate --strict`
- `composer --working-dir=laravel audit --locked`
- `composer --working-dir=laravel format:test`
- `composer --working-dir=laravel analyse`
- `composer --working-dir=laravel refactor:test`
- `composer --working-dir=laravel test`
- `npm --prefix laravel ci`
- `npm --prefix laravel run build`
- `git diff --check origin/6.x-dev...HEAD`

Definition of done

- The Laravel app is locked to Laravel 13 and boots on PHP 8.3 through 8.5.
- Boost, Pint, Larastan level 8, Rector, tests, dependency audit, and the frontend build have stable local and CI commands.
- No production Matomo route, schema, API contract, or frontend behavior changes in the foundation slice.
- The first branch and PR use the required conventional names and pass review before merge.
- Each later slice has a parity test, a safe cutover, and a rollback path.
- The program is complete only when all Matomo web, API, tracker, archive, console, scheduled, install, update, plugin, and UI behavior runs through Laravel and the legacy bootstraps are removed.

Planning does not run these commands. They run during implementation and review.
