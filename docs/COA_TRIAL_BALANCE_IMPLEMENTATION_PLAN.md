# Chart of Accounts Import + Ledger-Backed Trial Balance — Implementation Plan

Working dir `C:\laragon\www\aureuserp-master`, branch `accounting-stage2`,
authoritative DB `aureuserp`, protected commit `d72300d`, test file
`./Chart_of_Accounts_Trial_Balance_Test.csv`. Forward-only migrations,
idempotent logic, no destructive commands, no auto-commit.

## Current system state (verified, not assumed)

- **ERP restored & healthy** (CoA Phase 0 done): `erp:doctor` passes; company
  `DummyCorp LLC` (#1); admin `admin@example.com` (#2) active/verified, Admin
  role with all 574 permissions, default+allowed company; plugins
  products/accounts/accounting installed; `/admin/login` → 200.
- **Ledger tables present, empty**: `accounts_accounts` (51 seeded defaults),
  `accounts_account_moves`/`_move_lines` (0), `accounts_journals` (6).
- **Report tables present, EMPTY**: `accounting_report_templates`/`_lines`/
  `_columns` = **0 rows**. All 9 accounting-report migrations applied.
- **Stage 5 page permissions exist**: `page_accounting_financial_reports`,
  `_report_mapping_review`, `_external_providers` (+ legacy
  `_trial_balance`/`_balance_sheet`/`_profit_loss`/`_general_ledger`).
- Accounts have `parent_id` (migration `2026_04_17_000001`), `code`, `name`,
  `account_type` (enum `Webkul\Account\Enums\AccountType`), `deprecated`,
  company pivot `accounts_account_companies`.

## Why the Stage 5 reports are "missing"

**Root cause: `ReportWorkbookSeeder` was never executed on the rebuilt
`aureuserp`.** Pages/resources are registered, migrations ran, permissions
exist — but with 0 templates the Financial Reports picker is empty and the
per-template sidebar items (added in Stage 5) don't render. Fix = idempotent
seed, no code recreation.

## Architecture & data flow (target)

```
CoA CSV/XLSX ─► CoaSheetParser ─► CoaHeaderDetector ─► CoaColumnMapper
   ─► CoaRowValidator (+ warnings/flags) ─► CoaHierarchyPlanner (groups+leaves)
   ─► [preview / dry-run]  ─► CoaImportService (transactional, idempotent)
        ├─► accounts_accounts (group=non-postable, leaf=postable, parent_id, batch ref)
        └─► [optional] MigrationJournalService ─► 3 balanced journals (Move+MoveLine, posted)
                                                     via existing Account\Models\Move/MoveLine
   ─► posted ledger lines
        ├─► TrialBalanceService (SQL aggregation) ─► TrialBalance page + Excel/PDF
        ├─► existing GeneralLedger / BalanceSheet / ProfitLoss pages
        └─► Stage 5 MeasureResolver/engine (unchanged) ─► Financial Reports
```

Balances are **never** stored on account records — only as posted journal
lines. The upload preview may show a *provisional* TB from the sheet, but the
official TB is always ledger-derived.

## Phases, files, migrations, tests

### Phase 0 — Verify & stabilize (no rebuild)
Verify erp:doctor passes, login 200, authenticated /admin loads. Tests:
`ErpAccessTest` (doctor healthy, repair idempotent, login route 200).

### Phase 1 — Restore Stage 5 reports
- Run/upsert `ReportWorkbookSeeder` idempotently (already idempotent: skips
  existing codes). Register it to the accounting install so future installs
  seed it. Expected **6 templates**.
- Navigation: surface **Trial Balance / General Ledger / Balance Sheet /
  Profit & Loss** under the **Reports** group beside Financial Reports; keep
  **Report Templates / Mapping Review / External Providers** under **Report
  Administration**. (Move legacy statement pages' group from "Legacy Reports"
  to "Reports"; the new ledger TB from Phase 4 becomes THE Trial Balance.)
- Files: `AccountingServiceProvider` (register seeder on install),
  legacy page nav-group lang files, new TrialBalance page (Phase 4).
- Tests: `StageFiveReportVisibilityTest` (6 templates seeded; pages return 200
  for permissioned user; nav groups correct).

### Phase 2 — CoA domain + importer
- **Migration** (forward-only) `..._add_import_columns_to_accounts_accounts`:
  `source_classification_path` (text, nullable), `import_batch_id` (fk
  nullable). Reuse existing `parent_id`, `code`, `name`, `account_type`,
  `deprecated`. Group vs postable derived from a new nullable boolean
  `is_group` (non-postable classification nodes) — reuse if an equivalent
  exists; otherwise add. Journal lines already select accounts via
  `AccountResource`; enforce `is_group=false` for postable.
- **New table** `accounting_coa_import_batches` (company_id, user_id, filename,
  mode, status, counts, options json, timestamps).
- **Services** (`plugins/webkul/accounting/src/Services/Coa/`):
  `CoaSheetReader` (CSV+XLSX via maatwebsite/excel + league/csv),
  `CoaHeaderDetector`, `CoaColumnMap`, `CoaRowValidator` (+ `CoaWarning`),
  `CoaHierarchyPlanner`, `CoaAccountTypeMapper` (editable suggested mappings),
  `CoaImportService` (transactional, idempotent upsert by company+code).
- **Filament** (Configuration cluster): `ChartOfAccountsResource` (list/tree),
  `ImportChartOfAccounts` page (wizard: upload → company/currency → detect →
  map → validate → preview → confirm → import → report), `ImportHistory`
  (batches list + downloadable error report).
- Tests: parser, header detection, column map, validator+flags, hierarchy
  (no dup nodes), company-scoped uniqueness, idempotent re-import, group
  non-postable, 62-leaf detection.

### Phase 3 — Migration journals
- `MigrationJournalService`: builds 3 balanced journals (Opening/Movement/
  Adjustment) from sheet balances through `Account\Models\Move`+`MoveLine`,
  posted, tagged with `import_batch_id` + a `MoveKind`/reference marking
  adjustment vs movement. No artificial balancing account; reject unbalanced.
  Idempotent (skip if batch already has journals). Dates configurable.
- Adjustment separation: mark adjustment journal so the TB can split movement
  vs adjustment (via journal reference/name or a dedicated flag column on the
  move — reuse `ref`/`name`; add nullable `migration_kind` on moves if needed).
- Tests: three balanced journals; totals 800k/685k/20k; idempotency; rollback
  on unbalanced; batch reference set; draft exclusion.

### Phase 4 — Ledger-backed Trial Balance
- `TrialBalanceService`: pure SQL aggregation over `accounts_account_move_lines`
  joined to `_moves`, posted-only default, per-account opening (before from),
  movement (non-adjustment in-range), adjustment (in-range), closing =
  derived; company isolation; no N+1 (single grouped query + one before-date
  query). Decimal precision preserved.
- `TrialBalance` Filament page (Reports group) with all filters + columns;
  group rows aggregate descendants without double-counting grand totals.
- `TrialBalanceExport` (Excel) + PDF blade; totals match screen.
- Tests: exact formulas, date filters, adjustment split, draft exclusion,
  zero-balance toggle, company isolation, group aggregation, export parity,
  N+1 guard (query count).

### Phase 5 — Dependent report integration
- Verify imported accounts + posted migration journals flow through existing
  GeneralLedger/BalanceSheet/ProfitLoss pages and the Stage 5 engine.
- Auto-map unambiguous accounts to the seeded BS/P&L templates via
  `ReportLineAccount`; ambiguous → left unmapped (surface in Mapping Review).
  Never overwrite existing mappings. Version a template if structure must be
  generated from the imported hierarchy.
- Tests: BS Assets = Liab+Equity from posted journals; P&L from same lines;
  GL reconciles to TB; Mapping Review lists unmapped.

### Phase 6 — Exact-file acceptance
`CoaAcceptanceTest` driving the real CSV through the full flow, asserting the
16 acceptance criteria incl. the exact closing balances and totals, plus the 6
Stage 5 templates remaining visible.

### Phase 7 — Execute & verify
`composer dump-autoload; php artisan optimize:clear; php artisan migrate;
php artisan erp:doctor; php artisan test; npm run build`; inspect logs; hit
real routes. No auto-commit.

## Permissions & navigation
New Shield page/resource permissions for ChartOfAccounts, Import, ImportHistory,
TrialBalance — regenerate via the install's shield:generate; grant to Admin via
`erp:repair-access` (idempotent). Nav groups: **Reports** and **Report
Administration** and **Configuration** (Chart of Accounts, Import, History).

## Database safety strategy
Forward-only additive migrations; idempotent seeders/imports (upsert by
company+code, skip existing batches); all imports and journal creation wrapped
in DB transactions with full rollback; never drop/alter existing data;
`aureuserp` only; protected commit untouched; no auto-commit.

## Rollback / recovery
- Import: single transaction — any validation/row failure rolls the whole
  batch back (no partial accounts/journals). `erp:doctor` for health;
  `erp:repair-access` for access. Batch history allows identifying/reversing a
  specific import (reverse journals via standard move reversal; accounts from a
  batch identifiable by `import_batch_id`).

## Phase dependencies
0 → 1 (reports visible) → 2 (accounts) → 3 (journals need accounts) →
4 (TB needs posted journals) → 5 (dependent reports need TB/journals) →
6 (acceptance needs all) → 7 (execute).

## Acceptance criteria
The 16 Phase-6 checks (exact totals 800k/685k/20k, closing 1,170,000/0 diff,
the 16 non-zero closing balances, company isolation, idempotency, rollback,
group non-postable, export parity) + 6 Stage 5 templates visible.
