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
use Webkul\Accounting\Filament\Clusters\Reporting;
use Webkul\Accounting\Services\DirectCashFlowService;
use Webkul\Accounting\Services\ReportCompletenessService;

class DirectCashFlow extends Page implements HasForms
{
    use HasPageShield, InteractsWithForms;

    protected string $view = 'accounting::filament.clusters.reporting.pages.direct-cash-flow';

    protected static ?string $cluster = Reporting::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 96;

    public ?array $data = [];

    protected static function getPagePermission(): ?string
    {
        return 'page_accounting_direct_cash_flow';
    }

    public static function getNavigationLabel(): string
    {
        return 'Cash Flow Statement';
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
    public function cashFlowData(): array
    {
        $state = $this->form->getState();

        return app(DirectCashFlowService::class)->calculate(Auth::user()->default_company_id, $state['date_from'], $state['date_to']);
    }

    #[Computed]
    public function completeness(): array
    {
        $state = $this->form->getState();

        return app(ReportCompletenessService::class)->assess(Auth::user()->default_company_id, $state['date_from'], $state['date_to']);
    }
}
