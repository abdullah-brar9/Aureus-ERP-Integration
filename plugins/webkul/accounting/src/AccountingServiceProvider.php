<?php

namespace Webkul\Accounting;

use Filament\Panel;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Livewire\Livewire;
use Webkul\Accounting\Database\Seeders\ReportWorkbookSeeder;
use Webkul\Accounting\Filament\Widgets\JournalChartWidget;
use Webkul\Accounting\Livewire\InvoiceSummary;
use Webkul\Accounting\Repositories\LedgerBalanceRepository;
use Webkul\Accounting\Services\Bank\BankStatementParserRegistry;
use Webkul\Accounting\Services\Bank\HblBankStatementParser;
use Webkul\Accounting\Services\Bank\MeezanBankStatementParser;
use Webkul\Accounting\Services\MeasureResolverRegistry;
use Webkul\Accounting\Services\ReportValueProviderRegistry;
use Webkul\Accounting\Services\Resolvers\LedgerMeasureResolver;
use Webkul\PluginManager\Console\Commands\InstallCommand;
use Webkul\PluginManager\Console\Commands\UninstallCommand;
use Webkul\PluginManager\Package;
use Webkul\PluginManager\PackageServiceProvider;

class AccountingServiceProvider extends PackageServiceProvider
{
    public static string $name = 'accounting';

    public static string $viewNamespace = 'accounting';

    public function configureCustomPackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasViews()
            ->hasTranslations()
            ->hasDependencies([
                'accounts',
            ])
            ->hasMigrations([
                '2025_07_16_000001_create_accounting_report_templates_table',
                '2025_07_16_000002_create_accounting_report_lines_table',
                '2025_07_16_000003_create_accounting_report_line_accounts_table',
                '2025_07_16_000004_create_accounting_report_line_formulas_table',
                '2026_07_16_000001_create_accounting_report_columns_table',
                '2026_07_16_000002_create_accounting_report_line_inputs_table',
                '2026_07_16_000003_add_engine_columns_to_accounting_report_lines_table',
                '2026_07_16_000004_add_purpose_to_accounting_report_line_formulas_table',
                '2026_07_17_000001_add_published_at_to_accounting_report_templates_table',
                '2026_07_20_000001_add_coa_import_fields_to_accounts_accounts_table',
                '2026_07_20_000002_create_accounting_coa_import_batches_table',
                '2026_07_20_000003_add_coa_migration_fields_to_accounts_account_moves_table',
                '2026_07_27_074828_implement_bank_statement_workflow',
                '2026_07_27_082400_allow_multiple_bank_sheets_per_workbook',
            ])
            ->runsMigrations()
            ->hasSeeder(ReportWorkbookSeeder::class)
            ->icon('accounting')
            ->hasInstallCommand(function (InstallCommand $command) {
                $command->installDependencies();
                $command->runsMigrations();
                // Idempotently seed the six workbook report templates (the
                // seeder skips codes that already exist), so a fresh install
                // always has the Stage 5 reports available.
                $command->runsSeeders();
            })
            ->hasUninstallCommand(function (UninstallCommand $command) {});
    }

    public function packageBooted(): void
    {
        $this->registerCustomCss();

        $this->registerLivewireComponents();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(ReportValueProviderRegistry::class);

        $this->app->singleton(BankStatementParserRegistry::class, function (): BankStatementParserRegistry {
            $registry = new BankStatementParserRegistry;
            $registry->register(new HblBankStatementParser);
            $registry->register(new MeezanBankStatementParser);

            return $registry;
        });

        // The generic data-resolution seam (Phase 0). Only the ledger resolver
        // is registered today; imported-dataset, manual and external-API
        // resolvers register here in later phases without engine changes.
        $this->app->singleton(MeasureResolverRegistry::class, function ($app) {
            $registry = new MeasureResolverRegistry;

            $registry->register(new LedgerMeasureResolver($app->make(LedgerBalanceRepository::class)));

            return $registry;
        });

        Panel::configureUsing(function (Panel $panel): void {
            $panel->plugin(AccountingPlugin::make());
        });
    }

    public function registerLivewireComponents()
    {
        Livewire::component('accounting-journal-chart', JournalChartWidget::class);

        Livewire::component('accounting-invoice-summary', InvoiceSummary::class);
    }

    public function registerCustomCss()
    {
        FilamentAsset::register([
            Css::make('accounting', __DIR__.'/../resources/dist/accounting.css'),
        ], 'accounting');
    }
}
