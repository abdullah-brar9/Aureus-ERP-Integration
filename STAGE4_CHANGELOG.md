# Stage 4 Changelog — Workbook Import, Report Designer, Rendering

Stage 4 turns the Stage 3.5 engine into the working product: the
"Accounts 2025 Format" workbook lives in the database verbatim, is editable
end-to-end through Filament, and renders faithfully. **No engine file was
modified** (the constraint for this stage); one page-level addition only.

Stack: Laravel 13, Filament v5, PHP 8.3, Pest 4, MySQL.

---

## 1. Summary

| Category | Created | Modified |
|---|---|---|
| Seeder | 1 | — |
| Filament resource (+3 pages, +2 relation managers) | 6 | — |
| Filament standalone pages | 3 | — |
| Blade views | 3 | — |
| Lang files (en) | 4 | — |
| Finance deliverables (MD) | 3 | — |
| Tests | 2 files (9 tests) | — |
| **Total** | **25** | **0 engine files** |

Full suite after Stage 4: **63 tests, 202 assertions, all passing** (23
Stage 2/3 + 31 Stage 3.5 + 9 Stage 4).

## 2. Workbook import — `database/seeders/ReportWorkbookSeeder.php`

- All six worksheets become templates: `bs-group`, `cashflow-group`,
  `ridershipline-pnl`, `op-pnl`, `tin-pnl`, `notes` (180 lines, 18 columns).
- **Verbatim**: captions (including original spelling), ordering, every
  interior blank row, bold flags, section hierarchy (parent lines), check
  row, entity/period column matrix with its spacer column. Trailing blank
  rows at sheet edges are not modeled (disclosed deviation). Two header
  cells (BS "TRUCK IT IN" title, CF title row) are handled per U9 in
  STAGE4_UNRESOLVED_FORMULAS.md.
- **Never guesses**: account bindings are NOT created (workbook has none);
  entity companies are matched by exact or explicitly specific names only
  and left null when absent; every inferred subtotal formula is listed for
  sign-off.
- Idempotent (skips already-imported codes). Run with:
  `php artisan db:seed --class="Webkul\\Accounting\\Database\\Seeders\\ReportWorkbookSeeder"`

## 3. Report Designer (Filament v5, Reporting cluster)

- **ReportTemplateResource** — list/create/edit; edit page carries
  **Validate** (runs `ReportTemplateValidator`, lists every issue) and
  **Preview Report** actions.
  - **LinesRelationManager** — reorderable line table; line form covers
    line type, caption, code, parent (hierarchy), value source/basis,
    external provider key, company override, sign, indent, bold / check /
    visible; plus three repeaters: **Account Mapping** (bindings with
    signs), **Formula** (ordered operands, operator, line/constant,
    value vs consolidation purpose) and **Manual Values** (dated entries).
  - **ColumnsRelationManager** — reorderable columns: month / range /
    full-year / spacer, year offset (comparatives), entity company,
    consolidated flag.
- **FinancialReports page** — renders any template: two-row column headers
  (entity label + period), spacer columns, blank rows, bold, indentation,
  check rows (green when ~0, red otherwise), `$` formatting for USD
  templates, `-` for zero (workbook accounting style); template/year/company
  filters; accepts `?template=&year=` for deep links; shows live validation
  issues and a draft banner.
- **ReportMappingReview page** — the live unmapped-accounts / unresolved-
  formulas board across all templates.
- **ExternalProviders page** — registered provider keys + the lines using
  them.
- No PHP is needed for any layout, formula, mapping, column, manual value or
  consolidation change.

## 4. Deliverables

- `STAGE4_FINANCE_REVIEW.md` — review workflow, ERP locations, Notes owners.
- `STAGE4_UNMAPPED_ACCOUNTS.md` — all 60 unmapped ledger lines, 5 manual
  series, entity resolution status, sign-convention guidance.
- `STAGE4_UNRESOLVED_FORMULAS.md` — every inferred formula chain + open
  questions U1–U10.
- Rendered previews (HTML + PNG screenshots) in
  `storage/app/stage4-previews/` — captured through the real Filament pages
  with demo ledger data (BS entity matrix with balanced Check row; TIN PNL
  matrix showing the formula cascade GMV→GM→NR→CM1→CM2→EBITDA→EBIT→EBTDA).

## 5. Tests

- `ReportWorkbookSeederTest` (7): six templates, idempotency, TIN PNL
  caption/blank-row order byte-exact, BS column matrix incl. spacer +
  consolidated flags, check-row wiring, cashflow bases + half-year ranges,
  unmapped-and-manual guarantees, and an end-to-end computed BS (bound
  accounts → Total Assets = Total E&L → Check = 0).
- `FinancialReportsPageTest` (2): renders all six templates over HTTP as an
  authenticated, permissioned user asserting key content, and renders the
  Mapping Review / External Providers pages; saves the preview HTML.

## 6. Verification steps

```bash
php artisan migrate                     # Stage 3.5 tables (already applied)
php artisan db:seed --class="Webkul\\Accounting\\Database\\Seeders\\ReportWorkbookSeeder"
php artisan test --testsuite=AccountingFeature
```

Then in the admin panel: Accounting → Reporting → *Report Templates* (edit
"BS Group", Validate), *Financial Reports* (select each template),
*Mapping Review*. New page permissions (`page_accounting_financial_reports`,
`page_accounting_report_mapping_review`, `page_accounting_external_providers`)
follow the existing HasPageShield pattern — regenerate Shield permissions the
same way the existing reporting pages' permissions were provisioned
(e.g. `php artisan shield:generate`).

## 7. Deferred (Stage 5)

- PDF/Excel exports of rendered reports (existing exporter patterns ready to
  reuse).
- Template duplication / version-bump action for the versioning lifecycle.
- Arabic translations for the new designer strings (en shipped; ar exists
  only for pre-existing plugin strings).
- Dimension-filter application in the engine (validator still warns).
- Retiring/redirecting the legacy hardcoded Balance Sheet / P&L pages once
  Finance publishes the configured equivalents.
