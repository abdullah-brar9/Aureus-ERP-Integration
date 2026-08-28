<?php

namespace Webkul\Accounting\Filament\Clusters\Reporting\Pages;

use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\BankStatementResource;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\BankTransactionMappingResource;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\JournalEntryResource;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\ManualAdjustmentResource;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\AccountResource;
use Webkul\Accounting\Filament\Clusters\Reporting;
use Webkul\Accounting\Services\AccountingReconciliationService;
use Webkul\Accounting\Services\ReportCompletenessService;

class AccountingChecks extends Page implements HasForms
{
    use HasPageShield, InteractsWithForms;

    protected string $view = 'accounting::filament.clusters.reporting.pages.accounting-checks';

    protected static ?string $cluster = Reporting::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-check-badge';

    protected static ?int $navigationSort = 97;

    public ?array $data = [];

    protected static function getPagePermission(): ?string
    {
        return 'page_accounting_accounting_checks';
    }

    public static function getNavigationLabel(): string
    {
        return 'Accounting Checks';
    }

    public function mount(): void
    {
        $this->form->fill(['date_from' => now()->startOfMonth()->toDateString(), 'date_to' => now()->endOfMonth()->toDateString()]);
    }

    protected function getFormSchema(): array
    {
        return [Section::make('Period')->columns(2)->schema([
            DatePicker::make('date_from')->label('From')->required()->live(),
            DatePicker::make('date_to')->label('To')->required()->live(),
        ])];
    }

    protected function getFormStatePath(): string
    {
        return 'data';
    }

    #[Computed]
    public function checkRows(): array
    {
        $state = $this->form->getState();
        $checks = app(AccountingReconciliationService::class)->checks(Auth::user()->default_company_id, $state['date_from'], $state['date_to']);

        return array_map(function (array $check): array {
            $check['fix_url'] = match (true) {
                str_contains($check['where_to_fix'], 'Transaction Mapping'), str_contains($check['where_to_fix'], 'Transfer') => BankTransactionMappingResource::getUrl('index'),
                str_contains($check['where_to_fix'], 'Bank Statement')                                                        => BankStatementResource::getUrl('index'),
                str_contains($check['where_to_fix'], 'Chart of Accounts')                                                     => AccountResource::getUrl('index'),
                str_contains($check['where_to_fix'], 'Manual') || str_contains($check['where_to_fix'], 'Opening')             => ManualAdjustmentResource::getUrl('index'),
                default                                                                                                       => JournalEntryResource::getUrl('index'),
            };

            return $check;
        }, $checks);
    }

    #[Computed]
    public function completeness(): array
    {
        $state = $this->form->getState();

        return app(ReportCompletenessService::class)->assess(Auth::user()->default_company_id, $state['date_from'], $state['date_to']);
    }
}
