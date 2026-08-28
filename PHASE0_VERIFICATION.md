# Phase 0 Verification

Evidence that the generic data-resolution seam is correct and non-breaking.

## Commands

```bash
php artisan test --testsuite=AccountingFeature   # reporting engine + seam
php artisan test --testsuite=AccountFeature      # base accounts plugin (cross-plugin check)
vendor/bin/pint --dirty                          # style
```

## Results

| Check | Result |
|---|---|
| AccountingFeature suite | **79 passed / 261 assertions** (72 pre-existing unchanged + 7 new) |
| New `MeasureResolverTest` | **7 passed / 18 assertions** |
| Pint (changed files) | clean (only import-ordering auto-fixes on new files) |
| Database migrations | none added — schema unchanged |

## Requirement-by-requirement evidence

| Requirement | How it is proven |
|---|---|
| 1. `MeasureResolver` contract is the single value interface | Engine's only ledger call now goes through `MeasureResolverRegistry::for('ledger')->resolve()`. Grep confirms `ReportCalculationEngine` no longer calls `$this->ledger->basisBalances()` directly. |
| 2. Minimal value objects | `MeasureReference`, `DimensionFilter`, `ResolutionContext`, `ResolvedSeries` — 4 small immutable classes in `src/Data`, reusing `ReportContext`. |
| 3. Ledger path adapted, no SQL duplicated | `LedgerMeasureResolver` forwards to `LedgerBalanceRepository::basisBalances`; the repository is still the sole owner of ledger SQL. Test: *"routes a ledger measure … matches the repository exactly"* asserts `ResolvedSeries::all() === basisBalances()`. |
| 4. Registry/router for future sources | `MeasureResolverRegistry` keyed by source; ledger registered today. Test: *"registers the ledger resolver and returns it by source"* + container-singleton test. |
| 5. Engine routed through resolver, no behaviour change | Test: *"produces identical report results whether routed through a custom or default registry"* + the entire 72-test suite passing unchanged. |
| 6. Compatibility with bindings/sources/formulas/caching/consolidation/manual/external/Stage 1–5 | 72 pre-existing tests pass byte-for-byte unmodified. |
| 7. No schema change unless necessary | No migration added; justification recorded in `PHASE0_CHANGELOG.md` §5. |
| 8a. Existing ledger reports identical | Full suite green + the custom-vs-default-registry equivalence test. |
| 8b. All previous tests unchanged & pass | 72/72, no test file edited except the *new* `MeasureResolverTest`. |
| 8c. Router resolves ledger correctly | *"routes a ledger measure through the resolver and matches the repository exactly"*. |
| 8d. Unknown sources fail clearly | *"fails clearly for an unknown measure source"* asserts the `RuntimeException` message. |
| 8e. No extra ledger queries | *"introduces no extra ledger queries through the seam"* — `DB::listen` counts exactly **1** query against `accounts_account_move_lines` for a single-line movement report. |

## Compatibility impact

None observable. Public behaviour, report values, query count, schema and all
Stage 1–5 features are unchanged. The only surface addition is an **optional**
5th engine constructor argument and a new container singleton — both additive.

## Manual sanity (optional)

In the ERP: Accounting → Reporting → Financial Reports → any report renders and
exports exactly as before (ledger reads now flow through the resolver seam).
