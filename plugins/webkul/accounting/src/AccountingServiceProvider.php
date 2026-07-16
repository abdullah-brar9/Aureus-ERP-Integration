<?php

namespace Webkul\Accounting;

use Filament\Panel;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Livewire\Livewire;
use Webkul\Accounting\Filament\Widgets\JournalChartWidget;
use Webkul\Accounting\Livewire\InvoiceSummary;
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
            ])
            ->runsMigrations()
            ->icon('accounting')
            ->hasInstallCommand(function (InstallCommand $command) {
                $command->installDependencies();
                $command->runsMigrations();
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
