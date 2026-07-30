<?php

namespace Webkul\Accounting\Filament\Clusters\Reporting\Pages;

use Barryvdh\DomPDF\Facade\Pdf;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Maatwebsite\Excel\Facades\Excel;
use Webkul\Accounting\Enums\ReportCurrencyMode;
use Webkul\Accounting\Filament\Clusters\Reporting;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\Exports\DirectCashFlowExport;
use Webkul\Accounting\Services\DirectCashFlowService;
use Webkul\Accounting\Services\ReportCompletenessService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Support\Models\Currency;

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
        $this->form->fill([
            'date_from'             => now()->startOfMonth()->toDateString(),
            'date_to'               => now()->endOfMonth()->toDateString(),
            'currency_mode'         => ReportCurrencyMode::Company->value,
            'reporting_currency_id' => null,
        ]);
    }

    protected function getFormSchema(): array
    {
        return [Section::make('Period')->columns(2)->schema([
            DatePicker::make('date_from')->label('From')->required()->live(),
            DatePicker::make('date_to')->label('To')->required()->live(),
            Select::make('currency_mode')->label('Currency mode')->options(ReportCurrencyMode::options())
                ->default(ReportCurrencyMode::Company->value)->live(),
            Select::make('reporting_currency_id')->label('Reporting currency')
                ->visible(fn (Get $get): bool => $get('currency_mode') === ReportCurrencyMode::Reporting->value)
                ->options(fn () => Currency::query()
                    ->whereHas('enabledCompanies', fn ($query) => $query
                        ->where('companies.id', Auth::user()?->default_company_id)
                        ->where('accounting_company_currencies.reporting_enabled', true))
                    ->orderBy('display_order')->get()
                    ->mapWithKeys(fn (Currency $currency): array => [$currency->id => $currency->display_name]))
                ->required(fn (Get $get): bool => $get('currency_mode') === ReportCurrencyMode::Reporting->value)
                ->searchable()->live(),
        ])];
    }

    protected function getFormStatePath(): string
    {
        return 'data';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('excel')->label('Export Excel')->icon('heroicon-o-document-arrow-down')->color('success')
                ->action(function () {
                    $state = $this->form->getState();

                    return Excel::download(
                        new DirectCashFlowExport($this->cashFlowData, $state['date_from'], $state['date_to']),
                        "cash-flow-{$state['date_from']}-to-{$state['date_to']}.xlsx",
                    );
                }),
            Action::make('pdf')->label('Export PDF')->icon('heroicon-o-document-text')->color('danger')
                ->action(function () {
                    $state = $this->form->getState();
                    $pdf = Pdf::loadView('accounting::filament.clusters.reporting.pages.pdfs.direct-cash-flow', [
                        'data'     => $this->cashFlowData,
                        'dateFrom' => $state['date_from'],
                        'dateTo'   => $state['date_to'],
                    ]);

                    return response()->streamDownload(
                        fn () => print ($pdf->output()),
                        "cash-flow-{$state['date_from']}-to-{$state['date_to']}.pdf",
                    );
                }),
        ];
    }

    #[Computed]
    public function cashFlowData(): array
    {
        $state = $this->form->getState();

        return app(DirectCashFlowService::class)->calculateForMode(
            Auth::user()->default_company_id,
            $state['date_from'],
            $state['date_to'],
            $this->authorizedCurrencyMode(),
            isset($state['reporting_currency_id']) ? (int) $state['reporting_currency_id'] : null,
        );
    }

    #[Computed]
    public function completeness(): array
    {
        $state = $this->form->getState();

        return app(ReportCompletenessService::class)->assess(Auth::user()->default_company_id, $state['date_from'], $state['date_to']);
    }

    private function authorizedCurrencyMode(): string
    {
        $mode = $this->data['currency_mode'] ?? ReportCurrencyMode::Company->value;
        if ($mode !== ReportCurrencyMode::Company->value) {
            abort_unless(Auth::user()?->can(AccountingPermissions::ViewMultiCurrencyReports), 403);
        }

        return $mode;
    }
}
