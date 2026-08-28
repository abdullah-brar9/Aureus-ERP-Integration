# Aureus Accounting — Configurable Reporting Engine

The reporting module (in `plugins/webkul/accounting`) replaces the hardcoded
financial statement pages with a fully data-driven engine: every report's
rows, columns, formulas, account mappings and consolidation behaviour live in
the database and are edited through the Report Designer — no PHP required.

- **Finance users** → *Accounting → Reporting → Reports → Financial Reports*.
- **Administrators** → *Accounting → Reporting → Report Administration*.
- The original hardcoded pages remain under *Legacy Reports*.

---

## 1. Architecture

```
Filament UI                     Services                          Data
───────────                     ────────                          ────
FinancialReports (viewer) ──►  ReportQueryService  ──► cache ──► ReportLineValue[]
  Excel / PDF exports            │  ReportColumnResolver           ReportColumnSpec[]
ReportTemplateResource           ▼
  Lines / Columns RMs          ReportCalculationEngine
MappingReview                    │  CycleDetector / FormulaEvaluator
ExternalProviders                │  AccountBindingService
                                 ▼
                               LedgerBalanceRepository ──► accounts_account_move_lines
                               ReportValueProviderRegistry (external KPIs)
                               ReportLineInput (manual values)
```

Design rules:

- **One ledger reader.** `LedgerBalanceRepository` is the only class that
  queries the ledger tables (same query shape as the legacy pages: join to
  `accounts_account_moves`, posted filter, company filter, SUM(balance)).
- **Pure evaluation.** `FormulaEvaluator` has no DB access; the engine feeds
  it per-column line values in topological order.
- **No Filament page contains business logic** — pages call
  `ReportQueryService` only.

## 2. Database schema

| Table | Purpose |
|---|---|
| `accounting_report_templates` | One row per statement version. Identity (`name`, `code`), `layout_type`, `currency_mode`, `entity_mode`, lifecycle (`status`, `version`, `published_at`, `parent_template_id`), company scope, soft-deletes. Unique `(company_id, code, version)`. |
| `accounting_report_columns` | Optional explicit columns: `column_type` (`month`/`range`/`full_year`/`spacer`), `start_month`/`end_month`, `year_offset` (comparatives), `company_id` (entity column), `is_consolidated`, `label`, `sort`. Templates without columns fall back to layout defaults (monthly matrix = Jan–Dec + Total; period total = one full-year column). |
| `accounting_report_lines` | Ordered rows: `line_type` (`section_header`/`detail`/`subtotal`/`spacer`), verbatim `caption`, `parent_id` hierarchy, presentation flags (`is_bold`, `is_check`, `is_visible`, `indent_level`, `sign`), computation config (`value_source`, `value_basis`, `external_provider`, `company_id` override). |
| `accounting_report_line_accounts` | Account mapping: line ↔ chart-of-account account with a `+/-` sign. Parent accounts include descendants. |
| `accounting_report_line_formulas` | Formula operands: `operator` (+‑*/), `operand_type` (line/constant), `purpose` (`value` / `consolidation`), `sign`, `sort`. |
| `accounting_report_line_inputs` | Manual values: dated `(line, company?, date, value)` entries; a period sums the entries inside its range. |

## 3. Value sources & bases

Each line's `value_source` (defaults from its line type):

| Source | Meaning |
|---|---|
| `ledger` | Signed sum of mapped accounts' balances (detail default) |
| `formula` | Evaluated from other lines (subtotal default) |
| `manual` | Sum of `report_line_inputs` in the period |
| `external` | Resolved through the provider registry |

`value_basis` (ledger lines; defaults from layout: period_total → closing,
monthly_matrix → movement):

| Basis | Meaning |
|---|---|
| `movement` | Sum of postings inside the period (P&L style) |
| `closing_balance` | Cumulative through the period end (balance-sheet style) |
| `opening_balance` | Cumulative before the period start (e.g. "Beginning cash") |

Ledger reads are batched by (company scope, basis): a report costs ~2 queries
per distinct scope/basis pair regardless of column or line counts
(`LedgerBalanceRepository::basisBalances`, day-level SQL aggregation +
carried-forward query).

## 4. Formula engine

- Operands fold **left-to-right, no operator precedence** — `A + B * C`
  evaluates as `(A + B) * C`. Ratios (`Revenue / Parcels`) and percentages
  (`x / y * 100`) are ordinary operand chains.
- Division by zero yields `0` (rendered `-`).
- `CycleDetector` rejects circular references before evaluation; the engine
  orders computed lines topologically, so on-sheet order never matters.

## 5. Consolidation

A column flagged `is_consolidated` computes under the full run scope — for
additive lines that *is* the simple sum across entities. If a line defines
`consolidation`-purpose operands, they replace its value formula **in
consolidated columns only** — that's where eliminations/balancing adjustments
live. Nothing else about consolidation is hardcoded. A consolidation formula
may not reference its own line (cycle detection); reference constituent lines
or a hidden elimination line instead.

Scope precedence per line/column: **line company override → column entity →
report run scope**.

## 6. Validation

`ReportTemplateValidator::validate($template)` returns every issue (errors
block publishing; warnings don't): cycles, unmapped ledger lines, empty
formulas, dangling/cross-template/non-computable operands, malformed
operands, unregistered providers, duplicate sorts, invalid column configs,
duplicate global code+version, unapplied dimension filters.
`checkViolations($results)` verifies `is_check` rows (e.g. the balance-sheet
"Check") are ≈ 0 per column. Surfaces: the **Validate** action on a template,
the **Mapping Review** page, and the issue banner on the viewer.

## 7. Lifecycle & versioning

`draft → published → archived` via `ReportTemplateVersioningService`:

- **Publish** is validator-gated and stamps `published_at`.
- **Published/archived versions are immutable** — enforced at the model layer
  (template + all structural children) and mirrored in the UI (disabled form,
  read-only relation managers, hidden delete). Manual values remain editable.
- **New Version** deep-copies any version into the next-numbered draft:
  columns, lines (hierarchy remapped), mappings, formulas (operand references
  remapped to the copied lines) and manual values.

## 8. Caching

`ReportQueryService::getReport` caches computed results for 15 minutes.
The key includes the template id/version/`updated_at`, every resolved
column's period+scope, the default basis and the posted flag. Every
structural model `$touches` up to the template, so **any designer edit or
manual-value entry invalidates automatically**. Always mutate bindings via
the `ReportLineAccount` model (never `BelongsToMany::attach`) or the touch
chain won't fire.

## 9. Manual values & external providers

- Manual: designer → line → *Manual Values* (date, value, optional company).
  Non-additive series (FX rates) shouldn't rely on the summed Total column.
- External: implement `Webkul\Accounting\Contracts\ReportValueProvider` (or a
  closure) and register during boot:

```php
app(\Webkul\Accounting\Services\ReportValueProviderRegistry::class)
    ->register('parcels', new ParcelCountProvider());
```

Set the line's source to *External Provider* with key `parcels`. Unregistered
keys fail loudly at render and are flagged by the validator. The *External
Providers* page lists registrations and usage.

## 10. Audit history

All six report models use the chatter `HasLogActivity` trait; every create /
update / delete of a template, line, column, formula, mapping or manual value
is logged (user, timestamp, old/new values) against the owning **template**'s
timeline — open it via the chatter action on the template edit page.

## 11. Permissions

Pages are gated by Shield page permissions; resource CRUD follows Shield
policies (generate with your standard `shield:generate` flow). Suggested
role mapping:

| Capability | Viewer | Accountant | Finance Manager | Admin |
|---|---|---|---|---|
| `page_accounting_financial_reports` (view/export) | ✓ | ✓ | ✓ | ✓ |
| Manual value entry (designer, inputs section) | | ✓ | ✓ | ✓ |
| Template/line/column/formula/mapping editing | | | ✓ | ✓ |
| Publish / archive / new version | | | ✓ | ✓ |
| `page_accounting_report_mapping_review` | | | ✓ | ✓ |
| `page_accounting_external_providers` | | | | ✓ |
| Legacy report pages (`page_accounting_balance_sheet`, …) | optional | optional | optional | ✓ |

## 12. Extension guide

- **New report**: create a template in the designer (or seed it like
  `ReportWorkbookSeeder`) — no code.
- **New value feed**: register a `ReportValueProvider` (§9).
- **New column semantics / bases**: extend `ColumnType` / `ValueBasis` and
  `ReportColumnResolver` / `LedgerBalanceRepository::basisBalances` — the
  engine is agnostic to how specs resolve.
- **Exports**: `ReportSpreadsheetExport` (workbook-faithful Excel) and the
  `pdfs/financial-report` blade take (template, columns, rows) — reuse them
  for scheduled emails etc.

## 13. Finance guide (day-to-day)

1. *Reports → Financial Reports*: pick report + year (+ companies), read or
   export (Excel/PDF). Red **Check** cells mean the statement doesn't balance.
2. Monthly KPI/rate entry: *Report Administration → Report Templates →
   (report) → Lines → line → Manual Values*.
3. Changing a published report: **New Version** → edit the draft → *Validate*
   → **Publish**. History of every change is on the template timeline.
4. *Mapping Review* lists anything incomplete across all reports.
