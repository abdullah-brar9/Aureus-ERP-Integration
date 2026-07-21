# Stage 3.5 Changelog — Engine Completion (Columns, Value Sources, Consolidation, Validation)

All paths are relative to the **Aureus project root**. Stage 3.5 makes the
reporting engine structurally capable of every layout in the Accounts 2025
workbook — entity-matrix balance sheet / cashflow columns, per-line value
bases, manual and external series, consolidation overrides — **without any
business assumptions**: no formulas, mappings, eliminations or KPI semantics
are baked in. No UI, no Excel import, no Filament resources.

Stack: Laravel 13, Filament v5, PHP 8.3, Pest 4, MySQL.

---

## 1. Summary

| Category | Created | Modified |
|---|---|---|
| Migrations | 4 | — |
| Enums (+ en lang files) | 4 (+4) | — |
| Models | 2 | 3 |
| Factories | 2 | 2 |
| Value objects (`src/Data`) | 2 | 1 |
| Contracts | 1 | — |
| Services | 3 | 4 |
| Repository | — | 1 |
| Service provider | — | 1 |
| Lang (validation) | 1 | — |
| Tests | 4 | — |
| **Total** | **28** | **12** |

Every pre-existing public API keeps its signature and behaviour, except
`ReportQueryService::forget()` (zero callers; see §8).

---

## 2. Why each change was necessary

The workbook audit (see conversation record) established four structural
requirements the Stage 1–3 design could not express, plus defects found during
the independent code review. Each Stage 3.5 change maps to one of them:

| Requirement / defect | Change |
|---|---|
| BS Group / Cashflow Group render an **entity × period column matrix** (TIN, Rider, OP, Consolidated × Jun'25, Dec'25 + spacer columns); comparative/prior-year columns must be configurable | `accounting_report_columns` table + `ReportColumn` model + `ReportColumnResolver` + `ReportColumnSpec`; engine computes per resolved column |
| Cashflow mixes **opening balance, movement and closing balance rows** in one statement; the old "period_total ⇒ cumulative" heuristic mis-modelled it | `value_basis` per line (`movement` / `opening_balance` / `closing_balance`), template default still derived from layout so existing templates behave identically |
| TIN PNL rows ("OpenPort NI", "Rider NI") read **another entity's ledger**; KPI rows ("No. of Parcels", "Volume", "USD Rate") have **no ledger source** | per-line `company_id` override; `value_source` per line (`ledger` / `formula` / `manual` / `external`); `accounting_report_line_inputs` for manual series; `ReportValueProvider` contract + registry for future KPI feeds |
| **Consolidated column** must default to a simple sum across entities but allow per-line elimination overrides, with no hardcoded consolidation logic | `is_consolidated` column flag; `purpose` on formulas (`value` / `consolidation`); engine evaluates consolidation-purpose formulas only in consolidated columns |
| Designer errors were silently evaluated as zero | `ReportTemplateValidator` (structural) + `checkViolations()` (control totals) |
| BS "Check" row must provably equal zero | `is_check` flag on lines + runtime check validation |
| `balancesMatrixForAccounts` was dead code with a first-match bucketing bug (full-year total column would read 0) | rewritten on top of the new `basisBalances()`; a day now counts toward **every** period containing it |
| Monthly matrix cost 13 queries | engine batches ledger reads by (scope, basis): a monthly matrix is now 1 query, an entity-matrix BS ~2 per entity |
| Cache invalidation had gaps for new inputs | `$touches` chains on `ReportColumn` and `ReportLineInput`; column-aware `ReportCacheKey::forColumns()` |

---

## 3. Migrations — `plugins/webkul/accounting/database/migrations/`

| File | Purpose |
|---|---|
| `2026_07_16_000001_create_accounting_report_columns_table.php` | Column definitions per template: `column_type` (`month` / `range` / `full_year` / `spacer`), `start_month`/`end_month`, `year_offset` (comparatives), `company_id` (entity column), `is_consolidated`, `label`, `sort`. Relative specs — resolved against the run year, so "Jun / Dec" columns roll forward each year without editing. |
| `2026_07_16_000002_create_accounting_report_line_inputs_table.php` | Manual values: `(report_line_id, company_id NULL, date, value)`, unique per line+company+date (named index — the auto-generated name exceeded MySQL's 64-char limit). A period's value is the **sum of entries inside its range**; non-additive series (rates) should use a provider or a total-column formula. |
| `2026_07_16_000003_add_engine_columns_to_accounting_report_lines_table.php` | Adds `value_source`, `value_basis`, `external_provider`, `is_check`, `company_id` to lines — all nullable/defaulted so every existing row keeps its exact previous semantics. |
| `2026_07_16_000004_add_purpose_to_accounting_report_line_formulas_table.php` | Adds `purpose` (`value` default / `consolidation`) to formula operands. |

All four are **additive** — the original Stage 2 migrations are untouched, so
databases that already ran them upgrade with a plain `php artisan migrate`
(verified against the dev database).

## 4. Enums — `plugins/webkul/accounting/src/Enums/` (+ `resources/lang/en/enums/`)

`ColumnType`, `ValueSource`, `ValueBasis`, `FormulaPurpose` — string-backed,
`HasLabel`, static `options()`, one en lang file each; identical pattern to the
Stage 2 enums.

Mapping to the requirement list: "Ledger balance" is `ValueSource::LEDGER`;
which of opening / movement / closing the ledger read uses is `ValueBasis`
(closing ≡ cumulative ledger balance). "Formula result / Manual value /
External provider" are the other three `ValueSource` cases.

## 5. Models & factories

- **`ReportColumn`** (new) — sortable per template (`buildSortQuery`), touches
  the template. Factory with `fullYear()` / `range()` / `spacer()` /
  `consolidated()` states.
- **`ReportLineInput`** (new) — touches its line (→ template) so manual edits
  rotate the cache key. Factory included.
- **`ReportLine`** — new fillables/casts; `company()` and `inputs()` relations;
  `effectiveValueSource()` (null column derives from line_type:
  detail→ledger, subtotal→formula, header/spacer→none — the exact Stage 3
  behaviour) and `effectiveValueBasis($default)`; per-template
  `buildSortQuery()` so sort sequences no longer share one global counter.
- **`ReportTemplate`** — `columns()` relation.
- **`ReportLineFormula`** — `purpose` fillable/cast.

## 6. Engine layer

- **`ReportColumnSpec`** (`src/Data`) — a resolved column: key, label, period,
  optional entity scope, consolidation flag. Spacer columns carry no period.
- **`ReportColumnResolver`** (new service) — resolves definitions against the
  run year; templates **without** column rows get the Stage 3 defaults
  (12 months + total, or one full-year column) with the same period keys, so
  existing callers see identical value maps.
- **`ReportCalculationEngine`** — now computes per column:
  - lines are classified by effective value source; ledger reads are batched
    by (company scope, basis) across all columns via the new repository method;
  - scope precedence: line `company_id` override → column entity scope → run
    context; a consolidated column uses the full run scope, which for additive
    lines **is** the simple sum across entities;
  - in a consolidated column, a line's `consolidation`-purpose formulas (when
    defined) replace its `value` formulas — this is the only consolidation
    behaviour, nothing is hardcoded;
  - manual lines sum their input entries per period (entity-scoped; a
    company-less entry applies to every scope);
  - external lines resolve through the provider registry and **fail loudly**
    if the provider key is unregistered;
  - the old `calculate($template, $periods, $context, $cumulative)` API is
    kept and delegates (cumulative → closing basis), so all Stage 3 tests pass
    unchanged.
- **`FormulaEvaluator`** — optional `FormulaPurpose` argument; operands without
  a purpose count as `value`, preserving the pure in-memory tests.
- **`LedgerBalanceRepository`** — new `basisBalances()` reads any number of
  periods under any basis in ≤ 2 queries (day-level SQL aggregation + carried-
  forward query for balance bases); `balancesMatrixForAccounts()` now delegates
  to it (bug fixed, signature unchanged); `balancesForAccounts()` /
  `cumulativeBalancesForAccounts()` untouched.
- **`ReportValueProvider`** contract + **`ReportValueProviderRegistry`**
  (singleton, bound in the service provider) — the future-KPI extension point.

## 7. Validation — `ReportTemplateValidator` (+ `resources/lang/en/validation.php`)

`validate(ReportTemplate)` returns every issue (never throws): formula cycles;
ledger lines without bindings; formula lines without operands; operands that
are dangling, cross-template, or point at non-value-carrying lines; malformed
operands; missing/unregistered external providers; duplicate line/column sort
positions; invalid column month configs; duplicate global `code`+`version`
(MySQL's unique index can't enforce it for NULL company_id); and a warning that
dimension filters are defined but not applied by the engine yet.

`checkViolations($results, $tolerance = 0.01)` validates `is_check` rows
(e.g. the BS "Check" row) against computed results — every column must be ~0.

## 8. Caching

- `ReportCacheKey::forColumns()` — covers each resolved column's period, scope
  and consolidation flag plus the default basis; the old `for()` remains.
- Invalidation is still updated_at-driven and now complete: lines, formulas,
  account bindings, **columns** and **manual inputs** all touch the template.
- `ReportQueryService::forget()` signature changed from
  `(template, periods, context, cumulative)` to `(template, year, context)` to
  match the column world — it had zero callers. `getReport` / `previewReport` /
  `periodsFor` are unchanged; `columnsFor()` and `defaultBasisFor()` are new.

## 9. Designer guidelines encoded by this stage (for Stage 4 UI)

- Formulas evaluate strictly left-to-right (no operator precedence) — unchanged
  from Stage 3; surface this in the formula builder.
- A consolidation formula may not reference its own line (cycle detection
  rejects it); model eliminations by referencing the constituent lines or a
  dedicated (hidden) elimination line.
- Account bindings must be edited through `ReportLineAccount` models (not
  `BelongsToMany::attach()`) so cache invalidation fires.
- Ratio lines in consolidated columns evaluate their formula over consolidated
  inputs (a consolidated ratio), not the sum of per-entity ratios; define a
  consolidation formula if different behaviour is required.

## 10. Tests — `plugins/webkul/accounting/tests/Feature/`

| File | Covers |
|---|---|
| `LedgerBalanceRepositoryTest.php` | movement/opening/closing bases incl. carried-forward; overlapping-period matrix regression (the old `break` bug); company scoping. |
| `ReportColumnResolverTest.php` | layout-default fallbacks; month/range/full-year/spacer resolution; year offsets; entity + consolidated flags; invalid month rejection. |
| `ReportEngineColumnsTest.php` | entity-matrix with consolidated column (default sum); consolidation-formula override (elimination); per-line bases in one report; manual inputs (period bucketing + company scoping); external providers (registered + unregistered); line-level company override; legacy `calculate()` compatibility. |
| `ReportTemplateValidatorTest.php` | every validator rule plus check-row violations. |
| `ReportQueryServiceTest.php` | cache serve + invalidation via the manual-input touch chain; `columnsFor` incl. spacer columns. |

The 23 pre-existing Stage 2/3 tests run **unmodified**.

## 11. Compatibility statement

- Existing templates (no column rows, null `value_source`/`value_basis`,
  `purpose = 'value'`) resolve to byte-identical behaviour: same periods, same
  period keys, same bases, same formula sets.
- `ReportCalculationEngine::calculate()`, `LedgerBalanceRepository`'s three
  public methods, `ReportQueryService::getReport/previewReport/periodsFor`,
  `FormulaEvaluator::evaluate()` (new optional arg), `CycleDetector`,
  `AccountBindingService`, all Data objects (additive field on
  `ReportLineValue`) — signature-compatible.
- Migrations are purely additive; `php artisan migrate` upgrades in place.

## 12. Not included (Stage 4+ / parked, unchanged)

- Filament Resource, Report Designer UI, policies, permissions, navigation.
- Excel layout importer/seeder and the formula/mapping sign-off sheet.
- Render pages and PDF/Excel exports (Stage 5).
- Dimension filter application (validator warns), FX auto-conversion via
  `CurrencyRate` (manual USD-rate rows work today), elimination policy content
  (the mechanism exists; the business numbers are Stage 4 data).
