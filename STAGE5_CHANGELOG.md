# Stage 5 Changelog — Production Readiness, UI Polish & Finalization

Stage 5 turns the feature-complete reporting module into a production-shaped
one: clean navigation, a real template lifecycle with immutable published
versions, workbook-faithful Excel/PDF exports, audit history, and full
documentation. **The calculation engine was not modified.**

Final state: **72 tests, 243 assertions, all passing** (23 Stage 2/3 +
31 Stage 3.5 + 10 Stage 4 + 8 Stage 5), Pint clean.

---

## 1. Navigation (nothing feels like a developer tool)

Accounting → Reporting is now three groups, in order:

- **Reports** — *Financial Reports* (the primary entry point: pick a report,
  it renders immediately; deep-linkable; Excel/PDF export buttons).
- **Report Administration** — *Report Templates*, *Mapping Review*,
  *External Providers* (admin-only via their Shield page permissions).
- **Legacy Reports** — the original hardcoded pages (Balance Sheet, P&L,
  General Ledger, Trial Balance, Partner Ledger, Aged Receivable/Payable),
  regrouped and pushed to the bottom, hideable per role by revoking their
  existing page permissions. Nothing was deleted.

## 2. Lifecycle & versioning (`ReportTemplateVersioningService`)

- `draft → published → archived`; **Publish** is blocked by validator errors
  and stamps the new `published_at` column (migration
  `2026_07_17_000001_...`).
- **Published/archived versions are immutable**, enforced in three layers:
  model guard on the template, a shared `InteractsWithReportTemplate` guard
  on every structural child (lines, columns, formulas, mappings), and a
  read-only designer UI (disabled form, read-only relation managers, hidden
  delete). Deleting non-drafts is refused. **Manual values stay editable** —
  they are operational data, not structure.
- **New Version** deep-copies any version into the next-numbered draft with
  hierarchy and formula operand references remapped (tested).
- Template table now shows status badge, version, company, creator,
  published date, line counts; filters for status/layout/company; lifecycle
  actions on both the table and the edit page.

## 3. Audit history

All six reporting models compose the chatter `HasLogActivity` trait through
`InteractsWithReportTemplate`: every authenticated create/update/delete of a
template, line, column, formula, mapping or manual value is recorded (user,
timestamp, old/new values) on the owning template's timeline, opened via the
chatter action on the template page. System writes (seeder/CLI) are skipped
by design — the chatter schema requires a causer.

Two defects found and fixed while wiring this: `getOriginal('status')`
returns the cast enum in Laravel 13 (guard now uses `getRawOriginal`), and
causer-less logging attempts filled the log with swallowed exceptions (now
gated).

## 4. Exports

- **Excel** (`ReportSpreadsheetExport`): entity/period header rows, spacer
  columns (width 1.5) and blank rows, bold subtotal rows, red check rows,
  accounting number formats (`$#,##0;($#,##0);"-"` for USD sheets), caption
  column width 32, frozen panes. Values are numbers — no manual cleanup.
- **PDF** (`pdfs/financial-report` blade + dompdf): repeating fixed header
  (company scope, report title, period, generated timestamp, draft stamp),
  page-number footer, automatic landscape for wide (entity-matrix/monthly)
  reports, professional typography.

## 5. Permissions

Page-level Shield permissions cover the three new pages (provisioned in this
environment; regenerate via your standard `shield:generate` flow on deploy).
The role matrix (Viewer / Accountant / Finance Manager / Administrator) is
documented in `docs/reporting-engine.md` §11 — roles themselves are
deployment configuration.

## 6. Documentation

`docs/reporting-engine.md` — architecture, schema, value sources/bases,
formula engine, consolidation, validation, lifecycle, caching, manual
values, external providers, audit history, permission matrix, extension
guide, and a finance day-to-day guide.

## 7. Performance & security review (no changes required)

- Queries: batched by (scope, basis) — monthly matrix ≈ 1 query; indexes on
  all FK/sort paths; eager loads in the engine and relation managers.
- Caching: 15-min result cache, invalidation via the touch chain (verified
  by test), now includes `published_at`-bearing template stamps.
- Security: no raw SQL from user input (query builder bindings throughout),
  `$fillable` allow-lists, Shield-gated pages, model-layer immutability
  (defense in depth below the UI), exports rendered server-side.

## 8. Known remaining items (documented, not blocking the module)

1. **Finance sign-off** — mappings/formulas (STAGE4_* reports) before
   publishing the six seeded templates; reports render `-` until then.
2. Resource-level Shield policies + role split are deploy-time configuration
   (`shield:generate`, assign per docs §11); publish/mapping actions
   currently follow resource access.
3. Dimension filters remain unapplied (validator warns).
4. Arabic translations pending for the new designer strings.
5. Cashflow H1/H2 column semantics (U1) and other U-items need the Finance
   decisions listed in STAGE4_UNRESOLVED_FORMULAS.md.

## 9. Verification

```bash
php artisan migrate                          # adds published_at
php artisan test --testsuite=AccountingFeature   # 72 passed
vendor/bin/pint --dirty                      # clean
```

Manual: Reporting → Financial Reports (render + both exports) → Report
Templates (Validate, Publish, New Version, chatter history) → Mapping
Review. Screenshots: `storage/app/stage4-previews/stage5-*.png`.
