# Data Platform Vision & Roadmap

The approved long-term direction for the accounting reporting engine: evolve it
from a ledger + configurable-formula reporting engine into a **fully
configurable financial data platform**, where arbitrary raw datasets
(Excel/CSV, APIs, manual inputs) are ingested, normalized and turned into
reports entirely through configuration — no PHP per workbook format.

This is the roadmap. **Phase 0 is implemented** (see `PHASE0_CHANGELOG.md`);
Phases 1–4 are design-only until scheduled.

---

## North star architecture

Three cleanly separated layers replace today's partly-fused engine:

```
INGESTION            →   SEMANTIC (data layer)      →   CALCULATION + PRESENTATION
import framework          Measure Store +                expression formula engine
+ source adapters         Dataset/Dimension/Measure      + report templates
(GL, Excel, manual,       registry — the ONE thing       (today's engine, generalized)
 API)                     reports read from
```

Guiding principle: **decouple where data comes from, what it means, and how it
is calculated and presented.** Reports stop reading "the ledger" or "a provider"
and instead read **measures addressed by dimensions**. Every source becomes an
adapter behind one resolver interface.

## Why this is evolutionary, not a rewrite

The current design already contains the seams:

- `ValueSource` (ledger / formula / manual / external) + `ReportValueProviderRegistry`
  is the embryo of the generic data layer — a routing abstraction over sources.
- The dormant `dimension_type` / `dimension_id` columns on report lines were
  forward-looking hooks for the semantic layer.
- Configurable columns, formulas, consolidation, and the draft/publish/version
  lifecycle mean **presentation is already fully data-driven**.

Phase 0 formalizes the first seam (`MeasureResolver`); later phases extend it.

---

## Phase 0 — The generic data-resolution seam ✅ IMPLEMENTED

Introduce `MeasureResolver` as the single interface through which the engine
requests values, with a registry/router keyed by source. `LedgerBalanceRepository`
is wrapped by a `LedgerMeasureResolver` (no duplicated SQL); the engine's one
ledger call routes through the registry. Pure refactor, zero behavior change,
query count unchanged. Enables every later phase without touching the engine
again. See `PHASE0_CHANGELOG.md` / `PHASE0_VERIFICATION.md`.

## Phase 1 — Ingestion MVP + first dataset

- **Import framework**: `ImportTemplate` (source sheets, header rows, column
  mappings, data types, transform pipeline, validation rules, dedup key, update
  strategy) → staging (raw rows stored verbatim) → validate → materialize facts.
- **`DatasetAdapter`** implementing `MeasureResolver`, registered on the
  registry alongside the ledger resolver.
- First real dataset: the TIN operational workbook (Costing / PaidTopay /
  S.tax / Dispatch). Report lines can bind to dataset-measures, generalizing
  today's `manual`/`external` sources.
- New tables: `data_import_templates`, `data_import_sheet_mappings`,
  `data_import_column_mappings`, `data_import_transforms`,
  `data_import_validations`, `data_import_runs`, `data_staging_rows`, plus a
  first-cut fact store. Filament resources for each, Shield-permissioned.
- Delivers the "upload the sheets → generate the report" workflow.

## Phase 2 — Expression formula engine

- Replace the operand-list `FormulaEvaluator` with a lexer → precedence-climbing
  parser → sandboxed evaluator + pluggable `FunctionRegistry`.
- Semantic references (not A1 cells): `[Revenue]`, `[GP]/[Revenue]`,
  `SUM([dataset].[measure] WHERE [dim]=…)`, `PRIOR_YEAR([EBITDA])`,
  cross-report `[Balance Sheet].[Total Assets]`.
- Function categories added incrementally: math → logical → text → date →
  aggregation → lookup → financial.
- Named formulas + user-defined calculated fields.
- A converter migrates existing operand formulas; both evaluators run during
  transition. **Precedence changes formula meaning** — migrate, don't
  reinterpret. Sandbox is mandatory (no PHP eval; recursion/fan-out/time limits).

## Phase 3 — Dimensions & dynamic reporting

- Activate the dormant dimension columns; dimension-filtered measure binding
  (the semantic form of `SUMIF`).
- Cross-report references, comparison/variance columns, ratios, KPIs, derived
  metrics as first-class configurable constructs.

## Phase 4 — Platform

- Scheduled/automated imports; lineage & reconciliation dashboards; external
  API adapters; self-serve import UI polish.

---

## Cross-cutting concerns

**Data model.** `data_datasets`, `data_dimensions`, `data_measures`,
`data_facts` (grain = one fact per source row, tagged with dimensions;
aggregation happens at query time, not import time), plus the import/staging
tables and a **lineage link** on every fact back to its import run.

**Performance.** Federate the GL (query-through, never copied); materialize
imports. Query pushdown is non-negotiable — aggregation becomes SQL `GROUP BY`,
never PHP loops. Three cache tiers: raw facts → resolved-measure cache →
report-result cache. Freeze resolved measures on template publish.

**Security.** Formula sandbox; file-ingestion limits + CSV/formula-injection
neutralization + streaming for large workbooks; **PII handling** (the raw
operational sheets carry driver CNIC and phone numbers — field classification,
masking, retention); dataset/measure/import-template as Shield-permissioned
resources; lineage as audit.

**Migration.** Strangler-fig / expand-and-contract. Each phase ships behind the
stable current engine; nothing is removed until its replacement is contracted
and green. The draft/publish immutability protects live reports throughout.

## Lead-engineer caveats (recorded with the approval)

1. This is a BI-platform + ETL + formula-engine build (6–12 months + ongoing
   maintenance). If the near-term need is only "get the TIN workbook in,"
   Phase 1 alone delivers most of the value.
2. The formula engine is the highest-risk piece — scope a **curated** function
   set that covers the actual workbooks, not "all of Excel."
3. The real project is the **authority shift** (Excel → ERP as source of
   truth). It only earns trust through faithful reproduction + reconciliation +
   lineage, which is why lineage is first-class in the data model.
