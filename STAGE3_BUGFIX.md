# Stage 3 Bugfix — ReportCalculationEngine integration tests

## Symptom

19 of 23 Stage 3 tests passed. The four `ReportCalculationEngineTest` cases
failed, each asserting a real value but receiving `0.0`:

```
Failed asserting that 0.0 is identical to 1000.0.
Failed asserting that 0.0 is identical to 500.0.
Failed asserting that 0.0 is identical to 300.0.
Failed asserting that 0.0 is identical to 100.0.
```

The pure-logic suites (`FormulaEvaluator`, `CycleDetector`, `ReportPeriod`) and
`AccountBindingService` all passed. So the calculation math and the account
resolution were correct; only reads that go through the ledger returned zero.

## Trace performed

`ReportCalculationEngine` → `AccountBindingService` → `LedgerBalanceRepository`
→ `Move` / `MoveLine`.

1. **Repository SQL — verified correct.** The exact query the repository builds
   (join `accounts_account_move_lines` → `accounts_account_moves`, filter
   `company_id` / `state = 'posted'` / `date BETWEEN`, `SUM(balance)` grouped by
   `account_id`) was replicated against SQLite with the same seed data and
   returned `1000` as expected. The query shape matches the existing Profit &
   Loss page exactly (`plugins/webkul/accounting/src/Filament/Clusters/Reporting/Pages/ProfitLoss.php`).
   Joins, `account_id`, company filter, posted filter, `balance` column, date
   filtering and grouping are all correct.

2. **Account binding — verified correct.** `AccountBindingService` resolves the
   report line to the intended `account_id` (its own tests pass). Those tests
   create only `ReportLineAccount` pivot rows, which have no model hooks.

3. **Fixture persistence — root cause found here.** The failing tests build
   ledger rows with `MoveLine::factory()`. The `MoveLine` model has a `saving`
   hook (`plugins/webkul/accounts/src/Models/MoveLine.php`) that recomputes
   several columns from the parent move, including:

   ```php
   $moveLine->computeAccountId();
   ```

   For a plain journal entry the factory default is `display_type = null`, so
   `computeAccountId()` falls into its `default:` branch and sets:

   ```php
   $this->account_id = $this->move->journal?->default_account_id ?? $this->account_id;
   ```

   The `Journal` factory sets `default_account_id => Account::factory()` — a
   **different** account. So on save, each move line's `account_id` was
   overwritten with the journal's default account, discarding the explicit
   `account_id` the test set. The report line was bound to the original account,
   which then had no matching move lines, so every ledger read summed to `0.0`.

## Root cause

Not a Stage 3 defect. `LedgerBalanceRepository` and the rest of the business
layer are correct. The four integration tests seeded ledger data in a way that
fought the `MoveLine` domain model: the model derives a line's account from its
journal's `default_account_id` (via the `saving` hook), so setting `account_id`
directly on the factory is silently overridden.

## Fix

**Test fixtures only.** No source/architecture change. The repository, engine,
binding service, data objects, caching and models are byte-for-byte unchanged.

`plugins/webkul/accounting/tests/Feature/ReportCalculationEngineTest.php` now
creates, for each posted line, a `Journal` whose `default_account_id` is the
account under test, and points the move/line at that journal:

```php
$journal = Journal::factory()->create([
    'company_id'         => $company->id,
    'default_account_id' => $account->id,
]);

$move = Move::factory()->create([
    'company_id' => $company->id,
    'journal_id' => $journal->id,
    'state'      => $state,
    'date'       => $date,
]);

MoveLine::factory()->create([
    'move_id'      => $move->id,
    'journal_id'   => $journal->id,
    'company_id'   => $company->id,
    'account_id'   => $account->id,   // now preserved: it equals the journal default
    'balance'      => $balance,
    'debit'        => $balance >= 0 ? $balance : 0,
    'credit'       => $balance < 0 ? -$balance : 0,
    'parent_state' => $state,
    'date'         => $date,
]);
```

This is faithful to the domain: real ledger lines take their account from the
journal, so the persisted line genuinely belongs to `$account`. The helper also
gained a `$state` parameter so the draft-exclusion case creates its excluded
line through the same path (proving exclusion is by state, not by an accidental
account mismatch).

`computeBalance()` keeps the value because these are `ENTRY` (non-invoice)
moves, where `balance = debit - credit`, and the fixture sets `debit`/`credit`
to match the intended balance.

## Files changed

- `plugins/webkul/accounting/tests/Feature/ReportCalculationEngineTest.php`
  (updated — fixtures only)

No other files changed.

## Verification

```bash
composer dump-autoload
php artisan optimize:clear
php artisan test --testsuite=AccountingFeature
```

Expected: all `ReportCalculationEngineTest` cases pass alongside the other 19,
for 23 green tests total.
