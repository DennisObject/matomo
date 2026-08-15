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

### Checked parity inventory

This inventory was checked at `e8fa469705`. A method is counted only when it is a public method on a plugin's `API` class, is not a constructor, and is not marked `@ignore`.

Current Laravel entry points:

| Surface | Legacy source | Checked total | Laravel status |
| --- | --- | ---: | --- |
| Reporting API methods | `plugins/*/API.php` | 389 methods | 288 method names handled |
| Web controllers | `plugins/*/Controller.php` | 47 controller files | Not ported; `/` remains a 503 foundation route |
| Console commands | `plugins/**/Commands/*.php` | 129 command files | Not ported; `laravel/routes/console.php` is empty |
| Scheduled tasks | `plugins/*/Tasks.php` | 15 task providers | Persistent runner and extension contract ported; bundled providers remain |
| Report archivers | `plugins/*/Archiver.php` | 19 archivers | Archive storage, day and parent core metrics, cache reuse, failure markers, extension events, all bundled segment families, active site-configured custom dimensions, 23 visit-derived report records, goal and ecommerce enrichment for time, device, and location reports, general goal metrics and conversion-timing records, ecommerce item and product-view records, all six hierarchical Events records, both hierarchical Contents records, all three ExamplePlugin records, all 14 PagePerformance totals with configured caps, all seven BotTracking overview, content, broken-content, and favoured-page records, and recursive Actions traffic, search, entry, exit, timing, capped page-performance timing, flat, numeric, and goal-attribution records ported; other specialized plugin records remain |
| Tracker extensions | `plugins/*/Tracker.php`, `plugins/*/Tracker/*.php` | 15 files | Not ported; `matomo.php` and `piwik.php` remain legacy entry points |
| Update migrations | `core/Updates/*.php`, `plugins/*/Updates/*.php` | 191 update files | Not ported |
| Installation and updates | `plugins/Installation`, `plugins/CoreUpdater` | 2 lifecycles | Not ported |
| Plugin lifecycle | `Piwik\\Plugin\\Manager`, events, hooks, dependency injection | Cross-cutting | Not ported |
| Browser UI and assets | plugin controllers, Twig, Vue, JavaScript, asset manager | Cross-cutting | Not ported |

Handled reporting API method names:

- `API`: `getIpFromHeader`, `getMatomoVersion`, `getPagesComparisonsDisabledFor`, `getPhpVersion`, `getPiwikVersion`, `isPluginActivated`.
- `SitesManager`: all 55 public API methods, including lifecycle mutations, measurable settings, tracking-code generation, site-scoped and global settings, aliases, groups, metadata, access-filtered lists, and consent-manager detection.
  The default currency and timezone writes also preserve superuser access, legacy validation, and option storage.
  Global IP, search, user-agent, referrer, URL-fragment, and query-parameter exclusion writes preserve
  input normalization, custom-mode invariants, stale-option deletion, and tracker-cache invalidation.
  Alias URL add and replace operations preserve admin access, URL normalization, atomic storage,
  main-URL ownership, inserted counts, and site and global tracker-cache invalidation.
  Group rename preserves superuser access, atomic cross-site updates, no-op behavior, and affected-site cache clears.
- `VisitsSummary`: all 10 API methods in every supported response format.
- `VisitFrequency`: `get` in every supported response format.
- `VisitTime`: all 3 API methods in every supported response format.
- `VisitorInterest`: all 4 API methods in every supported response format.
- `UserLanguage`: both API methods in every supported response format.
- `Resolution`: both API methods in every supported response format, including compliance-policy filtering.
- `DevicePlugins`: `getPlugin` in every supported response format.
- `PagePerformance`: `get` in every supported response format.
- `UserId`: `getUsers` in every supported response format.
- `UsersManager`: access-role and plugin-capability metadata, including localized role labels,
  some-site admin access checks, and an extension event for capability registration; plus supported
  user preference reads and writes with self-or-superuser access, canonical logins, legacy defaults,
  JSON storage, LDAP option compatibility, initialization, and superuser bulk reads; plus login and
  email existence checks, admin email-to-login lookup, and current superuser status.
- `Actions`: all 18 API methods and their archive producers, including numeric totals,
  hierarchical and pre-flattened reports, bounded expansion, direct action lookup, entry and exit
  reports, search reports, metadata, segment values, goal attribution, and processed page metrics.
- `Annotations`: all 7 API methods, including site-scoped create, read, update, delete, range
  queries, period counts, note truncation and output escaping, and write-access decoration.
- `Contents`: both API methods and archive producers, including bounded root and subtable records,
  interaction-only filtering, parent aggregation, subtable IDs, and every supported response format.
- `BotTracking`: all 11 API methods, including unsegmented overview metrics,
  click-through rates, page and document subtables, chatbot metadata, flat and expanded rows,
  content timing and size averages, broken content, human- and AI-favoured pages, and bounded
  real-time chatbot and page-URL reports; plus all seven archive records, including bounded day
  queries, exact parent aggregation, error and performance accumulators, distinct human visits,
  variant score recomputation, and unscored ranked tails.
- `CustomJsTracker`: `doesIncludePluginTrackersAutomatically` in every supported response format.
- `ProfessionalServices`: `dismissWidget` in every supported response format.
- `Login`: `unblockBruteForceIPs` in every supported response format.
- `AIAgents`: `get` in every supported response format, including suffixed column filtering.
- `AIProviders`: all 4 API methods, including masked credential storage, managed configuration,
  provider extension events, and SSRF-safe connection tests.
- `Dashboard`: all 5 API methods, including legacy layout decoding, extension events, permission checks,
  default widgets, dashboard writes, and recipient visibility rules.
- `CustomDimensions`: 4 read APIs for configured dimensions, hidden scope filtering, installed visit
  and action capacity, extraction metadata, localized labels, and view or write access checks; plus
  the archived custom-dimension report with active checks, processed metrics, segments, subtables,
  expanded and flat output, and multi-period response shapes; plus both configuration writes with
  transactional slot allocation, legacy validation, optional-value preservation, and tracker-cache
  invalidation.
- `DBStats`: all 11 API methods, including general and server status, storage-family totals, tracker,
  numeric archive, blob archive, annual archive, admin-table, and cached per-record summaries,
  physical table prefixes, legacy grouping and cache formats, and strict superuser access.
- `SegmentEditor`: 3 read and permission APIs, including per-site creation roles, private-segment
  ownership, site access, invalid-definition filtering, and owner-first visibility ordering; plus
  soft delete, star, and unstar with edit checks, legacy hash updates, events, and cache clearing;
  plus add and update with legacy encoding, global and per-site permissions, archive-mode controls,
  timestamps, cache clearing, update events, and compatible queued rearchive entries; plus the
  preprocessed segment summary with current and previous-period visits, actions, evolution, and icons.
- `Insights`: `canGenerateInsights`, including some-site view access and fixed-period, range, and
  unsupported multi-period date detection; plus both direct insight reports with site access,
  segmented current and comparison archives, mover, new, and disappeared rows, impact and growth
  filters, ordering, limits, and mover-and-shaker marking; plus both extension-driven overview maps
  with default parameter merging, report labels, isolated source reads, and empty-report handling.
- `DevicesDetection`: all 8 API methods, including device, brand, model, operating-system, browser,
  and engine reports, legacy archive fallback, metadata, and compliance-policy filtering.
- `Events`: all 9 API methods and their six archive producers, including secondary dimensions,
  bounded subtables, parent aggregation, subtable IDs, expanded and flat archive reports,
  event-value metrics, metadata, and localized missing-name labels.
- `ExampleAPI`: all 9 API methods, including scalar, null, object, table, simple-array, and
  multidimensional-array response behavior plus the protected version lookup.
- `ExamplePlugin`: all 4 API methods, including its static report, numeric archive metrics,
  two numeric and visitor archive producers, segment hash lookup, truth switch, report validation,
  and view-access checks.
- `ExampleReport`: `getExampleReport`, including report parameters, response formats, and
  view-access checks.
- `ExampleUI`: all 4 API methods, including hourly and evolution temperature series, localized
  period labels, planet ratios, and optional logo and URL metadata.
- `Feedback`: all 3 API methods, including localized validation, English feedback labels, configured
  plain-text email delivery, permission checks, and legacy per-user reminder storage.
- `Goals`: all 12 API methods, including site-scoped management, legacy definition validation,
  tracker-cache invalidation, ecommerce item reports, goal metrics, and conversion-range reports.
- `JsTrackerInstallCheck`: both API methods, including short-lived nonce reuse, result lookup,
  main-URL fallback, URL validation, and site view-access checks.
- `LanguagesManager`: all 9 public API methods, including configured and filesystem language
  discovery, translation coverage and export, localized names, extension events, user language
  storage, 12-hour clock preferences, and self-or-superuser access checks.
- `MultiSites`: all 3 API methods, including visible-site filtering, site-name matching, current and
  prior-period archive metrics, evolution fields, enhanced goal and ecommerce metrics, dashboard
  totals, groups, search, sorting, paging, metric formatting, extension events, and RSS output.
- `CoreAdminHome`: `getTrackingFailures`, `deleteTrackingFailure`, and
  `deleteAllTrackingFailures`, including site-admin scoping, superuser handling, localized failure
  details, prefixed database access, and extension events; plus
  `whatIsNewMarkAllChangesReadForCurrentUser`, including viewer and login checks, recent-change
  filtering, extension filtering, and per-user read state; plus `setArchiveSettings` and
  `setTrustedHosts`, including superuser and feature-switch checks, option persistence, tracker-cache
  invalidation, safe INI rewriting through Matomo's INI component, and configuration events; plus
  `setBrandingSettings`, including per-instance and per-user file paths, staged logo and favicon
  publishing, cleanup, the branding option, and logo-change events; plus both opt-out embed-code
  methods, including trusted-host checks, validated styles, localized privacy text, consent-cookie
  settings, and encoded HTML and JavaScript boundaries; plus `invalidateArchivedReports`, including
  site-admin checks, extension-controlled site selection, strict date and range parsing, parent and
  child period expansion, automatic-segment re-archiving queues, old-log limits, and safe archive
  status updates through bound queries; plus `runScheduledTasks`, including superuser access,
  persistent timetables and retry state, database locks, priority ordering, execution results, and
  task collection, veto, start, and completion events.
- `CoreAdminHome.archiveReports`, including superuser access, strict site, period, date, segment,
  plugin, and report parsing, forced direct-request archiving, archive event dispatch, and the
  legacy archive ID and visit-count response; plus `runCronArchiving`, including a single-run lock,
  stale invalidation recovery, queued report and segment archives, per-site recent periods and
  automatic segments, local-day rollover handling, scheduled tasks, lifecycle events, and durable
  start and successful-completion timestamps.
- `Overlay`: both API methods, including the localized client key contract, site and global URL
  parameter exclusions, the configured pre-grouping limit, live following-page, outlink, and
  download rows, outer API row limits, and site view-access checks.
- `Tour`: all 3 API methods, including localized challenge state, extension events, and legacy per-user progress storage.
- `Transitions`: all 5 API methods, including URL and title action lookup, site-scoped access checks,
  period limits, live-log segment filtering, previous and following actions, loops, exits, entry
  referrers, per-type row grouping, partial reports, and the localized client key contract.
- `TwoFactorAuth`: `resetTwoFactorAuth`, including password confirmation and transactional recovery-code removal.
- `UserCountry`: all 8 API methods, including archive reports, localized location metadata, IP geolocation through the default, MaxMind database, or server-module provider, and protected provider selection.
- `Referrers`: all 7 distinct-count API methods, including segmented numeric archives, single-site,
  multi-site, and multi-date response shapes, access controls, and every supported response format.
  The overview API also combines per-type visits, distinct counters, processed percentages, and
  requested-column filtering. Campaign and campaign-keyword APIs include hierarchical archive
  expansion, direct subtable reads, processed metrics, segments, and response formats. Website and
  website-URL APIs include expanded and flat hierarchy reads, path grouping, decoded URL metadata,
  dimensions, and URL segments. Keyword and search-engine APIs include both archive orientations,
  direct and expanded subtables, flat dimensions, localized hidden keywords, canonical search URLs,
  logos, backlinks, and search segments. Social and social-URL APIs include dedicated archives,
  ordered definition IDs, normalized names, expansion, flat dimensions, metadata, URL segments, and
  conditional fallback to legacy website archives. AI-assistant and assistant entry-page APIs include
  URL and title archive orientations, optional direct subtable reads, expanded and flat dimensions,
  localized missing labels, metadata, segments, and conditional fallback to legacy website archives.
  Referrer-type and merged-all reports compose those ported reports with legacy type filtering,
  localized labels, direct type routing, recursion-safe expansion, and single-site/date limits.

Reporting API module matrix:

| Module | Legacy methods | Laravel handled | Remaining methods |
| --- | ---: | ---: | ---: |
| `AIAgents` | 1 | 1 | 0 |
| `AIProviders` | 4 | 4 | 0 |
| `API` | 19 | 6 | 13 |
| `Actions` | 18 | 18 | 0 |
| `Annotations` | 7 | 7 | 0 |
| `BotTracking` | 11 | 11 | 0 |
| `Contents` | 2 | 2 | 0 |
| `CoreAdminHome` | 13 | 13 | 0 |
| `CorePluginsAdmin` | 5 | 0 | 5 |
| `CustomDimensions` | 7 | 7 | 0 |
| `CustomJsTracker` | 1 | 1 | 0 |
| `DBStats` | 11 | 11 | 0 |
| `Dashboard` | 5 | 5 | 0 |
| `DevicePlugins` | 1 | 1 | 0 |
| `DevicesDetection` | 8 | 8 | 0 |
| `Events` | 9 | 9 | 0 |
| `ExampleAPI` | 9 | 9 | 0 |
| `ExamplePlugin` | 4 | 4 | 0 |
| `ExampleReport` | 1 | 1 | 0 |
| `ExampleUI` | 4 | 4 | 0 |
| `Feedback` | 3 | 3 | 0 |
| `Goals` | 12 | 12 | 0 |
| `ImageGraph` | 1 | 0 | 1 |
| `Insights` | 5 | 5 | 0 |
| `JsTrackerInstallCheck` | 2 | 2 | 0 |
| `LanguagesManager` | 9 | 9 | 0 |
| `Live` | 7 | 0 | 7 |
| `Login` | 1 | 1 | 0 |
| `Marketplace` | 5 | 0 | 5 |
| `MobileMessaging` | 12 | 0 | 12 |
| `MultiSites` | 3 | 3 | 0 |
| `Overlay` | 2 | 2 | 0 |
| `PagePerformance` | 1 | 1 | 0 |
| `PrivacyManager` | 18 | 0 | 18 |
| `ProfessionalServices` | 1 | 1 | 0 |
| `Referrers` | 23 | 23 | 0 |
| `Resolution` | 2 | 2 | 0 |
| `ScheduledReports` | 7 | 0 | 7 |
| `SegmentEditor` | 9 | 9 | 0 |
| `SitesManager` | 55 | 55 | 0 |
| `Tour` | 3 | 3 | 0 |
| `Transitions` | 5 | 5 | 0 |
| `TwoFactorAuth` | 1 | 1 | 0 |
| `UserCountry` | 8 | 8 | 0 |
| `UserId` | 1 | 1 | 0 |
| `UserLanguage` | 2 | 2 | 0 |
| `UsersManager` | 33 | 8 | 25 |
| `VisitFrequency` | 1 | 1 | 0 |
| `VisitTime` | 3 | 3 | 0 |
| `VisitorInterest` | 4 | 4 | 0 |
| `VisitsSummary` | 10 | 10 | 0 |
| **Total** | **389** | **296** | **93** |

The final parity gate requires every remaining counter to reach zero and the legacy entry files to be removed only after their Laravel replacements pass contract tests.

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
