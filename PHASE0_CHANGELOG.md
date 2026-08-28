# Phase 0 Changelog — Generic Data-Resolution Seam

Phase 0 of the data-platform roadmap (`docs/data-platform-vision.md`).
Introduces the `MeasureResolver` abstraction as the single interface through
which the report engine requests values, and routes the existing ledger path
through it — **with zero change to report results, public behaviour, schema, or
query count.**

Stack: Laravel 13, Filament v5, PHP 8.3, Pest 4, MySQL.

---

## 1. Summary

| Category | Created | Modified |
|---|---|---|
| Contract (`src/Contracts`) | 1 | — |
| Value objects (`src/Data`) | 4 | — |
| Services (`src/Services`, `…/Resolvers`) | 2 | — |
| Engine (`src/Services`) | — | 1 |
| Service provider | — | 1 |
| Tests (`tests/Feature`) | 1 (7 cases) | — |
| Docs | 3 | — |
| **Total** | **11** | **2** |

**No database migration.** Phase 0 is pure code routing; the schema is
untouched (see §5).

Test totals: **79 passed / 261 assertions** (72 pre-existing, unchanged + 7 new).

---

## 2. What was added

### Contract — `src/Contracts/MeasureResolver.php`
The single interface the engine uses to request values, batch-oriented:
`source(): string` and `resolve(array $references, array $periods, ResolutionContext $context): ResolvedSeries`.
Batch shape preserves the engine's one-call-per-(scope,basis)-group behaviour.

### Value objects — `src/Data/`
- `MeasureReference` — addresses one measurable quantity: `source` (selects the
  resolver) + `key` (ledger: account id) + optional `DimensionFilter`.
  `MeasureReference::ledgerAccount($id)`.
- `DimensionFilter` — immutable dimension-constraint set. Empty in every Phase 0
  call (the ledger's axes live on the context); present so the contract is
  dimension-aware from the start.
- `ResolutionContext` — reuses the existing `ReportContext` (company scope +
  posted) and adds the `ValueBasis` (movement/opening/closing). No duplication
  of `ReportContext`.
- `ResolvedSeries` — the result: `[key][period_key] => value`, the **exact
  shape `LedgerBalanceRepository::basisBalances` already returns**, so wrapping
  the repository is a zero-transform pass-through. `valueFor()`, `all()`.

### Ledger adapter — `src/Services/Resolvers/LedgerMeasureResolver.php`
Implements `MeasureResolver` for `source() === 'ledger'`. Owns **no SQL**: it
unpacks account ids from the batch and forwards them to
`LedgerBalanceRepository::basisBalances`, whose output is already the
`ResolvedSeries` shape. `LedgerBalanceRepository` remains the single source of
ledger SQL.

### Router — `src/Services/MeasureResolverRegistry.php`
Maps a source key to its resolver. `register()`, `has()`, `sources()`, and
`for($source)` which **throws a clear `RuntimeException`** naming the unknown
source and the registered ones. Future sources (imported datasets, manual,
external APIs) register here with no engine change.

## 3. What was modified

### `src/Services/ReportCalculationEngine.php`
- Added an optional 5th constructor argument `?MeasureResolverRegistry`. When
  null (e.g. direct `new` in tests) the engine builds a local registry holding
  just the `LedgerMeasureResolver`, so behaviour is identical whether or not the
  container singleton is injected. **The existing 4-arg constructor still works
  unchanged** — no existing call site or test was touched.
- The single ledger call in `ledgerBalances()` now goes through
  `registry->for('ledger')->resolve(...)` instead of `$this->ledger->basisBalances(...)`.
  `ResolvedSeries::all()` returns the identical `[account_id][period_key]` map,
  so `ledgerLineValue()` and everything downstream are unchanged.
- `$this->ledger` (the `LedgerBalanceRepository`) is still injected and still
  owns the SQL; only the *call path* moved.

### `src/AccountingServiceProvider.php`
Binds `MeasureResolverRegistry` as a singleton with the `LedgerMeasureResolver`
registered, mirroring the existing `ReportValueProviderRegistry` binding.

## 4. Compatibility

Preserved without change: account bindings, value sources (`ledger`/`formula`/
`manual`/`external`), formulas & consolidation, manual inputs, external
providers, caching, the draft/publish/version lifecycle, and all Stage 1–5
functionality. The 72 pre-existing tests pass **byte-for-byte unmodified**.

## 5. Schema

**No migration.** Explanation: Phase 0 only re-routes an in-memory service
call; it introduces no persisted data, no new columns, and no new tables. A
migration would be unjustified. (Persisted structures — datasets, staging,
facts — arrive in Phase 1, where they are genuinely required.)

## 6. Tests — `tests/Feature/MeasureResolverTest.php` (7)

- ledger measure routed through the resolver **matches the repository exactly**
  (`ResolvedSeries::all() === basisBalances()`);
- registry registers and returns the ledger resolver by source;
- container-resolved singleton has the ledger resolver bound;
- **unknown source throws clearly**;
- report results **identical** via the engine's default vs an explicitly
  supplied registry;
- **no extra ledger queries** — a controlled single-line movement report issues
  exactly one query against `accounts_account_move_lines`;
- the resolver's `source()` contract is stable.

## 7. Remaining roadmap

Phases 1–4 in `docs/data-platform-vision.md` (import framework + first dataset;
expression formula engine; dimensions & dynamic reporting; platform). Not
started; Phase 0 is the enabling seam they all build on.
