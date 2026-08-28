# Stage 2 Verification

Exact commands to install and verify the Stage 2 accounting report-engine
schema and models. Run from the **Aureus project root** (the folder containing
`plugins/`, `app/`, `artisan`, `composer.json`).

Environment assumed: Windows 11 + Laragon (Apache, PHP 8.3, MySQL), but the
commands are identical on any OS. Where Windows differs, it is noted.

---

## 0. Extract the package

Extract this ZIP **at the project root** so the `plugins/webkul/accounting/...`
paths merge into the existing plugin. Nothing outside `plugins/webkul/accounting`
is touched.

After extraction, confirm the files landed correctly:

```bash
# Should print the four migration filenames
ls plugins/webkul/accounting/database/migrations/2025_07_16_*.php

# Should print 7 enum classes
ls plugins/webkul/accounting/src/Enums/

# Should print the 4 models
ls plugins/webkul/accounting/src/Models/Report*.php
```

Windows PowerShell equivalents:

```powershell
Get-ChildItem plugins\webkul\accounting\database\migrations\2025_07_16_*.php
Get-ChildItem plugins\webkul\accounting\src\Enums\
Get-ChildItem plugins\webkul\accounting\src\Models\Report*.php
```

---

## 1. Regenerate the autoloader

New classes were added under already-registered PSR-4 namespaces
(`Webkul\Accounting\...`), so a dump is enough — no `composer.json` change.

```bash
composer dump-autoload
```

Expected output (version numbers may differ):

```
Generating optimized autoload files
> ... (package discovery lines) ...
Generated optimized autoload files containing NNNN classes
```

Success = ends with **"Generated optimized autoload files"** and no errors.

---

## 2. Clear cached framework state

```bash
php artisan optimize:clear
```

Expected output (each line shows `DONE`):

```
   INFO  Clearing cached bootstrap files.

  config ............................. DONE
  cache .............................. DONE
  compiled ........................... DONE
  events ............................. DONE
  routes ............................. DONE
  views .............................. DONE
```

---

## 3. Confirm the new migrations are visible

```bash
php artisan migrate:status
```

Expected: the four migrations appear, marked **Pending** (not yet run):

```
  Migration name .............................................. Batch / Status
  ...
  2025_07_16_000001_create_accounting_report_templates_table ......... Pending
  2025_07_16_000002_create_accounting_report_lines_table ............. Pending
  2025_07_16_000003_create_accounting_report_line_accounts_table ..... Pending
  2025_07_16_000004_create_accounting_report_line_formulas_table ..... Pending
```

If they do **not** appear, the service provider did not register them — see
Troubleshooting (A).

---

## 4. Run the migrations

Either run migrations directly:

```bash
php artisan migrate
```

Expected output:

```
   INFO  Running migrations.

  2025_07_16_000001_create_accounting_report_templates_table ..... DONE
  2025_07_16_000002_create_accounting_report_lines_table ......... DONE
  2025_07_16_000003_create_accounting_report_line_accounts_table . DONE
  2025_07_16_000004_create_accounting_report_line_formulas_table . DONE
```

**OR** reinstall the plugin from the admin **Plugins** page (the Install action
now runs these migrations). Both paths create the same four tables.

---

## 5. Verify the tables and columns exist

```bash
php artisan tinker
```

Then, inside tinker:

```php
use Illuminate\Support\Facades\Schema;

Schema::hasTable('accounting_report_templates');       // true
Schema::hasTable('accounting_report_lines');           // true
Schema::hasTable('accounting_report_line_accounts');   // true
Schema::hasTable('accounting_report_line_formulas');   // true

// Versioning + soft delete columns on templates:
Schema::getColumnListing('accounting_report_templates');
// expect: id, company_id, creator_id, parent_template_id, sort, name, code,
//         layout_type, currency_mode, entity_mode, status, version,
//         description, created_at, updated_at, deleted_at

// Dimension filter columns on lines:
Schema::getColumnListing('accounting_report_lines');
// expect to include: dimension_type, dimension_id, line_type, caption, sort,
//         parent_id, sign, is_visible, is_bold, indent_level

// Full-expression columns on formulas:
Schema::getColumnListing('accounting_report_line_formulas');
// expect to include: operator, operand_type, operand_line_id,
//         operand_constant, sign, sort
```

---

## 6. Verify models, enum casts, tree, and factories

Still in tinker:

```php
use Webkul\Accounting\Models\ReportTemplate;
use Webkul\Accounting\Models\ReportLine;
use Webkul\Accounting\Enums\LayoutType;
use Webkul\Accounting\Enums\TemplateStatus;
use Webkul\Accounting\Enums\LineType;

// Build a template with states
$t = ReportTemplate::factory()->monthlyMatrix()->published()->create();
$t->layout_type === LayoutType::MONTHLY_MATRIX;   // true  (enum cast works)
$t->status === TemplateStatus::PUBLISHED;          // true

// Add ordered lines
$header = ReportLine::factory()->sectionHeader()->create([
    'report_template_id' => $t->id, 'caption' => 'ASSETS', 'sort' => 1,
]);
$detail = ReportLine::factory()->create([
    'report_template_id' => $t->id, 'caption' => 'Trade Debts',
    'parent_id' => $header->id, 'sort' => 2,
]);
$sub = ReportLine::factory()->subtotal()->create([
    'report_template_id' => $t->id, 'caption' => 'Total Assets', 'sort' => 3,
]);

$t->lines()->count();          // 3
$sub->line_type === LineType::SUBTOTAL;   // true

// Tree helpers (mirrors Account)
$header->getDescendantIds();   // [ $detail->id ]

// Soft delete / archive
$t->delete();
ReportTemplate::withTrashed()->find($t->id) !== null;   // true (recoverable)

exit
```

All assertions above should evaluate to `true` (or the stated value). Any
`Class not found` means the autoloader was not dumped — re-run step 1.

---

## 7. One-shot verification (optional, no tinker)

```bash
php artisan tinker --execute="
use Webkul\Accounting\Models\ReportTemplate;
use Webkul\Accounting\Enums\LayoutType;
\$t = ReportTemplate::factory()->monthlyMatrix()->create();
echo \$t->layout_type->value === 'monthly_matrix' ? 'OK: model+enum+factory work' : 'FAIL';
echo PHP_EOL;
"
```

Expected: `OK: model+enum+factory work`

---

## Troubleshooting

### (A) Migrations do not appear in `migrate:status`
- Confirm the edit to `plugins/webkul/accounting/src/AccountingServiceProvider.php`
  is present: it must contain `->hasMigrations([...])` listing the four
  `2025_07_16_000001..000004` filenames and `->runsMigrations()`.
- Then:
  ```bash
  php artisan optimize:clear
  composer dump-autoload
  php artisan migrate:status
  ```

### (B) `Class "Webkul\Accounting\Models\ReportTemplate" not found`
```bash
composer dump-autoload
php artisan optimize:clear
```

### (C) Foreign-key error during migrate
- Ensure the `accounts` plugin is installed first (it owns `accounts_accounts`)
  and that `companies` / `users` exist (core). The `accounting` plugin depends
  on `accounts`, so installing/enabling `accounts` before `accounting` resolves
  this.

### (D) Windows / Laragon note
- Run these in the Laragon terminal (Menu → Terminal) so the correct PHP 8.3
  CLI is on PATH. Do not run them through Apache. `php -v` should report 8.3.x.

---

## Rollback

Roll back **only** the four Stage 2 migrations (most recent batch), in reverse
order automatically:

```bash
php artisan migrate:rollback --step=4
```

Expected output:

```
  2025_07_16_000004_create_accounting_report_line_formulas_table . DONE
  2025_07_16_000003_create_accounting_report_line_accounts_table . DONE
  2025_07_16_000002_create_accounting_report_lines_table ......... DONE
  2025_07_16_000001_create_accounting_report_templates_table ..... DONE
```

Verify tables are gone:

```bash
php artisan tinker --execute="echo \Illuminate\Support\Facades\Schema::hasTable('accounting_report_templates') ? 'STILL EXISTS' : 'DROPPED';"
# expect: DROPPED
```

### Full file rollback (remove Stage 2 entirely)
1. `php artisan migrate:rollback --step=4`
2. Delete the added files:
   - `plugins/webkul/accounting/database/migrations/2025_07_16_0000{01,02,03,04}_*.php`
   - `plugins/webkul/accounting/src/Enums/{LayoutType,CurrencyMode,EntityMode,TemplateStatus,LineType,FormulaOperator,FormulaOperandType}.php`
   - `plugins/webkul/accounting/resources/lang/en/enums/{layout-type,currency-mode,entity-mode,template-status,line-type,formula-operator,formula-operand-type}.php`
   - `plugins/webkul/accounting/src/Models/{ReportTemplate,ReportLine,ReportLineAccount,ReportLineFormula}.php`
   - `plugins/webkul/accounting/database/factories/{ReportTemplate,ReportLine,ReportLineAccount,ReportLineFormula}Factory.php`
3. Revert the `AccountingServiceProvider.php` change (remove the `hasMigrations`
   block, the `->runsMigrations()` call, and the `$command->runsMigrations();`
   line).
4. `composer dump-autoload && php artisan optimize:clear`
