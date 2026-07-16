# Stage 3 Changelog — Business Logic (Repositories, Services, Formula Engine, Tests)

All paths are relative to the **Aureus project root**. Stage 3 adds the
calculation layer on top of the Stage 2 schema/models. No Stage 2 migration or
schema change was required.

Stack: Laravel 12/13, Filament v4, PHP 8.3, Pest 4.

---

## 1. Summary

| Category | Created | Modified |
|---|---|---|
| Value objects (`src/Data`) | 3 | — |
| Repository (`src/Repositories`) | 1 | — |
| Services (`src/Services`) | 6 | — |
| Models (`src/Models`) | — | 3 (added `$touches`) |
| Tests (`tests/Feature`) | 5 | — |
| Test config | — | 1 (`phpunit.xml`) |
| **Total** | **15** | **4** |

---

## 2. Files created

### 2.1 Value objects — `plugins/webkul/accounting/src/Data/`

| File | Purpose |
|---|---|
| `ReportPeriod.php` | Immutable named date range (the column unit of a report). Factories: `monthsOfYear()` (12 months), `fullYear()`. A period_total report uses one; a monthly_matrix uses 12 + a full-year total. |
| `ReportContext.php` | Immutable run context: company scope (one or many ids) + posted-only flag. Passed through every service so multi-company behaviour is explicit and no service reaches for the authed user's default company. |
| `ReportLineValue.php` | Structured result for one line: presentation metadata + value per period key. Decouples calculation output from the model and from the eventual UI/export. |

### 2.2 Repository — `plugins/webkul/accounting/src/Repositories/`

| File | Purpose |
|---|---|
| `LedgerBalanceRepository.php` | The single place that reads ledger balances. Reuses the exact query shape of the existing Aureus reports: joins `accounts_account_move_lines` to `accounts_account_moves`, filters on the parent move's `company_id` / `state = posted` / `date`, sums line `balance` grouped by account. Provides period, bulk-matrix, and cumulative (point-in-time) variants. |

### 2.3 Services — `plugins/webkul/accounting/src/Services/`

| File | Purpose |
|---|---|
| `AccountBindingService.php` | Resolves a detail line's account bindings into a signed set of account ids, expanding parent accounts to their descendants using the existing `Account` `parent_id` tree. |
| `Formula/CycleDetector.php` | Builds the computed-line dependency graph from `report_line_formulas` and throws on any direct/indirect cycle before evaluation. |
| `Formula/FormulaEvaluator.php` | Pure (no DB) evaluation of one computed line for one period from a map of already-computed line values. Supports `+ - * /`, line and constant operands, operand signs; divides-by-zero to 0.0. Serves subtotals, ratios (RPS) and percentages with no special cases. |
| `ReportCalculationEngine.php` | Orchestrator. Loads ordered lines with bindings/formulas, asserts acyclicity, resolves detail-line account sets once, then per period computes detail values (signed ledger sums) and computed values (evaluator, in topological order). Returns one `ReportLineValue` per line in template order. Identical code path for period_total (1 period) and monthly_matrix (12 + total). |
| `ReportCacheKey.php` | Deterministic cache key incorporating template id + version + `updated_at` + periods + company scope + posted flag, so any template edit invalidates cached results. |
| `ReportQueryService.php` | Public entry point for the UI (Stage 4 preview) and report/export pages (Stage 5). Derives periods from `layout_type`, chooses movement vs cumulative balances, runs the engine, and caches results (hydrating cached arrays back into `ReportLineValue`). |

### 2.4 Tests — `plugins/webkul/accounting/tests/Feature/`

| File | Kind | Covers |
|---|---|---|
| `FormulaEvaluatorTest.php` | Pure | add / subtract subtotals, ratio (RPS), percentage via constant, division-by-zero, operand sign, empty and missing-reference lines. |
| `ReportPeriodTest.php` | Pure | 12-month generation and order, leap-year Feb, full-year period, company-scope normalisation. |
| `CycleDetectorTest.php` | Pure | accepts nested subtotals; rejects direct and indirect cycles. |
| `AccountBindingServiceTest.php` | DB | single binding, parent→descendant expansion, negative sign. |
| `ReportCalculationEngineTest.php` | DB | detail + subtotal from posted ledger; draft/date-window exclusion; company scoping; per-period values for a monthly matrix. |

---

## 3. Files modified

### `src/Models/ReportLine.php`, `ReportLineFormula.php`, `ReportLineAccount.php`
Added `protected $touches` so that editing a line, formula, or account binding
bumps the owning template's `updated_at`. This is what makes the
`ReportCacheKey` invalidation correct: any designer edit changes the cache key
automatically. No other behaviour changed.

### `phpunit.xml` (project root)
Registered a new `AccountingFeature` testsuite pointing at
`plugins/webkul/accounting/tests/Feature`, matching the pattern used by the
other plugins.

---

## 4. Design guarantees for Stage 4 (Excel import)

- **Nothing is hardcoded.** Layout, captions, ordering, subtotals, formulas and
  account bindings are all read from the Stage 2 tables. Importing the Excel in
  Stage 4 is purely inserting `ReportTemplate` / `ReportLine` /
  `ReportLineFormula` / `ReportLineAccount` rows — no service changes needed.
- **Formula generality already proven.** The evaluator handles the exact shapes
  the workbook needs (contribution-margin subtotals, RPS ratios, USD/percentage
  multipliers), verified by passing tests.
- **Layout-agnostic engine.** period_total and monthly_matrix share one code
  path, so both the balance-sheet/cashflow sheets and the monthly P&L sheets are
  served by the same engine.
- **Multi-company ready.** `ReportContext` already accepts multiple company ids
  and the engine sums across them, so the consolidated column (parked Gap 3) has
  its execution path in place; only the elimination policy remains open.

## 5. External components reused (not duplicated)

- Ledger read pattern from the existing reporting pages (`Move` + `MoveLine`
  join, posted filter) — centralised, not re-invented.
- `Webkul\Account\Models\Account` tree (`parent_id` / `children`).
- `MoveState::POSTED` enum.
- Laravel `Cache` facade for caching.
- Pest 4 harness (`Tests\TestCase` + `DatabaseTransactions`) and the existing
  factories from Stage 2 and the `accounts` plugin.

## 6. Not included (later stages / parked)

- Stage 4: Filament Resource + Report Designer UI (drag-drop, insert, duplicate,
  bind accounts, preview, draft/publish), policies, permissions, navigation, and
  the Excel importer/seeder that loads the 6 workbook layouts verbatim.
- Stage 5: render pages, PDF/Excel exports, additional feature tests.
- Parked: consolidation eliminations (Gap 3), operational-KPI data source
  (Gap 6), FX/USD-rate row wiring (Gap 5 — the `currency_mode` column and
  period model already accommodate it; the rate lookup lands with the UI/report
  pages).
