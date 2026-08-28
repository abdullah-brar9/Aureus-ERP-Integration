# Aureus ERP — Accounting / Reporting Project Handoff

> **2026-07-28 configurable import checkpoint:** the forward-only configurable
> import/FS Tag platform and canonical adapters are implemented. See
> `docs/CONFIGURABLE_IMPORT_PLATFORM.md`. The verified pre-migration backup is
> `storage/app/backups/pre-data-platform-20260728-222706/aureuserp.sql` with
> SHA-256 `7DB99FE9EC09F1AD70180313AC1689A5B443CA5442A636137EFF867D7C79BD70`.
>
> Audited against the working tree on branch `accounting-stage2` (protected base
> commit `d72300d`). Every claim below was verified by reading the current files,
> not from memory. Nothing is committed — all work is in the working tree.

---

## 1. Project Overview

### Purpose
Aureus ERP is a Laravel + Filament ERP (a Webkul "AureusERP" distribution). This
project extends its **Accounting** plugin with a **configurable financial
reporting platform** and, most recently, a **Chart-of-Accounts (CoA) importer +
ledger-backed Trial Balance** pipeline.

The end-to-end goal: take a client's exported Chart-of-Accounts workbook
(CSV/XLSX with opening/movement/adjustment/closing balances), import the account
hierarchy, post the balances as real balanced journal entries, and then produce
a **Trial Balance and all dependent statements (General Ledger, Balance Sheet,
P&L, and the Stage-5 configurable Financial Reports) from the posted ledger** —
never from numbers stored on the account records.

### Architecture
- **Monorepo of Webkul plugins** under `plugins/webkul/*`, auto-discovered via
  `composer` merge-plugin. Relevant plugins: `accounting` (reporting engine +
  CoA importer, this project's home), `accounts` (the ledger: accounts, moves,
  move-lines, journals), `support` (foundation: company, currency, country),
  `security` (users, roles, Filament Shield permissions), `products`.
- **Filament v5** admin panel at `/admin`. Pages/Resources are grouped into
  **clusters** (`Reporting`, `Configuration`). Page/resource access is gated by
  **Filament Shield** permissions (`page_accounting_*`).
- **Ledger data model** (owned by the `accounts` plugin):
  - `accounts_accounts` — chart of accounts. Linked to companies **many-to-many**
    via `accounts_account_companies` (there is **no `company_id` column** on the
    account itself). Has `parent_id` (self-referencing tree), nullable `code`,
    `name`, `account_type` (enum, NOT NULL), `deprecated`.
  - `accounts_account_moves` — journal entries (header). `company_id`, `date`,
    `journal_id`, `state` (draft/posted), `move_type`.
  - `accounts_account_move_lines` — journal lines. `move_id`, `account_id`,
    `debit`, `credit`, `balance`, `company_id`, `date`.
  - `accounts_journals` — journals; `type` includes `general` (misc).
- **Reporting engine** (Stages 2–3.5, pre-existing in this branch): configurable
  report **templates → lines → columns**, with a formula evaluator, a
  `ReportCalculationEngine`, a `LedgerBalanceRepository`, and (Phase 0) a generic
  `MeasureResolver` seam that routes value requests to a source-specific resolver.

### Key technologies / libraries
- PHP 8.3, Laravel 12/13-era, Filament v5, Livewire 3.
- **Pest 4** for tests (`it()` / `test()` style).
- **PhpSpreadsheet** (via `maatwebsite/excel`) for XLSX read + Excel export;
  native `fgetcsv` for CSV.
- **barryvdh/laravel-dompdf** for PDF export.
- **spatie/laravel-permission** + **bezhansalleh/filament-shield** for RBAC.
- **Laravel Pint** for code style; **Larastan/PHPStan** available.

### Design patterns / conventions established
- **Service objects, single-responsibility.** The CoA pipeline is a chain of
  small services (reader → header detector → parser → validator → planner →
  type mapper → import service → migration-journal service), each independently
  testable, communicating through immutable **`readonly` data objects**
  (`CoaRow`, `CoaColumnMap`, `CoaWarning`).
- **Reports read from posted ledger lines only.** Balances are never persisted
  onto accounts. The importer's job is to create *journals*; every report then
  aggregates those journals.
- **Transactional + idempotent writes.** Imports run inside `DB::transaction`;
  re-running the same import creates no duplicates (matched by company+code /
  company+path / company+migration-kind).
- **Never guess, never overwrite.** Suspicious data is *flagged as warnings*, not
  silently corrected. The importer refuses to modify accounts it did not create
  (`import_batch_id IS NULL`).
- **Forward-only, additive migrations** guarded by `Schema::hasColumn`. No
  destructive migrations; the base commit `d72300d` is protected.
- **Company isolation everywhere** — every query is scoped by company (M2M pivot
  for accounts, `company_id` for moves).
- **PHPDoc class headers** explain *why*, matching the surrounding house style.

---

## 2. Completed Work

The project has two arcs: the **reporting engine (Stages 2–5, + Phase 0 seam)**
which was largely already in the branch, and the **CoA importer + Trial Balance
(Phases 0–7)** which is the most recent body of work. All phases are complete in
the working tree.

### Reporting engine (Stages 2–5) — pre-existing in branch `accounting-stage2`
- **What/why:** a configurable report designer (templates, lines, columns,
  formulas, value bases) so finance can define Balance Sheet / P&L / cash-flow
  style statements without code. Committed as `d72300d` ("Stage 5:
  Production-ready reporting module").
- **Key artifacts (still present):** `accounting_report_templates` / `_lines` /
  `_columns` / `_line_accounts` / `_line_formulas` / `_line_inputs` tables;
  `ReportCalculationEngine`, `ReportQueryService`, `ReportColumnResolver`,
  `ReportTemplateValidator`, `FormulaEvaluator`, `LedgerBalanceRepository`;
  Filament pages `FinancialReports`, `ReportMappingReview`, `ExternalProviders`
  and the `ReportTemplateResource`; `ReportWorkbookSeeder` (6 templates:
  `bs-group`, `cashflow-group`, `ridershipline-pnl`, `op-pnl`, `tin-pnl`,
  `notes`).
- **Known limitation:** the six seeded templates are the client's **TIN workbook**
  layouts. They are not auto-bound to arbitrary imported accounts (see §9).

### Phase 0 — Generic data-resolution seam (`MeasureResolver`)
- **What:** an abstraction so the engine can request values from any source
  (ledger today; imported datasets, manual inputs, external APIs later) without
  engine changes.
- **Why:** future-proofs the "data platform vision" (`docs/data-platform-vision.md`)
  without speculative code — only the ledger resolver is wired today.
- **Files:** `src/Contracts/MeasureResolver.php` (interface: `source()` +
  batch `resolve()`), `src/Data/{MeasureReference,DimensionFilter,ResolutionContext,ResolvedSeries}.php`,
  `src/Services/MeasureResolverRegistry.php` (source→resolver router),
  `src/Services/Resolvers/LedgerMeasureResolver.php` (wraps
  `LedgerBalanceRepository`, owns no SQL). `ReportCalculationEngine` gained an
  optional 5th constructor arg (`?MeasureResolverRegistry`, defaults to a
  ledger-only registry) and routes ledger reads through
  `->for(MeasureReference::SOURCE_LEDGER)`.
- **Decision:** batch-oriented `resolve()` preserves the engine's exact query
  count — the seam adds no N+1. Registered as a singleton in
  `AccountingServiceProvider::packageRegistered()`.

### CoA Phase 0 — Verify & stabilize the ERP (no rebuild)
- **What/why:** the authoritative DB `aureuserp` had been rebuilt blank (login
  failed, panel empty). Restored it non-destructively.
- **Files:** `app/Console/Commands/ErpDoctor.php` (read-only health check: DB,
  foundation, plugins, core tables, admin access) and
  `app/Console/Commands/ErpRepairAccess.php` (idempotent: ensures Admin role has
  all permissions, ensures one active/verified admin user with default+allowed
  company, `resource_permission=GLOBAL`; creates a user only if none exists;
  never deletes anything).
- **Decision:** helper methods are `pass()/flag()/bad()` because
  `Illuminate\Console\Command` already defines public `fail()`/`warn()`.

### CoA Phase 1 — Chart-of-Accounts domain (schema deltas)
- **Three forward-only migrations** (all `Schema::hasColumn`-guarded):
  - `2026_07_20_000001` — adds `is_group` (bool, non-postable classification
    node), `source_classification_path` (text), `import_batch_id` (indexed,
    unconstrained) to `accounts_accounts`.
  - `2026_07_20_000002` — creates `accounting_coa_import_batches`.
  - `2026_07_20_000003` — adds `coa_migration_kind` + `coa_import_batch_id`
    (both nullable, indexed) to `accounts_account_moves`.
- **`Account` model** (`plugins/webkul/accounts/src/Models/Account.php`) gained
  the three fields in `$fillable`, an `is_group` boolean cast, and a
  `scopePostable()` = `where('is_group', false)`.
- **`CoaImportBatch` model** (`plugins/webkul/accounting/src/Models/`) — one row
  per import run (company, currency, creator, filename, mode, status, dates,
  counts, `options` JSON holding acknowledged warnings + type overrides).

### CoA Phase 2 — Import workflow
- **Data objects** (`src/Data/Coa/`): `CoaRow`, `CoaColumnMap`, `CoaWarning`.
- **Services** (`src/Services/Coa/`):
  - `CoaSheetReader` — CSV (`fgetcsv`) or XLSX (PhpSpreadsheet) → array of rows.
  - `CoaHeaderDetector` — finds the real header row (first row containing
    `Nature`+`Code`+`Title`), discovers variable `Classification N` columns, and
    fixes the 8 balance columns (Opening/Movement/Adjustment/Closing ×
    Debit/Credit) immediately after `Title`.
  - `CoaSheetParser` — rows+map → `CoaRow[]`; lenient number parsing (`$`, commas,
    `(...)` negatives, `-` placeholder).
  - `CoaRowValidator` — flags issues **without changing data**. Hard errors
    (block import): empty code/title, duplicate code within file. Warnings
    (acknowledge only): non-numeric code, misspellings (Libailities, Intanbgible,
    Guarrantee), duplicate titles, nature/section mismatches, input-tax-under-
    liability, provision-under-asset, cost-as-revenue.
  - `CoaHierarchyPlanner` — flat rows → de-duplicated group nodes (keyed by full
    path, ordered parents-before-children) + one leaf per row.
  - `CoaAccountTypeMapper` — suggests an `AccountType` from title/path keywords
    with a Nature-based fallback (suggestions only; overridable by the service).
  - `CoaImportService` — transactional, idempotent orchestrator (see §5/§4).
- **Filament** (`Configuration` cluster):
  - `ImportChartOfAccounts` page — upload → choose company/currency/mode/dates →
    **Preview/Dry-run** (counts, provisional sheet totals, warnings list) →
    **Import** (confirm modal). Permission `page_accounting_import_chart_of_accounts`.
  - `CoaImportHistoryResource` — read-only batch list with per-batch warnings-CSV
    download. Permission from the resource.
- **Limitation:** the page applies the type mapper's **suggestions automatically**;
  it does **not** yet expose an interactive per-account type-override grid, even
  though `CoaImportService::import()` accepts `$typeOverrides`. (See §7/§8.)

### CoA Phase 3 — Balance migration journals
- **`MigrationJournalService`** builds **three balanced, posted** journals
  (Opening / Movement / Adjustment) from the sheet balances.
- **Critical decision — raw `DB::table` inserts:** `MoveLine`'s `saving` hook
  recomputes `account_id` from the journal's `default_account_id`, which would
  corrupt a multi-account migration journal. So lines are inserted with every
  column set explicitly, bypassing the model hook.
- Each journal must balance (Σdebit = Σcredit within 0.005) or the whole import
  **rolls back** — no artificial balancing account is ever invented. Idempotent:
  skips a journal kind if one already exists for the company. Uses the first
  `general`-type journal. Tags each move with `coa_migration_kind`.

### CoA Phase 4 — Ledger-backed Trial Balance
- **`TrialBalanceService`** computes the TB from posted move-lines with exactly
  **two aggregate SQL queries** (opening-before-date + in-range movement/adjust
  split by `CASE WHEN coa_migration_kind='adjustment'`) — never a query per
  account. Company-isolated. Optional hierarchy-group aggregate rows are
  display-only and excluded from grand totals (no double counting).
- **`TrialBalance` Filament page** (rewritten from the old hard-coded page) under
  the **Reports** group, with filters (company, from/to, journals, posted-only,
  show-zero, show-groups) and **Excel + PDF export** header actions.
- `TrialBalanceExport` (Excel) + `pdfs/trial-balance.blade.php` + screen blade.

### CoA Phase 5 — Dependent report integration
- Verified that imported accounts + posted migration journals flow through the
  existing `GeneralLedger`, `BalanceSheet`, `ProfitLoss` pages and the Stage-5
  engine (all read the same posted lines). Navigation for Balance Sheet / P&L /
  General Ledger moved from **Legacy Reports** to **Reports** (in
  `resources/lang/en/filament/clusters/reporting.php` lines 33/100/128).
  Aged Payable/Receivable and Partner Ledger stay under **Legacy Reports**.
- **Deliberately NOT done:** auto-binding imported accounts to the 6 seeded
  Stage-5 templates (they are TIN-specific; binding would be guessing). Left for
  Mapping Review. (See §9.)

### CoA Phase 6 — Exact-file acceptance
- `CoaImportAcceptanceTest` drives the real `Chart_of_Accounts_Trial_Balance_Test.csv`
  and asserts header on row 5 (0-indexed 4), **62 leaf accounts**, the warning
  set, 3 balanced journals, exact totals (Opening 800k/800k, Movement 685k/685k,
  Adjustment 20k/20k, Closing 1,170,000/1,170,000, difference 0), and 16 named
  non-zero closing balances. Plus idempotency, company isolation, draft
  exclusion.
- `CoaDependentReportsTest` — ledger balances (accounting equation nets to 0),
  GL↔TB reconciliation per account, statement pages render (no 403/500), and
  screen-totals == Excel-export totals.

### CoA Phase 7 — Execute & verify
- Ran `composer dump-autoload`, `optimize:clear`, `migrate` (3 CoA migrations),
  `erp:doctor` (healthy), the accounting test suite, `npm run build`, and route
  smoke checks. No auto-commit.

---

## 3. Current State

- **No task is in progress.** All 27 tracked tasks are `completed`; the CoA
  Phases 0–7 and the reporting Stages 2–5 + Phase 0 seam are fully implemented
  in the working tree.
- **Nothing is committed.** Per instruction, all changes sit uncommitted on
  `accounting-stage2` above the protected commit `d72300d`.
- **No unfinished code / no in-code `TODO`/`FIXME`** in the new CoA or command
  files (verified). The one intentional *product* gap is the missing interactive
  type-override UI on the import page (the service already supports it).
- **Files most recently touched** (by the user or a linter, per the editor — kept
  as-is): the CoA services, `TrialBalanceService`, `MigrationJournalService`,
  `TrialBalance` page + export, the two CoA tests, and several Stage-2/3.5
  reporting files. These edits are consistent with the described behavior.

### Git status (working tree)
Branch `accounting-stage2`; base `d72300d` intact; **uncommitted**.

**Modified (8 tracked):**
```
plugins/webkul/accounting/resources/lang/en/filament/clusters/reporting.php
plugins/webkul/accounting/resources/views/filament/clusters/reporting/pages/pdfs/trial-balance.blade.php
plugins/webkul/accounting/resources/views/filament/clusters/reporting/pages/trial-balance.blade.php
plugins/webkul/accounting/src/AccountingServiceProvider.php
plugins/webkul/accounting/src/Filament/Clusters/Reporting/Pages/Exports/TrialBalanceExport.php
plugins/webkul/accounting/src/Filament/Clusters/Reporting/Pages/TrialBalance.php
plugins/webkul/accounting/src/Services/ReportCalculationEngine.php
plugins/webkul/accounts/src/Models/Account.php
```
**New / untracked (highlights):** `Chart_of_Accounts_Trial_Balance_Test.csv`;
`app/Console/Commands/{ErpDoctor,ErpRepairAccess}.php`; the three
`2026_07_20_*` migrations; `src/Data/Coa/*`, `src/Data/{MeasureReference,DimensionFilter,ResolutionContext,ResolvedSeries}.php`;
`src/Contracts/MeasureResolver.php`; `src/Services/Coa/*`,
`src/Services/{TrialBalanceService,MeasureResolverRegistry}.php`,
`src/Services/Resolvers/*`; `src/Models/CoaImportBatch.php`; the
`ImportChartOfAccounts` page + blade and `CoaImportHistoryResource` (+ page);
tests `CoaImportAcceptanceTest`, `CoaDependentReportsTest`,
`StageFiveReportVisibilityTest`, `MeasureResolverTest`; docs
(`COA_TRIAL_BALANCE_IMPLEMENTATION_PLAN.md`, `data-platform-vision.md`,
`PHASE0_CHANGELOG.md`, `PHASE0_VERIFICATION.md`, this file).

---

## 4. Remaining Roadmap (execution order)

The core deliverable is done. These are the value-adding follow-ups.

### R1 — Interactive account-type mapping UI on the import page
- **Objective:** let the user review and override each account's suggested
  `AccountType` before importing (the service already accepts `$typeOverrides`).
- **Approach:** after `preview()`, render an editable table (code, title,
  suggested type as a `Select` of `AccountType` cases) bound to page state; pass
  the edited map as `typeOverrides` into `CoaImportService::import()`.
- **Dependencies:** `CoaImportService` (ready), `CoaAccountTypeMapper::suggest()`,
  `preview()['type_preview']` (already returns code→type).
- **Files:** `ImportChartOfAccounts.php` + its blade; possibly a small Livewire
  repeater component.
- **Complexity:** Medium.
- **Risks:** large charts (62+ rows) need a scrollable/paginated grid; keep the
  override map in sync with the uploaded file (invalidate on re-upload).

### R2 — Account → Stage-5 template binding (Mapping Review)
- **Objective:** bind imported accounts to Balance-Sheet / P&L report lines so the
  configurable Financial Reports populate from the imported ledger.
- **Approach:** in `ReportMappingReview`, list unmapped postable accounts and let
  the user attach each to a `ReportLine` via `ReportLineAccount`. Optionally
  auto-suggest by `account_type`. **Never overwrite existing mappings**; version
  a template (immutability rules, §5) if structure must change.
- **Dependencies:** Stage-5 `ReportLine`/`ReportLineAccount`, `AccountBindingService`.
- **Files:** `ReportMappingReview.php` (+ blade), maybe `AccountBindingService`.
- **Complexity:** Medium–High.
- **Risks:** business decision (which account belongs on which statement line) —
  requires Finance sign-off; do not guess.

### R3 — Import reversal / rollback of a specific batch
- **Objective:** undo a completed import (reverse its journals, optionally
  soft-remove the accounts it created).
- **Approach:** add a batch action that reverses the 3 migration moves (standard
  ledger reversal) and marks the batch reversed; accounts are identifiable by
  `import_batch_id`. Keep it transactional + idempotent.
- **Dependencies:** `CoaImportBatch`, move-reversal capability in `accounts`.
- **Files:** `CoaImportHistoryResource` (record action), a new
  `MigrationJournalReversalService`.
- **Complexity:** Medium.
- **Risks:** must not reverse journals that already have downstream postings;
  guard against double-reversal.

### R4 — Opening-balance date convention
- **Objective:** remove the "run TB from 07-02" foot-gun (see §5/§7).
- **Approach:** either date the opening journal to the day *before* the period
  start, or make the TB "opening" bucket `<= fromDate` for the opening kind.
- **Dependencies:** `MigrationJournalService`, `TrialBalanceService`.
- **Files:** those two services + their tests.
- **Complexity:** Low.
- **Risks:** changing the convention shifts every acceptance number — update
  tests deliberately, with Finance agreement.

### R5 — Multi-sheet / multi-company workbook ingestion
- **Objective:** the source workbook has several TIN sheets (Paid/Topay, Costing,
  S.tax/I.Tax, Dispatch); support importing each into the right scope.
- **Approach:** extend `CoaSheetReader` to enumerate sheets; add a sheet picker to
  the import page; reuse the whole downstream pipeline per sheet.
- **Complexity:** Medium.
- **Risks:** sheet-specific header quirks; keep header detection tolerant.

### R6 — Commit & branch hygiene
- **Objective:** the entire body of work is uncommitted. Commit in logical
  chunks (seam / commands / CoA domain / importer / TB / reports / tests / docs).
- **Complexity:** Low. **Risk:** none if done in reviewable commits; the user has
  reserved commit authority — **do not auto-commit**.

---

## 5. Important Context (a new session MUST know this)

### Business rules
- **The Trial Balance is the source of truth for the exact numbers.** For the
  acceptance file the totals are immutable: Opening **800,000/800,000**, Movement
  **685,000/685,000**, Adjustment **20,000/20,000**, Closing
  **1,170,000/1,170,000**, difference **0**, across **62 leaf accounts**.
- **Every migration journal must balance** or the import rolls back. No plug/
  suspense account is ever fabricated.
- **Suspicious data is flagged, never auto-corrected** (misspellings,
  misclassifications, non-numeric codes, duplicates). Only empty code/title and
  in-file duplicate codes are *blocking errors*.
- **The importer never touches accounts it didn't create** (`import_batch_id IS
  NULL` ⇒ skip). Re-import is idempotent.

### Reporting logic (Trial Balance formula — `TrialBalanceService`)
```
openingNet      = Σ(debit − credit) for moves with date < fromDate
movementDr/Cr   = Σ debit/credit in [fromDate,toDate] where kind <> 'adjustment'
adjustmentDr/Cr = Σ debit/credit in [fromDate,toDate] where kind  = 'adjustment'
closingNet      = openingNet + movementDr − movementCr + adjustmentDr − adjustmentCr
debit column    = max(net, 0)   credit column = max(−net, 0)
```
- **Movement vs adjustment is split by `coa_migration_kind`, NOT by date.** Both
  the movement and adjustment migration journals fall inside the period; only the
  flag separates them.
- **Opening is strictly `date < fromDate`.** Because the opening journal is dated
  `2025-07-01`, the acceptance TB is run **from `2025-07-02`** so the opening
  entry lands in the Opening column. This is the current convention (see R4).
- Posted-only by default (`state = posted`); draft moves are excluded.

### Data model assumptions
- **Accounts ↔ companies is many-to-many** (`accounts_account_companies`). There
  is **no `company_id` on `accounts_accounts`.** Always scope accounts with
  `whereHas('companies', fn($q) => $q->where('companies.id', $companyId))`.
- **Moves/lines DO have `company_id`.**
- `account_type` is **NOT NULL** (enum `Webkul\Account\Enums\AccountType`);
  `code` is nullable (group nodes have `code = null`).
- **Group nodes** (`is_group = true`) are non-postable classification containers;
  **leaves** (`is_group = false`, `scopePostable()`) are the only accounts
  journal lines may reference.

### `AccountType` enum values (categories used by BS/P&L bucketing)
`asset_receivable, asset_cash, asset_current, asset_non_current, asset_prepayments,
asset_fixed, liability_payable, liability_current, liability_non_current, equity,
equity_unaffected, income, income_other, expense, expense_depreciation,
expense_direct_cost, off_balance`. BS/P&L classification uses the prefix before
the first `_` (asset/liability/equity/income/expense).

### Source-file shape (`Chart_of_Accounts_Trial_Balance_Test.csv`)
- Rows 1–3: metadata (`TRUCK IT IN PVT LTD`, `Chart of Accounts`, `FY25`).
- Row 4: a band row spanning Opening/Movement/Adjustment/Closing.
- **Row 5 (0-indexed 4) is the real header:** `Nature, Classification 1..5,
  Classification 7, Code, Title, [Opening D, Opening C, Movement D, Movement C,
  Adjustment D, Adjustment C, Closing D, Closing C]`. Note the source **skips
  "Classification 6"** — column detection must not assume contiguous numbering.
- 62 data rows. Codes are mostly numeric (`1011`…) with three deliberate
  `New code 1/2/3` non-numeric outliers.
- `Nature` is `B/S` or `P&L`; the group hierarchy is `Nature > Classification…`,
  with consecutive duplicate segments collapsed (`Cost > Cost` ⇒ one node).

### Permission model
- Filament Shield; page permissions like `page_accounting_trial_balance`,
  `_balance_sheet`, `_profit_loss`, `_general_ledger`, `_financial_reports`,
  `_report_mapping_review`, `_external_providers`,
  `_import_chart_of_accounts`.
- `User::canAccessPanel()` returns `is_active`. Admin access is repaired
  idempotently by `php artisan erp:repair-access` (Admin role gets all
  permissions; user gets default+allowed company, `resource_permission=GLOBAL`).
- Default admin after repair: `admin@example.com` / `password`.

### Navigation groups
- **Reports:** Financial Reports, Trial Balance, Balance Sheet, Profit & Loss,
  General Ledger.
- **Report Administration:** Report Templates, Mapping Review, External Providers.
- **Legacy Reports:** Aged Payable, Aged Receivable, Partner Ledger.
- **Configuration:** Import Chart of Accounts, Import History (+ existing config
  resources).

### Versioning / immutability rules (Stage 5 — do not violate)
- Report templates support publish/archive/new-version with immutability once
  published. **Never mutate a published template's structure in place** — create
  a new version. **Never overwrite existing account→line mappings.**

### Things that must NEVER change
- The **protected base commit `d72300d`.**
- The **authoritative DB is `aureuserp`** (never switch to `aureuserp_last`).
- **No destructive commands ever:** `migrate:fresh/refresh/rollback`, `db:wipe`,
  `erp:install`, destructive seeders, `git reset --hard`, `git clean`, discarding
  checkouts/restores. **Forward-only migrations, idempotent logic.**
- **Do not weaken tests** or suppress failures. **Do not auto-commit.**
- The **raw-insert approach in `MigrationJournalService`** (bypassing the
  `MoveLine::saving` hook) is load-bearing — do not "clean it up" into
  model saves or the account_id will be overwritten.

---

## 6. Testing

### Suites & coverage
- **18 feature test files, 93 test cases** in
  `plugins/webkul/accounting/tests/Feature/` (Pest). New/most-relevant:
  - `CoaImportAcceptanceTest` (6) — the exact-file acceptance: header row, 62
    leaves, warning set, 3 balanced journals, exact totals + 16 closing
    balances, idempotency, company isolation, draft exclusion.
  - `CoaDependentReportsTest` (4) — accounting-equation balance, GL↔TB per
    account, statement pages render (no 403/500), screen == Excel totals.
  - `StageFiveReportVisibilityTest` (4) — 6 templates seed idempotently, Stage-5
    pages render for a permissioned user, permissions registered, nav groups.
  - `MeasureResolverTest` (7) — the Phase-0 seam.
  - Plus the Stage 2–3.5 engine suite (`ReportCalculationEngineTest`,
    `ReportColumnResolverTest`, `ReportTemplateValidatorTest`, `FormulaEvaluatorTest`,
    `LedgerBalanceRepositoryTest`, `ReportWorkbookSeederTest`, `ReportPeriodTest`,
    `ReportExportTest`, `ReportEngineColumnsTest`, `ReportQueryServiceTest`,
    `FinancialReportsPageTest`, `AccountBindingServiceTest`, `CycleDetectorTest`,
    `ReportTemplateLifecycleTest`).
- **Latest run (verified this audit):** `php artisan test --testsuite=AccountingFeature`
  → **93 passed, 350 assertions**, exit 0, ~214s.
- Code style: **the new CoA + command code passes `pint --test`.** Some
  pre-existing Stage-2/3.5 files (`ReportPeriod.php`, cluster stubs,
  `AccountBindingService.php`, `Overview.php`) have style deviations Pint would
  reformat — they are **not** part of this project's changes; a repo-wide
  `pint` (not `--test`) would touch them, so run Pint path-scoped.

### Remaining tests to write
- **R1:** type-override UI — importing with overrides changes an account's
  `account_type`.
- **R2:** account→line binding populates the seeded BS/P&L templates; unmapped
  accounts surface in Mapping Review.
- **R3:** batch reversal zeroes the TB and is idempotent.
- **Stronger BS/P&L assertions:** current `CoaDependentReportsTest` proves the
  ledger *balances* (equation nets to 0 — true for any balanced set) but does
  **not** assert each account lands in the correct statement section. Add
  per-section total assertions once R2 binds accounts to lines.
- **N+1 guard** on `TrialBalanceService` (assert the 2-query count) if not
  already covered.

### Manual verification (happy path)
1. `php artisan erp:doctor` → healthy.
2. Log in at `/admin` (`admin@example.com` / `password`).
3. Reporting → **Financial Reports** shows 6 reports; Report Administration shows
   Templates / Mapping Review / External Providers.
4. Configuration → **Import Chart of Accounts** → upload the CSV, pick company +
   `Structure + migration journals`, dates `2025-07-01 / 07-30 / 07-31` →
   **Preview** (62 accounts, groups, warnings) → **Import**.
5. Reporting → **Trial Balance**, company + `2025-07-02`…`2025-07-31` →
   totals 800k/685k/20k, closing 1,170,000, difference 0 → **Export Excel/PDF**.
6. Balance Sheet / P&L / General Ledger (Reports group) read the same ledger.
7. Configuration → **Import History** shows the batch + warnings CSV.

---

## 7. Technical Debt

### Bugs / correctness watch-items
- **Opening-date foot-gun (R4).** TB must be run from the day *after* the opening
  journal date or opening balances land in the wrong bucket. Convention, not a
  crash — but easy to get wrong. Documented; fix in R4.
- **No blocking on `has_errors` in the UI beyond the service.** The service
  throws on blocking errors and rolls back (correct); the page surfaces it as a
  danger notification. Fine, but the preview's "Blocking errors: Yes/No" should
  ideally disable the Import action pre-emptively.

### Edge cases
- **Non-contiguous classification columns** (source skips "Classification 6") —
  handled by discovering `Classification*` labels rather than counting.
- **Duplicate/consecutive path segments** (`Cost > Cost`) collapsed in
  `CoaRow::groupPath()`.
- **Group `account_type`** is cosmetic (non-postable) but still NOT NULL — set by
  a coarse `groupType()`/`fallbackByNature()` heuristic.
- **XLSX vs CSV** both supported; XLSX via PhpSpreadsheet may coerce number/date
  cells — parser is lenient but verify on a real client XLSX.

### Performance
- `TrialBalanceService` is 2 aggregate queries + one account fetch — good. The
  optional **group aggregation** walks `parent_id` in PHP over all company
  accounts; fine at 62 accounts, watch at thousands.
- `CoaImportService` creates accounts one-by-one (Eloquent) inside the
  transaction; acceptable for 62 rows, consider chunked inserts for very large
  charts.

### Refactoring opportunities
- `groupType()` in `CoaImportService` duplicates the fallback logic in
  `CoaAccountTypeMapper::fallbackByNature()` — consolidate.
- `CoaImportService::import()` has a long positional signature; a small
  `CoaImportRequest` DTO would read better (the UI already passes named args).

### Future improvements
- Wire the additional `MeasureResolver` sources (imported-dataset / manual /
  external API) envisioned in `docs/data-platform-vision.md`.
- Persist the resolved column map + type overrides per batch for exact replays.

---

## 8. Exact Next Task

**Implement R1 — the interactive account-type mapping step on the import page.**
This is the smallest change that closes the biggest visible gap (the workflow
promises "map account types" but currently only auto-suggests them), and the
service already supports it, so it is low-risk.

### Open these files first
1. `plugins/webkul/accounting/src/Filament/Clusters/Configuration/Pages/ImportChartOfAccounts.php`
   — see `preview()` (already computes `type_preview`) and `import()` (already
   accepts, but does not pass, `$typeOverrides`).
2. `plugins/webkul/accounting/resources/views/filament/clusters/configuration/pages/import-chart-of-accounts.blade.php`
   — where preview output renders.
3. `plugins/webkul/accounting/src/Services/Coa/CoaAccountTypeMapper.php` and
   `.../Coa/CoaImportService.php` (`import(... $typeOverrides ...)`) — the
   already-built seam you are wiring to.
4. `plugins/webkul/accounting/tests/Feature/CoaImportAcceptanceTest.php` — copy
   its fixtures for the new test.

### Step-by-step
1. In `preview()`, keep `type_preview` (code → suggested `AccountType` value) and
   expose the leaf rows (code, title, suggested type) to the page state.
2. Add a page property `public array $typeOverrides = []` seeded from
   `type_preview` on preview.
3. Render an editable, scrollable table in the blade: per leaf, a `Select` of
   `AccountType::cases()` bound to `typeOverrides[code]`.
4. In `import()`, pass `typeOverrides: array_filter($this->typeOverrides)` into
   `CoaImportService::import()`.
5. Invalidate `typeOverrides` when a new file is uploaded (re-preview required).
6. Add `CoaImportAcceptanceTest` case: override one account's type, import, assert
   the created account's `account_type` equals the override (and TB totals are
   unchanged — types don't affect debit/credit).

### Acceptance criteria
- The import page shows every leaf with its suggested type, editable before
  import.
- Overrides are applied to created accounts; non-overridden accounts keep the
  suggestion.
- TB acceptance totals (800k/685k/20k, closing 1,170,000, diff 0) are unchanged.
- New test passes; full suite stays green; `pint --test` on the changed files
  passes. No auto-commit.

---

## 9. Handoff Prompt (paste into a fresh Claude Code session)

```
You are taking over an in-progress feature on the Aureus ERP repo at
C:\laragon\www\aureuserp-master (Laravel + Filament v5, PHP 8.3, Pest 4, MySQL).
Branch: accounting-stage2. Protected base commit: d72300d (never touch it).
Authoritative DB: aureuserp (NEVER switch to aureuserp_last).

READ FIRST (they replace prior conversation history):
- docs/PROJECT_HANDOFF.md  (full project state — read in full)
- docs/COA_TRIAL_BALANCE_IMPLEMENTATION_PLAN.md
Then skim: plugins/webkul/accounting/src/Services/Coa/*,
plugins/webkul/accounting/src/Services/TrialBalanceService.php,
plugins/webkul/accounting/src/Filament/Clusters/Configuration/Pages/ImportChartOfAccounts.php,
and plugins/webkul/accounting/tests/Feature/Coa*Test.php.

WHAT'S DONE: a full Chart-of-Accounts CSV/XLSX importer (services chain:
reader→header-detector→parser→validator→hierarchy-planner→type-mapper→
import-service), balanced Opening/Movement/Adjustment migration journals posted
to the real ledger, a ledger-backed Trial Balance page (+Excel/PDF), and
integration with General Ledger / Balance Sheet / P&L / Stage-5 Financial
Reports. Plus a generic MeasureResolver data seam and erp:doctor / erp:repair-access
console commands. 93 accounting tests pass. Nothing is committed.

HARD RULES (do not break):
- Reports read ONLY from posted journal lines; balances are never stored on
  accounts. Trial Balance formula and the split of movement-vs-adjustment by the
  `coa_migration_kind` flag (NOT by date) are defined in TrialBalanceService and
  in the handoff §5 — follow them exactly.
- Accounts↔companies is many-to-many via accounts_account_companies (no
  company_id column on accounts_accounts). Scope with whereHas('companies').
- Migration journals MUST balance or the import rolls back; MigrationJournalService
  inserts move-lines with raw DB::table on purpose (to bypass the MoveLine saving
  hook that would overwrite account_id) — keep it.
- Never run destructive commands (migrate:fresh/refresh/rollback, db:wipe,
  erp:install, git reset --hard, git clean). Forward-only, idempotent, no
  auto-commit, don't weaken tests.
- Flag suspicious data, never auto-correct; never overwrite accounts the importer
  didn't create (import_batch_id IS NULL) or published report templates/mappings.

ACCEPTANCE ANCHOR (regression guard): importing
./Chart_of_Accounts_Trial_Balance_Test.csv (company + mode "with_journals",
dates 2025-07-01/07-30/07-31) then computing the Trial Balance from 2025-07-02
to 2025-07-31 must give Opening 800,000/800,000, Movement 685,000/685,000,
Adjustment 20,000/20,000, Closing 1,170,000/1,170,000, difference 0, over 62 leaf
accounts. `php artisan test --testsuite=AccountingFeature` must stay green;
`php artisan erp:doctor` must stay healthy.

NEXT TASK: implement R1 from docs/PROJECT_HANDOFF.md §8 — add an interactive
account-type override grid to the Import Chart of Accounts page (the service's
import() already accepts $typeOverrides; the page just needs to collect and pass
them). Follow §8's step-by-step and acceptance criteria. Verify with the suite
and path-scoped `pint --test`; do not commit unless asked.
```

---

## 10. Multi-Currency, Permissions, and Bank Workflow Handoff (2026-07-28)

### Implemented architecture

- Accounting permissions are registered idempotently through
  `AccountingPermissionRegistrar` and `php artisan accounting:sync-permissions`.
  Page access and sensitive actions (import, mapping, journal generation/posting,
  rate approval, inline account creation, and FX revaluation) use the same
  permission catalog.
- `IsoCurrencySynchronizer` maintains the current ISO 4217 fiat master by code
  without replacing existing IDs or customized display fields. Run
  `php artisan accounting:sync-currencies` for a manual resynchronization.
- Company currency settings retain the base currency, enabled transaction and
  reporting currencies, FX gain/loss accounts, rate-source priority, explicit
  previous-rate fallback, and P&L/Balance Sheet translation policies.
- `ExchangeRateService` resolves approved, company-scoped, dated rates with
  decimal-safe direct/inverse conversion, source priority, optional configured
  fallback, and versioned cache invalidation. Missing rates never become 1:1
  unless the currencies are identical.
- Bank imports preserve source and company currencies/amounts plus the exact
  rate snapshot. Raw foreign statements may be imported with a missing-rate
  status, but journal posting is blocked until an approved rate exists.
- `CanonicalAccountCreationService` is the single inline creation path for Bank
  GL and Offset GL accounts. It enforces company, parent, account type,
  postability, currency, code, account-number, and IBAN constraints.
- Approved mappings create company/bank/currency-scoped reusable rules with
  normalized descriptions, confidence, explanation, priority, and review state.
  Cross-currency transfers remain blocked until an explicit conversion, charge,
  and FX workflow is supplied.
- `FxRevaluationService` creates idempotent draft period-closing revaluation
  journals and optional reversal drafts. It never rewrites posted history.
- GL, Trial Balance, Balance Sheet, P&L, and Direct Cash Flow support company,
  original-currency, and selected-reporting-currency calculations with missing
  rate warnings. Original mode never sums unrelated currencies. The configurable
  Financial Reports template page has company isolation and original-currency
  context support, but its complete three-mode UI/conversion workflow remains a
  documented blocker.

### Migration and deployment

The forward-only migration is
`plugins/webkul/accounting/database/migrations/2026_07_28_000001_implement_multi_currency_accounting.php`.
It adds ISO metadata, company currency/rate policy fields, company-currency
pivots, dated rates, account bank metadata, source/company/rate snapshots,
mapping and transfer metadata, FX revaluations, and lookup indexes. Its backfill
sets rate 1 only when source and company currencies are identical; uncertain
foreign history is flagged for review. It also performs idempotent ISO and
permission synchronization. The rollback is intentionally empty because the
migration is forward-only and preserves accounting evidence.

The migration was restored and run successfully on an isolated copy of the
primary database. It has **not** been applied to `aureuserp`. After a fresh
verified backup, apply it with:

```bat
php artisan migrate --force
php artisan erp:doctor
```

### Configuration order

1. Confirm each company's base and enabled transaction/reporting currencies.
2. Configure company FX gain and FX loss postable accounts.
3. Set rate-source priority and whether previous-valid fallback is allowed.
4. Set P&L and Balance Sheet translation policies.
5. Enter and approve dated rates before posting foreign transactions or running
   complete reporting-currency reports.
6. Create Bank GL accounts as Cash/Bank Assets in the statement currency; create
   Offset GL accounts only in the supported postable account types.

### Verification evidence

- Isolated restored migration: all three accounting migrations completed;
  174 currency rows, 155 active ISO fiat codes, zero duplicate non-null codes,
  20 company-currency pivots, and zero posted move imbalances.
- Accounting tests: `128 passed (627 assertions)` using
  `vendor\\bin\\pest --compact plugins/webkul/accounting/tests`.
- The expanded multi-currency/FX focused file subsequently passed
  `8 tests (53 assertions)`, including gain, loss, reversal balance, and
  revaluation idempotency.
- Support feature tests: `97 passed (700 assertions)`.
- Accounting route discovery: 146 routes.
- `php artisan erp:doctor`: all checks passed against the unchanged primary DB.
- Production assets built successfully; Vite still reports the pre-existing CSS
  minifier warning for an empty `:is()` selector.

### Known limitations and checkpoint rule

- The configurable Financial Reports template engine is not yet complete for
  all three currency modes.
- Cross-currency bank-transfer matching intentionally blocks instead of
  inventing FX/charge accounting.
- FX revaluation aggregate state is not automatically synchronized when a draft
  journal is posted through a generic journal screen.
- Import writes are still row-oriented; a measured 1,000-row before/after
  benchmark and equivalent page/export timings were not completed.
- The expanded accounting suite is green but is slower in this environment; do
  not claim a performance improvement without an apples-to-apples benchmark.
- Do not stage `.env.before-performance`, `hi.txt`, database dumps, logs,
  private uploads, or generated assets. Do not commit/tag/push until the above
  functional and performance blockers are accepted or resolved.
