<?php

namespace Webkul\Accounting\Filament\Clusters\Reporting\Pages;

use Barryvdh\DomPDF\Facade\Pdf;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Maatwebsite\Excel\Facades\Excel;
use Webkul\Account\Models\Journal;
use Webkul\Accounting\Enums\ReportCurrencyMode;
use Webkul\Accounting\Filament\Clusters\Reporting;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\Exports\TrialBalanceExport;
use Webkul\Accounting\Services\ReportCompletenessService;
use Webkul\Accounting\Services\TrialBalanceService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

/**
 * Ledger-backed Trial Balance: opening / movement / adjustment / closing from
 * posted account-move lines only (see TrialBalanceService), replacing the old
 * hardcoded page. Grouped under Reports beside the other statements.
 */
class TrialBalance extends Page implements HasForms
{
    use HasPageShield, InteractsWithForms;

    protected string $view = 'accounting::filament.clusters.reporting.pages.trial-balance';

    protected static ?string $cluster = Reporting::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static ?int $navigationSort = 2;

    public ?array $data = [];

    protected static function getPagePermission(): ?string
    {
        return 'page_accounting_trial_balance';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Reports';
    }

    public static function getNavigationLabel(): string
    {
        return __('accounting::filament/clusters/reporting.pages.trial-balance.navigation.title');
    }

    public function getTitle(): string
    {
        return __('accounting::filament/clusters/reporting.pages.trial-balance.navigation.title');
    }

    public function mount(): void
    {
        $this->form->fill([
            'company_id'            => Auth::user()?->default_company_id,
            'from_date'             => now()->startOfMonth()->toDateString(),
            'to_date'               => now()->endOfMonth()->toDateString(),
            'posted_only'           => true,
            'include_zero'          => false,
            'include_groups'        => false,
            'journals'              => [],
            'currency_mode'         => ReportCurrencyMode::Company->value,
            'reporting_currency_id' => null,
        ]);
    }

    protected function getFormSchema(): array
    {
        return [
            Section::make()
                ->columns(['default' => 1, 'sm' => 3])
                ->schema([
                    Select::make('company_id')
                        ->label('Company')
                        ->options(fn () => Company::query()->whereKey(Auth::user()?->default_company_id)->pluck('name', 'id'))
                        ->default(Auth::user()?->default_company_id)
                        ->disabled()->dehydrated()->live(),
                    DatePicker::make('from_date')
                        ->label('From')->live(),
                    DatePicker::make('to_date')
                        ->label('To')->live(),
                    Select::make('journals')
                        ->label('Journals')
                        ->multiple()
                        ->options(fn (Get $get) => Journal::query()
                            ->where('company_id', (int) $get('company_id'))
                            ->pluck('name', 'id'))
                        ->searchable()->live(),
                    Select::make('currency_mode')
                        ->label('Currency mode')
                        ->options(ReportCurrencyMode::options())
                        ->default(ReportCurrencyMode::Company->value)
                        ->live(),
                    Select::make('reporting_currency_id')
                        ->label('Reporting currency')
                        ->visible(fn (Get $get): bool => $get('currency_mode') === ReportCurrencyMode::Reporting->value)
                        ->options(fn (Get $get) => Currency::query()
                            ->whereHas('enabledCompanies', fn ($query) => $query
                                ->where('companies.id', (int) $get('company_id'))
                                ->where('accounting_company_currencies.reporting_enabled', true))
                            ->orderBy('display_order')->get()
                            ->mapWithKeys(fn (Currency $currency): array => [$currency->id => $currency->display_name]))
                        ->required(fn (Get $get): bool => $get('currency_mode') === ReportCurrencyMode::Reporting->value)
                        ->searchable()->live(),
                    Toggle::make('posted_only')->label('Posted only')->default(true)->live(),
                    Toggle::make('include_zero')->label('Show zero balances')->live(),
                    Toggle::make('include_groups')->label('Show hierarchy groups')->live(),
                ])
                ->columnSpanFull(),
        ];
    }

    protected function getFormStatePath(): ?string
    {
        return 'data';
    }

    #[Computed]
    public function trialBalanceData(): array
    {
        $companyId = (int) Auth::user()?->default_company_id;

        if (! $companyId) {
            return ['rows' => [], 'totals' => [], 'company' => null, 'from' => null, 'to' => null];
        }

        $from = $this->data['from_date'] ?? now()->startOfMonth()->toDateString();
        $to = $this->data['to_date'] ?? now()->endOfMonth()->toDateString();

        $result = app(TrialBalanceService::class)->compute($companyId, $from, $to, [
            'posted_only'           => (bool) ($this->data['posted_only'] ?? true),
            'journal_ids'           => array_map('intval', $this->data['journals'] ?? []),
            'include_zero'          => (bool) ($this->data['include_zero'] ?? false),
            'include_groups'        => (bool) ($this->data['include_groups'] ?? false),
            'currency_mode'         => $this->authorizedCurrencyMode(),
            'reporting_currency_id' => isset($this->data['reporting_currency_id']) ? (int) $this->data['reporting_currency_id'] : null,
        ]);

        return array_merge($result, [
            'company' => Company::find($companyId),
            'from'    => $from,
            'to'      => $to,
        ]);
    }

    private function authorizedCurrencyMode(): string
    {
        $mode = $this->data['currency_mode'] ?? ReportCurrencyMode::Company->value;
        if ($mode !== ReportCurrencyMode::Company->value) {
            abort_unless(Auth::user()?->can(AccountingPermissions::ViewMultiCurrencyReports), 403);
        }

        return $mode;
    }

    #[Computed]
    public function completeness(): array
    {
        $companyId = (int) ($this->data['company_id'] ?? Auth::user()?->default_company_id);
        $from = $this->data['from_date'] ?? now()->startOfMonth()->toDateString();
        $to = $this->data['to_date'] ?? now()->endOfMonth()->toDateString();

        return app(ReportCompletenessService::class)->assess($companyId, $from, $to);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('excel')
                ->label('Export Excel')
                ->icon('heroicon-o-document-arrow-down')
                ->color('success')
                ->action(function () {
                    $data = $this->trialBalanceData;
                    $name = 'trial-balance-'.$data['from'].'-to-'.$data['to'].'.xlsx';

                    return Excel::download(new TrialBalanceExport(
                        $data['rows'],
                        $data['totals'],
                        $data['company']?->name,
                        $data['from'],
                        $data['to'],
                        $data['currency_totals'] ?? [],
                        $data['currency_mode'] ?? ReportCurrencyMode::Company->value,
                        $data['rate_basis'] ?? '',
                        $data['conversion_status'] ?? 'complete',
                    ), $name);
                }),
            Action::make('pdf')
                ->label('Export PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('danger')
                ->action(function () {
                    $data = $this->trialBalanceData;
                    $pdf = Pdf::loadView('accounting::filament.clusters.reporting.pages.pdfs.trial-balance', [
                        'rows'             => $data['rows'],
                        'totals'           => $data['totals'],
                        'currencyTotals'   => $data['currency_totals'] ?? [],
                        'currencyMode'     => $data['currency_mode'] ?? ReportCurrencyMode::Company->value,
                        'rateBasis'        => $data['rate_basis'] ?? '',
                        'conversionStatus' => $data['conversion_status'] ?? 'complete',
                        'company'          => $data['company']?->name,
                        'from'             => $data['from'],
                        'to'               => $data['to'],
                        'generatedAt'      => now(),
                    ])->setPaper('a4', 'landscape');

                    return response()->streamDownload(fn () => print ($pdf->output()), 'trial-balance-'.$data['from'].'-to-'.$data['to'].'.pdf');
                }),
        ];
    }
}
