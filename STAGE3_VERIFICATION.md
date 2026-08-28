# Stage 3 Verification

Commands to integrate and verify the Stage 3 business layer. Run from the
**Aureus project root**. Stage 3 adds no migrations, so there is no database
schema step — only autoload, cache clear, and tests.

Environment: Windows 11 + Laragon (PHP 8.3 CLI on PATH), or any OS. Run in the
Laragon terminal so the correct PHP is used.

---

## 0. Extract

Extract this ZIP at the project root. It adds Stage 3 source under
`plugins/webkul/accounting/src/{Data,Repositories,Services}`, tests under
`plugins/webkul/accounting/tests/Feature`, updates three model files
(`src/Models/ReportLine.php`, `ReportLineFormula.php`, `ReportLineAccount.php`)
with a `$touches` property, and updates the project `phpunit.xml` to register
the `AccountingFeature` testsuite.

> The three model files and `phpunit.xml` are **overwrites** of files that
> already exist. If you have local changes to them, diff before replacing. The
> only model change is the added `protected $touches` array.

Confirm files landed:

```bash
ls plugins/webkul/accounting/src/Services/            # 5 files + Formula/
ls plugins/webkul/accounting/src/Services/Formula/    # CycleDetector, FormulaEvaluator
ls plugins/webkul/accounting/src/Repositories/        # LedgerBalanceRepository
ls plugins/webkul/accounting/src/Data/                # ReportPeriod, ReportContext, ReportLineValue
ls plugins/webkul/accounting/tests/Feature/           # 5 test files
```

---

## 1. Regenerate the autoloader

New classes under the existing `Webkul\Accounting\...` namespace:

```bash
composer dump-autoload
```

Expected: ends with `Generated optimized autoload files ...`, no errors.

---

## 2. Clear cached state

```bash
php artisan optimize:clear
```

Expected: each cache line reports `DONE`.

---

## 3. Run the Stage 3 test suite

Run only the accounting suite:

```bash
php artisan test --testsuite=AccountingFeature
```

or with Pest directly:

```bash
./vendor/bin/pest --testsuite=AccountingFeature
```

or the whole project test run:

```bash
php artisan test
```

### Expected result

All Stage 3 tests pass. You should see the pure-logic tests
(`FormulaEvaluatorTest`, `ReportPeriodTest`, `CycleDetectorTest`) and the
DB-backed tests (`AccountBindingServiceTest`, `ReportCalculationEngineTest`)
reported green, e.g.:

```
   PASS  Tests\Feature\FormulaEvaluatorTest
  ✓ adds line operands (e.g. a simple subtotal)
  ✓ subtracts line operands (e.g. GM minus subsidies)
  ✓ divides to produce a ratio (e.g. RPS = revenue / parcels)
  ✓ computes a percentage using a constant operand ...
  ✓ returns 0.0 on division by zero rather than erroring
  ✓ honours an operand sign flag
  ✓ treats a line with no formulas as zero
  ✓ resolves a missing referenced line as zero

   PASS  Tests\Feature\ReportPeriodTest
  ✓ builds twelve months for a year in order
  ✓ handles february in a leap year
  ✓ builds a full-year period
  ✓ normalises company scope in the context
  ✓ supports an empty company scope

   PASS  Tests\Feature\CycleDetectorTest
  ✓ accepts an acyclic chain of nested subtotals
  ✓ detects a direct self-reference
  ✓ detects an indirect cycle

   PASS  Tests\Feature\AccountBindingServiceTest
  ✓ resolves a single bound account
  ✓ expands a parent account to include its descendants
  ✓ carries a negative binding sign

   PASS  Tests\Feature\ReportCalculationEngineTest
  ✓ computes detail lines and a subtotal from posted ledger data
  ✓ excludes draft moves and respects the date window
  ✓ scopes by company
  ✓ produces a value per period for a monthly matrix
```

> Note: the DB-backed tests use `DatabaseTransactions` and the existing
> factories. They require the test database to be migrated (the normal Aureus
> test bootstrap handles this). If your test DB is not yet set up, run the
> project's standard test setup first (the same one used to run the other
> plugins' `*Feature` suites).

---

## 4. Manual smoke check (optional, via tinker)

Build a tiny report in tinker and compute it end to end:

```bash
php artisan tinker
```

```php
use Webkul\Support\Models\Company;
use Webkul\Account\Models\{Account, Move, MoveLine};
use Webkul\Account\Enums\MoveState;
use Webkul\Accounting\Models\{ReportTemplate, ReportLine, ReportLineAccount, ReportLineFormula};
use Webkul\Accounting\Data\ReportContext;
use Webkul\Accounting\Services\ReportQueryService;

$company = Company::first() ?? Company::factory()->create();
$rev = Account::factory()->create();

$move = Move::factory()->create(['company_id' => $company->id, 'state' => MoveState::POSTED, 'date' => '2025-01-15']);
MoveLine::factory()->create(['move_id' => $move->id, 'company_id' => $company->id, 'account_id' => $rev->id, 'balance' => 1000, 'parent_state' => MoveState::POSTED, 'date' => '2025-01-15']);

$tpl = ReportTemplate::factory()->monthlyMatrix()->create();
$line = ReportLine::factory()->create(['report_template_id' => $tpl->id, 'line_type' => 'detail', 'caption' => 'Revenue', 'sort' => 1]);
ReportLineAccount::factory()->create(['report_line_id' => $line->id, 'account_id' => $rev->id, 'sign' => 1]);

$svc = app(ReportQueryService::class);
$rows = $svc->getReport($tpl->fresh(), 2025, ReportContext::forCompany($company->id), useCache: false);
$rows->firstWhere('lineId', $line->id)->valueFor('2025-01');   // 1000.0
```

Expected: `1000.0` in the January column.

---

## Troubleshooting

- **`Class ... not found`** → `composer dump-autoload && php artisan optimize:clear`.
- **DB tests error on missing tables** → ensure the accounting migrations
  (Stage 1/2) ran on the **test** database; run the project's standard test
  bootstrap that the other plugin suites rely on.
- **Windows/Laragon** → run in the Laragon terminal; `php -v` should be 8.3.x.

---

## Rollback

Stage 3 adds no schema, so rollback is purely file-level:

1. Delete the created files:
   - `plugins/webkul/accounting/src/Data/{ReportPeriod,ReportContext,ReportLineValue}.php`
   - `plugins/webkul/accounting/src/Repositories/LedgerBalanceRepository.php`
   - `plugins/webkul/accounting/src/Services/AccountBindingService.php`
   - `plugins/webkul/accounting/src/Services/ReportCalculationEngine.php`
   - `plugins/webkul/accounting/src/Services/ReportCacheKey.php`
   - `plugins/webkul/accounting/src/Services/ReportQueryService.php`
   - `plugins/webkul/accounting/src/Services/Formula/{CycleDetector,FormulaEvaluator}.php`
   - `plugins/webkul/accounting/tests/Feature/{FormulaEvaluatorTest,ReportPeriodTest,CycleDetectorTest,AccountBindingServiceTest,ReportCalculationEngineTest}.php`
2. Revert the `$touches` additions in `src/Models/ReportLine.php`,
   `ReportLineFormula.php`, `ReportLineAccount.php`.
3. Remove the `AccountingFeature` `<testsuite>` block from `phpunit.xml`.
4. `composer dump-autoload && php artisan optimize:clear`.
