# Stage 2 Changelog — Accounting Report Engine (Models, Enums, Factories)

This package contains every file created or modified in **Stage 2** of the
configurable financial-report engine inside the `webkul/accounting` plugin.

All paths are relative to the **Aureus project root** (the directory that
contains `plugins/`, `app/`, `composer.json`). Extracting this ZIP at the
project root drops each file into its correct location without touching any
other plugin.

Stack target: Laravel 12/13, Filament v4, PHP 8.3, Aureus ERP plugin system.

---

## 1. Summary

| Category | Created | Modified |
|---|---|---|
| Migrations | 4 (created in Stage 1; **bodies revised in Stage 2**) | — |
| PHP Enums | 7 | — |
| Enum translation files (en) | 7 | — |
| Eloquent Models | 4 | — |
| Model Factories | 4 | — |
| Service Provider | — | 1 |
| **Total** | **26** | **1** |

> The four migration files were first created in Stage 1. Their **table bodies
> were revised during Stage 2** to add the approved schema deltas (versioning,
> generic dimension filter, full-expression formulas). They are therefore
> included here as the authoritative Stage 2 versions.

---

## 2. Files created

### 2.1 Migrations — `plugins/webkul/accounting/database/migrations/`

| File | Purpose |
|---|---|
| `2025_07_16_000001_create_accounting_report_templates_table.php` | Root table. One row per financial statement (e.g. "BS Group", "TIN PNL"). Holds identity (`name`, `code`), rendering mode (`layout_type`, `currency_mode`, `entity_mode`), versioning lifecycle (`status`, `version`, `parent_template_id`, soft delete), company scope (`company_id`, nullable = global), and navigation order (`sort`). |
| `2025_07_16_000002_create_accounting_report_lines_table.php` | Ordered lines within a template. One row per visible/blank line. Holds `line_type` (section_header/detail/subtotal/spacer), verbatim `caption`, `sort` (drag order), `parent_id` (self-referencing nesting), presentation flags (`is_visible`, `is_bold`, `indent_level`, `sign`), and a **generic nullable dimension filter** (`dimension_type` + `dimension_id`). |
| `2025_07_16_000003_create_accounting_report_line_accounts_table.php` | Pivot binding a `detail` line to one or many chart-of-account accounts (`accounts_accounts`). Carries a per-account `sign` so a single line can add some accounts and subtract others. |
| `2025_07_16_000004_create_accounting_report_line_formulas_table.php` | Expression components for `subtotal`/computed lines. Each row is one operand: an `operator` (`+ - * /`), an `operand_type` (`line` or `constant`), a nullable `operand_line_id` (reference to another line) or `operand_constant` (literal, e.g. 100 for %), plus `sign` and `sort`. Enables sums, differences, ratios (RPS), and percentages without hardcoding. |

### 2.2 Enums — `plugins/webkul/accounting/src/Enums/`

All are `string`-backed and implement `Filament\Support\Contracts\HasLabel`,
each exposing `getLabel()` and a static `options()` — matching the existing
`Webkul\Account\Enums\MoveState` / `AccountType` convention.

| File | Casts column | Values |
|---|---|---|
| `LayoutType.php` | `report_templates.layout_type` | `period_total`, `monthly_matrix` |
| `CurrencyMode.php` | `report_templates.currency_mode` | `ledger_only`, `usd_only`, `ledger_and_usd` |
| `EntityMode.php` | `report_templates.entity_mode` | `single_company`, `multi_company_consolidated` |
| `TemplateStatus.php` | `report_templates.status` | `draft`, `published`, `archived` |
| `LineType.php` | `report_lines.line_type` | `section_header`, `detail`, `subtotal`, `spacer` |
| `FormulaOperator.php` | `report_line_formulas.operator` | `+`, `-`, `*`, `/` |
| `FormulaOperandType.php` | `report_line_formulas.operand_type` | `line`, `constant` |

### 2.3 Enum translations — `plugins/webkul/accounting/resources/lang/en/enums/`

Flat `key => label` arrays resolved by the enum `__()` calls
(`accounting::enums/<kebab-file>.<key>`). One file per enum:
`layout-type.php`, `currency-mode.php`, `entity-mode.php`,
`template-status.php`, `line-type.php`, `formula-operator.php`,
`formula-operand-type.php`.

### 2.4 Models — `plugins/webkul/accounting/src/Models/`

| File | Purpose | Key traits / relations |
|---|---|---|
| `ReportTemplate.php` | Root aggregate for a statement. | `HasFactory`, `SoftDeletes`, `SortableTrait` (`sort`); casts the 4 template enums; `company()`, `creator()`, `parent()`/`versions()` (self, versioning), `lines()` (ordered), `rootLines()` (top-level only); `creating` boot defaults `creator_id`. |
| `ReportLine.php` | One line in a template. | `HasFactory`, `SortableTrait` (`sort`); casts `line_type`; `template()`, `parent()`/`children()`/`descendants()` + manual `getDescendantIds()` (mirrors `Account`), `accounts()` (BelongsToMany through pivot w/ `sign`), `accountBindings()` (HasMany pivot model), `formulas()` (ordered), `formulaOperands()`, `dimension()` (MorphTo generic dimension); `creating` boot defaults `creator_id`. |
| `ReportLineAccount.php` | First-class pivot for line↔account with payload. | `HasFactory`; casts `sign`; `line()`, `account()` (existing `Webkul\Account\Models\Account`). |
| `ReportLineFormula.php` | One operand of a computed line's expression. | `HasFactory`; casts `operator`, `operand_type`, `operand_constant`; `line()`, `operandLine()`. |

### 2.5 Factories — `plugins/webkul/accounting/database/factories/`

| File | Purpose | States |
|---|---|---|
| `ReportTemplateFactory.php` | Fixtures for `ReportTemplate`. | `monthlyMatrix()`, `published()`, `archived()`. |
| `ReportLineFactory.php` | Fixtures for `ReportLine`. | `sectionHeader()`, `subtotal()`, `spacer()`. |
| `ReportLineAccountFactory.php` | Fixtures for line↔account binding (uses existing `Account` factory). | `negative()`. |
| `ReportLineFormulaFactory.php` | Fixtures for formula operands. | `constant(float)`, `operator(FormulaOperator)`. |

All factories follow the repo-wide precedent
`User::query()->value('id') ?? User::factory()` for `creator_id`, matching the
existing `webkul/recruitments` factories, and are wired via each model's
`protected static function newFactory()`.

---

## 3. Files modified

### `plugins/webkul/accounting/src/AccountingServiceProvider.php`

Additive change only — no existing behavior removed:

- Added `->hasMigrations([... four Stage 2 migration filenames ...])` so the
  plugin registers the new migrations (Aureus does **not** auto-discover plugin
  migrations; they must be listed explicitly).
- Added `->runsMigrations()` on the package.
- Added `$command->runsMigrations();` inside the existing
  `hasInstallCommand(...)` closure so the plugin's **Install** action runs these
  migrations during installation.

The dependency on the `accounts` plugin and the install/uninstall command
structure are preserved exactly.

---

## 4. Dependencies (internal to this package)

Load/creation order matters because of foreign keys and enum casts:

```
Enums  ──►  Models  ──►  Factories
   │           │
   │           └── ReportLine, ReportTemplate cast to the enums
   └── FormulaOperator/OperandType, LineType, LayoutType,
       CurrencyMode, EntityMode, TemplateStatus

Enum translations are runtime-resolved by __(); no compile-time dependency.

Model FK graph:
  ReportTemplate 1─* ReportLine
  ReportLine     1─* ReportLineAccount   *─1 accounts_accounts (existing)
  ReportLine     1─* ReportLineFormula   (operand_line_id ─► ReportLine, nullable)
  ReportTemplate parent_template_id ─► ReportTemplate (self, nullable)
  ReportLine     parent_id          ─► ReportLine     (self, nullable)
```

## 5. External dependencies (already present in Aureus — reused, not created)

- `Webkul\Account\Models\Account` and its factory (chart of accounts).
- `Webkul\Support\Models\Company`.
- `Webkul\Security\Models\User` (extends `App\Models\User`, which provides `HasFactory`).
- `Spatie\EloquentSortable\{Sortable, SortableTrait}`.
- `Illuminate\Database\Eloquent\SoftDeletes`.
- `Filament\Support\Contracts\HasLabel`.

## 6. Migration order

Run in ascending timestamp order (Laravel does this automatically). FK-safe:

1. `2025_07_16_000001_create_accounting_report_templates_table`
   (depends on existing `companies`, `users`; self-FK `parent_template_id`)
2. `2025_07_16_000002_create_accounting_report_lines_table`
   (depends on `accounting_report_templates`, `users`; self-FK `parent_id`)
3. `2025_07_16_000003_create_accounting_report_line_accounts_table`
   (depends on `accounting_report_lines`, existing `accounts_accounts`)
4. `2025_07_16_000004_create_accounting_report_line_formulas_table`
   (depends on `accounting_report_lines`)

No forward references. `accounts_accounts` exists because `accounting` declares
a dependency on the `accounts` plugin.

---

## 7. Not included (later stages / parked)

- Stage 3: services (value computation, formula evaluator, monthly-matrix
  aggregation, dimension application, FX row), repositories.
- Stage 4: Filament Resource + Report Designer UI, policies, permissions,
  navigation.
- Stage 5: render pages, PDF/Excel exports, the seeder importing the 6 Excel
  layouts verbatim, tests.
- Parked: consolidation eliminations (Gap 3), operational-KPI data source
  (Gap 6), binding detail lines to the real chart-of-account codes.
