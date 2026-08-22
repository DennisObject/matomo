# Laravel Port TODO

This is the remaining-work ledger for the Matomo-to-Laravel port. It describes `dev` after PR #185 (`70c1264e82`).

Last validated code baseline: 1,130 tests and 6,495 assertions passed. Pint, PHPStan, Rector, and all required GitHub checks also passed.

## Delivery order

- [ ] Keep every change in a small, coherent branch and PR based on `dev`.
- [ ] Mark each PR ready only when its required checks are green.
- [ ] Do not bypass required checks.
- [ ] Resolve review comments and related CI failures before each merge.
- [ ] Remove legacy entry points only after the matching Laravel runtime passes parity tests.

## Correct the migration ledger

- [ ] Re-run the public reporting API inventory and update `LARAVEL_MIGRATION.md`. Its checked commit and 378/389 table are stale; the route inventory now handles all 389 public method names.
- [ ] Replace broad "ported" statements with links to the tests or inventories that prove each surface.
- [ ] Record intentional compatibility limits and remove them as full parity is implemented.

## Reporting API fidelity

All 389 public method names are routed. Full response and side-effect parity is not yet proven.

- [ ] Complete segment metadata behavior for `_hideImplementationData`, `_showAllSegments`, plugin-defined segments, and permission filtering.
- [ ] Wire every generic CorePluginsAdmin setting definition and preserve plugin setting hooks.
- [ ] Match the complete processed-report wrapper: report metadata, totals, formatting, filters, related reports, and multi-period behavior.
- [ ] Make combined overview reports include every supported target report instead of skipping unsupported report shapes.
- [ ] Replace the limited segment-suggestion SQL allowlist with the complete safe legacy segment-value behavior.
- [ ] Complete row-evolution parity for metadata, totals, multi-row labels, compare periods, missing rows, and every output format.
- [ ] Run contract comparisons against the legacy runtime for all modules, formats, errors, headers, permissions, and mutation side effects.

## Tracking runtime

The Laravel endpoints now cover page views, events, downloads, outlinks, bulk requests, visit reuse, visitor context, custom dimensions and variables, referrer and campaign basics, performance timings, site search, content tracking, heartbeat, manual and automatic goals, ecommerce orders, items, and carts.

- [ ] Issue and read compatible first-party and optional third-party visitor cookies. Preserve cookie names, domains, paths, SameSite, Secure, expiry, opt-out, and consent behavior.
- [ ] Implement consent-required, consent-given, remembered-consent, cookie-consent, and privacy-manager request processing.
- [ ] Port trusted `token_auth`, `cip`, `cdt`, `cdo`, and visitor-ID overrides with the same authorization boundaries. Never trust privileged overrides anonymously.
- [ ] Port user-agent parsing and device, browser, engine, operating-system, bot, and configuration detection.
- [ ] Port geolocation provider selection, IP anonymization, country/language guessing, and location fields in the correct privacy order.
- [ ] Complete referrer classification for search engines, keywords, social networks, AI assistants, internal referrers, excluded referrers, and campaign attribution fields.
- [ ] Port campaign-attribution cookies and first/last attribution timestamps and visit counters.
- [ ] Persist action references, page-view IDs and positions, generation time, time spent, custom float behavior, and prior-action links.
- [ ] Match new-versus-returning visitor fields, visit counters, days-since fields, config IDs, visit timeouts, forced new visits, and out-of-order requests.
- [ ] Match action URL normalization, excluded query parameters, URL fragments, page-title behavior, site-search auto-detection, and unknown-URL policy.
- [ ] Complete automatic goals for visit duration and all legacy edge cases. Verify duplicate, repeatable, event-revenue, and ecommerce conversion rules.
- [ ] Complete ecommerce order uniqueness, abandoned-cart transitions, cart-to-order item movement, item updates, rounding, and buyer-state values.
- [ ] Port queued tracking, request authentication, tracking failure storage, spam prevention, request limits, response callbacks, and debug modes.
- [ ] Port all bundled tracker extension points and the tracker event/dimension extension contracts.
- [ ] Add legacy-versus-Laravel tracker fixture comparisons, concurrency tests, large bulk tests, and MySQL integration coverage.

## Archiving and reports

- [ ] Inventory all 18 bundled archivers by record name and mark each record as proven, incomplete, or missing.
- [ ] Port every remaining specialized plugin record and its day/period aggregation.
- [ ] Verify invalidation, archive locking, temporary/final archive states, blob encoding, numeric precision, segment hashes, and concurrent archiving.
- [ ] Verify all goal, ecommerce, referrer, action, event, content, device, location, custom-dimension, bot, and performance records against legacy fixtures.
- [ ] Prove bounded memory and query behavior on large sites, ranges, segments, and archive tables.

## Web runtime and UI

The current `/` route still returns a 503 foundation response. Only reporting and tracker HTTP controllers exist.

- [ ] Inventory and port all 47 legacy plugin controller files.
- [ ] Port the main authenticated application shell, dashboard, site selector, reporting navigation, administration navigation, login, logout, password reset, invitations, and two-factor flows.
- [ ] Port controller redirects, flash messages, form validation, CSRF behavior, content negotiation, error pages, and permission failures.
- [ ] Port Twig rendering or replace each view with an equivalent Laravel/Vue path while preserving safe escaping and extension hooks.
- [ ] Port the asset manager, JavaScript bootstrap data, CSP nonces, theme and branding assets, translations, icons, and cache busting.
- [ ] Keep Vue-first behavior for touched UI and remove legacy jQuery only when the replacement is covered.
- [ ] Port and run browser, DOM, accessibility, visual-regression, and JavaScript component tests.
- [ ] Replace the 503 root route only when the browser application is usable end to end.

## Console and scheduled work

`laravel/routes/console.php` is empty and there are no Laravel console command classes.

- [ ] Inventory and port all 129 bundled command files with names, arguments, options, exit codes, output, permissions, and failure behavior intact.
- [ ] Port console bootstrap, configuration checks, maintenance mode, plugin command discovery, and extension registration.
- [ ] Port all 15 bundled scheduled-task providers on top of the migrated persistent scheduler.
- [ ] Configure Laravel scheduling, locking, retry behavior, task priorities, time zones, and cron entry documentation.
- [ ] Add command and scheduler integration tests, including concurrent execution and failed-task recovery.

## Installation, updates, and schema

- [ ] Port clean installation: requirements, database setup, schema creation, first user/site, configuration writing, and completion checks.
- [ ] Port the CoreUpdater web and console lifecycles, maintenance mode, version detection, recovery, and post-update cache work.
- [ ] Port or safely execute all 191 current core and bundled-plugin update files in exact version order.
- [ ] Preserve state checks, idempotence, transactional boundaries, resumability, and large-table migration strategies.
- [ ] Add clean-install, old-version upgrade, interrupted-upgrade, retry, and current-schema no-op tests on MySQL/MariaDB.
- [ ] Do not create a parallel Laravel schema that diverges from the existing Matomo tables.

## Plugin platform

- [ ] Port plugin discovery, activation, deactivation, install, uninstall, dependency checks, version checks, and configuration.
- [ ] Port service-container extension, events, hooks, dimensions, reports, widgets, menus, commands, tasks, tracker processors, archivers, translations, settings, and assets.
- [ ] Define and test the Laravel compatibility layer for public PHP APIs and plugin entry points.
- [ ] Preserve deprecation behavior and avoid silent public API removals.
- [ ] Test bundled example plugins and representative third-party plugins through full lifecycles.

## Security and privacy

- [ ] Complete a route-by-route authentication, authorization, CSRF, request-trust, output-escaping, SQL, SSRF, upload, and secret-exposure audit.
- [ ] Verify password confirmation, secure token rules, brute-force limits, session invalidation, allowlisted IPs, trusted proxies, and trusted hosts in web, API, tracker, and console flows.
- [ ] Verify consent, opt-out, Do Not Track, anonymization, deletion, export, retention, log purging, and policy enforcement end to end.
- [ ] Verify no raw credentials, tokens, invite links, API keys, IPs, user IDs, or private report data leak through errors, logs, caches, headers, or responses.
- [ ] Run dependency audits, secret scanning, static analysis, and targeted adversarial tests before cutover.

## Performance and operations

- [ ] Benchmark tracking throughput, bulk tracking, API reads, archiving, scheduled tasks, and UI requests against the legacy runtime.
- [ ] Remove N+1 queries, unbounded result sets, repeated schema inspection, and request-path configuration parsing.
- [ ] Add production cache, queue, session, logging, metrics, health, readiness, and graceful-failure configuration.
- [ ] Document deployment, worker, scheduler, storage, proxy, TLS, backup, rollback, and disaster-recovery procedures.
- [ ] Prove zero-downtime compatibility during staged deployment and rollback.

## Documentation and repository metadata

- [ ] Rewrite the root `README.md` for the completed Laravel runtime. Remove staged-foundation and legacy-cutover wording after cutover.
- [ ] Expand `laravel/README.md` with installation, configuration, web server, queue, scheduler, build, test, upgrade, and deployment instructions.
- [ ] Keep the README and repository-facing project description free of the `matomo.org` website URL.
- [ ] Remove or replace the root Composer `homepage` value if root package metadata remains repository-facing. Preserve author, copyright, license, third-party attribution, and required legal notices.
- [ ] Keep `LICENSE` and all required notices unchanged unless a verified legal requirement calls for an additive notice.
- [ ] Update contributor, test, architecture, migration, release, and support documentation for the Laravel-only runtime.

## Final cutover and completion proof

- [ ] Make every required local and GitHub check green on the final stacked commit.
- [ ] Run full PHP, frontend, browser, API contract, tracker contract, install, upgrade, console, scheduler, archiver, security, privacy, and performance suites.
- [ ] Test a clean installation and at least one production-sized legacy database upgrade.
- [ ] Prove all public URLs, request parameters, response shapes, headers, permissions, schema data, archive records, plugin hooks, and CLI contracts are compatible.
- [ ] Remove fallback routing and legacy runtime code only after the proof above is recorded.
- [ ] Confirm the root README, Laravel README, repository description, package metadata, license, and legal notices satisfy the requested final state.
- [ ] Perform a requirement-by-requirement completion audit. Do not call the port complete while any item above is open or supported only by indirect evidence.
