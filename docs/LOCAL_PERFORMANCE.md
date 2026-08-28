# Aureus ERP Local Performance and Stability Audit

Audit date: 2026-07-22 to 2026-07-23
Workspace: `C:\laragon\www\aureuserp-master`
Branch: `accounting-stage2`
Baseline commit: `d72300d`

## Scope and safety

This was a stability and local-performance audit. Existing accounting behavior,
routes, permissions, calculations, report templates, Chart of Accounts data,
Trial Balance logic, and unfinished work were kept in place. No migration,
installer, destructive seeder, Git reset/clean/restore, dependency install, or
automatic commit was run.

Before application testing, a separate MySQL database named
`aureuserp_testing` was prepared from a consistent copy of the working database.
PHPUnit is forced to use that database, and the test bootstrap now refuses to run
unless the selected database is exactly `aureuserp_testing`. The old test helper
calls to `migrate:fresh` and `erp:install` were removed.

## Original stability status

- Laravel booted successfully at `http://aureuserp-master.test`.
- Laragon's virtual host points to `C:/laragon/www/aureuserp-master/public`.
- `php artisan about` passed on Laravel 13.8.0, Filament 5.6.3, Livewire 4.3.0,
  and PHP 8.3.30.
- `php artisan route:list --path=admin` returned 1,298 routes. All required
  accounting, reporting, Trial Balance, General Ledger, Balance Sheet, Profit
  and Loss, and Chart of Accounts Import routes were present.
- `php artisan migrate:status` showed all migrations applied, including the
  three Chart of Accounts migrations.
- `php artisan erp:doctor` passed. Products, accounts, and accounting were
  installed; the accounting tables and active admin user were valid.
- All six Stage 5 templates existed: `bs-group`, `cashflow-group`,
  `ridershipline-pnl`, `op-pnl`, `tin-pnl`, and `notes`.
- Read-only authenticated HTTP-kernel checks returned `200` for Accounting
  Overview, Financial Reports, Trial Balance, General Ledger, Balance Sheet,
  Profit and Loss, and Chart of Accounts Import. `/admin` and the Accounting
  cluster returned their expected redirects.
- The login page returned `200`. The saved handoff password did not match the
  current account, so an end-to-end credential submission was not repeated and
  no password was reset. `erp:doctor` independently confirmed that user 1 is
  active, verified, assigned to a company, has the Admin role, and has all 1,756
  permissions.
- The initial CoA-focused run passed 26 tests with 120 assertions. The CoA
  Preview, dependent reports, and export parity were covered by these tests.
- One transient Windows Blade compile race produced a baseline `500`
  (`rename(...storage/framework/views...): Access is denied`). Reloading worked,
  and precompiled views now prevent on-request compilation of those templates.
- No `ERROR`, `CRITICAL`, or `EMERGENCY` entry appeared in the final 1,000 log
  lines.

## Measurement method

The login page was measured through Apache with cURL and the existing Debugbar.
Authenticated pages were measured through the real Laravel HTTP kernel in fresh
PHP processes with user 1 set on the web guard. Query listening was temporary;
session and cache writes were redirected to in-memory drivers for the profiler.
The temporary profiler was deleted after use.

Windows Defender/filesystem cold-start variance was high. The table uses warmed,
comparable values where a warmed baseline was available. The first cold pass is
retained where that was the original recorded observation.

## Before and after page measurements

| Page | Before | After cached, warm | Before queries | After queries | Duplicate queries after | Peak memory after | Response size after |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| `/admin/login` (Apache/Debugbar) | 2.80 s | 2.73 s | 18 | 7 | 0 | 58.5 MB | 72.6 KB |
| `/admin` | 37.84 s cold | 3.20 s | 8 | 6 | 0 | 132 MB | 414 B |
| Accounting Overview | 10.91 s cold | 2.34 s | 10 | 8 | 0 | 136 MB | 105,349 B |
| Accounting landing redirect | 51.49 s cold | 2.15 s | 8 | 6 | 0 | 132 MB | 534 B |
| Financial Reports | 3.72 s warm | 2.53 s | 28 | 26 | 6 | 140 MB | 306,458 B |
| Trial Balance | 3.29 s warm | 2.47 s | 19 | 17 | 0 | 140 MB | 230,044 B |
| General Ledger | 3.52 s warm | 2.46 s | 13 | 11 | 0 | 140 MB | 277,864 B |
| Balance Sheet | 3.09 s warm | 2.69 s | 16 | 14 | 0 | 140 MB | 236,835 B |
| Profit and Loss | 3.05 s warm | 2.45 s | 14 | 12 | 0 | 140 MB | 222,699 B |
| Chart of Accounts Import | 3.38 s warm | 2.37 s | 14 | 12 | 0 | 140 MB | 255,137 B |

The warmed accounting pages improved by approximately 13% to 32%. The recorded
two-query reduction was consistent across authenticated routes, but the final
listener had to attach after framework bootstrap while the baseline also saw
boot activity. It is therefore reported conservatively and is not treated as a
proven application-query elimination. Financial Reports retained six duplicate
executions, but their warmed aggregate database cost was only about 20 ms before
optimization and 39 ms for all 26 queries after optimization. No query-result or
caching change was justified by that cost.

After several hours, the Apache worker grew to about 698 MB and login requests
varied from 7.8 to 13.9 seconds. This is the clearest remaining limitation:
Apache is executing PHP without OPcache, and the long-lived worker needs a
Laragon restart. A force-stop was not performed while concurrent user activity
was visible.

## Bottlenecks found

1. OPcache is not loaded by CLI or Apache, although `php_opcache.dll` is present.
   PHP/Filament/plugin loading dominates request time; warmed SQL is usually
   only 18 to 39 ms on the measured pages.
2. `APP_DEBUG=true` enables Debugbar. A baseline login request used about 114 MB,
   while the final cached request used 58.5 MB. Debugbar still adds collectors,
   response markup, file writes, and timing overhead.
3. The admin permission graph contains 1,756 permissions. The recurring Shield
   permission/role hydration query is normally the slowest warmed query, but it
   is still only about 7 to 14 ms. No permission behavior was changed.
4. The application has 1,298 admin routes and many plugin/service-provider files.
   Composer class-map scanning, Laravel boot, and Blade compilation are expensive
   on this Windows filesystem.
5. `public/hot` pointed at an inactive Vite server on port 5173. The stale,
   generated marker was removed, and the production Vite build is now used.
6. NativePHP mobile component discovery uses forward-slash path handling that
   does not register its intended component aliases on Windows. This caused
   `php artisan optimize` to fail while compiling views. Application-level
   aliases for the same NativePHP classes fixed view caching without changing
   component behavior.
7. `vendor/pest-plugins.json` is not writable by the restricted Codex process.
   `composer dump-autoload -o` completes its scan but exits 1 when Pest tries to
   update that generated file. The normal Windows user ACL has Modify access, so
   the batch file should be run from the user's Laragon terminal.
8. `storage/logs/laravel.log` grew from about 12.8 MB to 15.9 MB during debug and
   regression activity. It was preserved; no logs were deleted.
9. The serial plugin test suite is very large. The complete direct Pest run took
   12,296.87 seconds.

## Changes applied

- Removed the ignored, stale `public/hot` marker.
- Generated Laravel configuration, event, route, Blade view, icon, Filament, and
  settings caches with `php artisan optimize`.
- Built production frontend assets with Vite.
- Added NativePHP's intended Blade component aliases early enough for its
  Windows precompiler.
- Added `optimize-local.bat`, containing only approved non-destructive commands.
- Added an isolated PHPUnit database configuration and cache directory.
- Replaced destructive test bootstrap installation with a strict test-database
  guard and required-table checks.
- Added a test-only unlimited execution-time hook. The production panel's
  existing 300-second web limit is unchanged.
- Restarted an initially hung Apache instance once during the baseline audit.
  A second forced restart was deliberately avoided during concurrent activity.

No migration, database index, report cache, application-data cache, accounting
calculation change, route change, permission change, or business-logic change was
added by this audit.

## Cache and driver decisions

- The environment uses database cache, session, and queue drivers.
- Redis, APCu, and Redis PHP extensions are not installed. Redis was not added.
- The database driver was retained because its measured SQL cost is small and it
  avoids Windows file-lock contention. The array driver is reserved for tests
  because it is not persistent.
- A repository scan found no `env()` use outside configuration files in
  `app`, `bootstrap`, `routes`, or plugin application code, so configuration
  caching is compatible.
- Laravel documents that `optimize` caches configuration, events, routes, and
  views, and that `env()` should only be used in configuration after config
  caching: <https://laravel.com/docs/13.x/deployment> and
  <https://laravel.com/docs/13.x/configuration>.
- Laravel recommends precompiling Blade views to avoid request-time compilation:
  <https://laravel.com/docs/13.x/views#optimizing-views>.

## Regression results

| Command | Result |
| --- | --- |
| `composer dump-autoload -o` | Exit 1 after 179.2 s; optimized scan ran, then Pest metadata write was denied. Pre-existing optimized Composer files were preserved. |
| `php artisan optimize:clear` | Exit 0. |
| `php artisan test` | Exit 1 at 306.4 s because `AdminPanelProvider` sets a 300-second process limit. |
| `php vendor/bin/pest --compact` | Exit 1: 1,855 passed, 2 failed, 5,549 assertions, 12,296.87 s. |
| `npm.cmd run build` | Exit 0; 55 modules transformed, 180.1 s. |
| `php artisan optimize` | Exit 0; all cache stages passed, 108.6 s. |
| `php artisan erp:doctor` | Exit 0; all checks passed. |
| `vendor\bin\pint.bat --dirty --format agent` | Exit 0. |

The two full-suite failures were unrelated pagination assumptions against the
realistic cloned test data:

- Partner API list expected two newly created partners on the first page while
  27 active partner rows already existed.
- Inventory location API list expected a newly created location on the first
  page while 20 active location rows already existed.

The assertions were not weakened. The initial CoA-only run passed all 26 tests
and 120 assertions. A test-bootstrap smoke run passed 14 tests and 15 assertions.
Laravel's testing documentation confirms that testing environment values belong
in `phpunit.xml` and configuration caches should be cleared before tests:
<https://laravel.com/docs/13.x/testing#environment>.

## Database integrity evidence

The critical accounting fingerprint was identical before and after:

| Item | Before | After |
| --- | ---: | ---: |
| Plugins | 19 | 19 |
| Report templates | 6 | 6 |
| Report lines | 180 | 180 |
| Report columns | 18 | 18 |
| Accounts | 246 | 246 |
| Account moves | 3 | 3 |
| Account move lines | 21 | 21 |
| CoA import batches | 1 | 1 |
| Ledger debit | 1,505,000.0000 | 1,505,000.0000 |
| Ledger credit | 1,505,000.0000 | 1,505,000.0000 |

The company count changed from 15 to 19 during the three-hour regression run.
The four new rows were named `Open Port`, `Shipline`, `TIN`, and `TIN Pte`, with
deliberate names and staggered timestamps consistent with concurrent user work.
Before final handoff, `Open Port`, `Shipline`, and `TIN` were concurrently
soft-deleted, leaving a final active-company count of 16. `TIN Pte` remained
active. These rows were preserved and were not treated as test artifacts.

## PHP and Laragon status

- CLI PHP: 8.3.30 ZTS, Visual C++ 2019 x64.
- Apache: 2.4.66, using the same PHP 8.3.30 through
  `php8apache2_4.dll`.
- Active `php.ini`:
  `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.ini`.
- Apache `PHPIniDir` points to the same PHP directory.
- Xdebug is not loaded.
- OPcache is not loaded.
- Redis and APCu are not loaded.
- No PHP preloading is configured; preloading should not be used for this Windows
  setup.
- The virtual host correctly uses the project `public` directory and the site is
  served through Laragon, not `php artisan serve`.

## Manual PHP settings

Edit the active `php.ini` and add or update the following once. The OPcache DLL
already exists in the active PHP `ext` directory.

```ini
zend_extension=php_opcache.dll

[opcache]
opcache.enable=1
opcache.enable_cli=0
opcache.memory_consumption=256
opcache.interned_strings_buffer=32
opcache.max_accelerated_files=20000
opcache.validate_timestamps=1
opcache.revalidate_freq=2
opcache.save_comments=1
opcache.jit=0
```

Keep timestamp validation enabled for local development. Do not add
`opcache.preload` on Windows. PHP recommends enabling OPcache on Windows, and
the directive behavior is documented at
<https://www.php.net/manual/en/install.windows.recommended.php> and
<https://www.php.net/manual/en/opcache.configuration.php>.

After saving `php.ini`, use **Laragon > Menu > Restart All**. In Laragon's PHP
information page, confirm that an **Zend OPcache** section exists and that
`opcache.enable` is On. From a terminal, confirm that Xdebug remains absent:

```bat
php -m | findstr /i "opcache xdebug"
```

The CLI check should show OPcache after loading the extension and should not show
Xdebug. `opcache.enable_cli=0` is intentional; Apache still uses OPcache.

For the fastest daily local browsing, optionally set the following in `.env`:

```dotenv
APP_DEBUG=false
DEBUGBAR_ENABLED=false
```

This optional change was not applied automatically because it changes debugging
visibility. After any `.env` change, rerun `optimize-local.bat`.

## Local startup procedures

### Normal fast startup

1. Start Laragon with **Start All**.
2. Browse to `http://aureuserp-master.test/admin/login`.
3. Keep Laravel caches and the Vite production build in place.
4. Do not run `php artisan serve` for normal Laragon use.

### After pulling or changing PHP, routes, config, providers, or Blade views

Run from a normal Laragon terminal:

```bat
optimize-local.bat
```

The script runs, in order:

```bat
composer dump-autoload -o
php artisan optimize:clear
php artisan optimize
npm.cmd run build
```

It stops at the first error. It never installs dependencies, migrates, seeds,
installs plugins, or changes database records.

### Frontend development mode

1. Run `php artisan optimize:clear` before active route/config/view development.
2. Run `npm.cmd run dev` while editing frontend assets.
3. Stop Vite when finished.
4. Run `npm.cmd run build` and `php artisan optimize` before returning to fast
   local mode.
5. If `public/hot` remains after Vite has stopped, remove that generated marker
   so Laravel uses `public/build/manifest.json`.

### When to restart Apache

Restart Laragon after changing `php.ini`, enabling/disabling an extension,
changing PHP versions, or when the Apache worker has accumulated unusually high
memory/CPU and requests become progressively slower.

## Remaining limitations

- OPcache must still be enabled manually and Apache restarted. This is the
  largest expected improvement.
- Debugbar remains enabled while `APP_DEBUG=true`.
- The long-lived Apache worker can accumulate hundreds of megabytes without
  OPcache and restart hygiene.
- Composer/Pest generated vendor files are not writable by the restricted Codex
  identity; run the optimizer from the normal user terminal.
- The full serial suite takes more than three hours on this machine.
- Two API pagination tests assume sparse fixture data and fail against a realistic
  cloned database; assertions were intentionally left unchanged.
- Vite reports a pre-existing CSS minification warning for an unexpected `)` in
  a generated selector, but the build succeeds.
- The active admin password was not available, so credential submission and a
  fully interactive authenticated browser walkthrough remain manual checks.
- The in-app browser webview failed to attach during the final retry; direct HTTP
  and authenticated-kernel checks remained available and successful.

---

## Multi-Currency Verification Addendum (2026-07-28)

The multi-currency implementation adds approved-rate indexes and a versioned
lookup cache, bulk ISO upsert, paginated/searchable currency management, and
asynchronous company-scoped account/currency selectors. Report cache keys now
include original currency context. These changes reduce repeated lookup work and
avoid preloading every account or currency.

Verification on this machine:

| Check | Result |
|---|---:|
| Accounting suite before the multi-currency expansion | 121 tests / 584 assertions / 213.75 s |
| Accounting suite after the expansion | 128 tests / 627 assertions / 710.66 s |
| Support feature suite | 97 tests / 700 assertions / 332.93 s |
| Production Vite build | passed / 34.04 s |
| Accounting route discovery | passed / 146 routes |

The accounting runs are not an apples-to-apples performance comparison: the
later suite contains additional migration, permission, ISO, rate, bank, FX, and
report-mode coverage and ran under different warmed state. The longer duration
means no performance improvement is claimed. The requested comparable timings
for bank import, currency search, mapping, cold/warm rate lookup, 1,000-row
preview, posting, GL, Trial Balance, and exports remain outstanding.

The import service remains row-oriented even though ISO synchronization is a
bulk upsert. Chunked/bulk import writes and a reproducible benchmark harness are
the next performance work. Keep `APP_DEBUG=false`, Debugbar disabled, Composer's
optimized autoloader, production assets, and Laragon OPcache in place for manual
page measurements; do not change accounting caches without rechecking company
switching and rate invalidation.
