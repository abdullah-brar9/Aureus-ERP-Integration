<?php

namespace Webkul\Accounting\Filament\Clusters\Reporting\Pages;

use Barryvdh\DomPDF\Facade\Pdf;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Maatwebsite\Excel\Facades\Excel;
use Malzariey\FilamentDaterangepickerFilter\Fields\DateRangePicker;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\MoveLine;
use Webkul\Accounting\Enums\ExchangeRateType;
use Webkul\Accounting\Enums\ReportCurrencyMode;
use Webkul\Accounting\Exceptions\MissingExchangeRateException;
use Webkul\Accounting\Filament\Clusters\Reporting;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\Concerns\NormalizeDateFilter;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\Exports\GeneralLedgerExport;
use Webkul\Accounting\Services\Currency\ExchangeRateService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

class GeneralLedger extends Page implements HasForms
{
    use HasFiltersForm, HasPageShield, InteractsWithForms, NormalizeDateFilter;

    protected string $view = 'accounting::filament.clusters.reporting.pages.general-ledger';

    protected static ?string $cluster = Reporting::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?int $navigationSort = 93;

    public ?array $data = [];

    public array $expandedAccounts = [];

    public array $loadedMoveLines = [];

    public ?int $loadingAccountId = null;

    protected static function getPagePermission(): ?string
    {
        return 'page_accounting_general_ledger';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('accounting::filament/clusters/reporting.pages.general-ledger.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('accounting::filament/clusters/reporting.pages.general-ledger.navigation.title');
    }

    public function getTitle(): string
    {
        return __('accounting::filament/clusters/reporting.pages.general-ledger.navigation.title');
    }

    public function mount(): void
    {
        $requestedAccountId = (int) request()->query('account_id', 0);

        $this->form->fill([
            'accounts'              => $requestedAccountId > 0 ? [$requestedAccountId] : [],
            'show_zero_activity'    => true,
            'show_zero_balance'     => true,
            'show_classification'   => false,
            'currency_mode'         => ReportCurrencyMode::Company->value,
            'reporting_currency_id' => null,
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('excel')
                ->label(__('accounting::filament/clusters/reporting.pages.general-ledger.actions.export-excel'))
                ->icon('heroicon-o-document-arrow-down')
                ->color('success')
                ->action(function () {
                    $data = $this->generalLedgerData;

                    return Excel::download(
                        new GeneralLedgerExport(
                            $data['accounts'],
                            $data['date_from'],
                            $data['date_to'],
                            fn ($accountId) => $this->getAccountMoves($accountId),
                            $this->expandedAccounts,
                            $data['currency_mode'],
                            $data['conversion_status'],
                            $data['rate_basis'],
                        ),
                        'general-ledger-'.$data['date_from']->format('Y-m-d').'-'.$data['date_to']->format('Y-m-d').'.xlsx'
                    );
                }),
            Action::make('pdf')
                ->label(__('accounting::filament/clusters/reporting.pages.general-ledger.actions.export-pdf'))
                ->icon('heroicon-o-document-text')
                ->color('danger')
                ->action(function () {
                    $data = $this->generalLedgerData;
                    $getAccountMoves = fn ($accountId) => $this->getAccountMoves($accountId);

                    $pdf = Pdf::loadView('accounting::filament.clusters.reporting.pages.pdfs.general-ledger', [
                        'data'             => $data,
                        'getAccountMoves'  => $getAccountMoves,
                        'expandedAccounts' => $this->expandedAccounts,
                    ])->setPaper('a4', 'landscape');

                    return response()->streamDownload(function () use ($pdf) {
                        echo $pdf->output();
                    }, 'general-ledger-'.$data['date_from']->format('Y-m-d').'-'.$data['date_to']->format('Y-m-d').'.pdf');
                }),
        ];
    }

    protected function getFormSchema(): array
    {
        return [
            Section::make()
                ->columns([
                    'default' => 1,
                    'sm'      => 2,
                ])
                ->schema([
                    DateRangePicker::make('date_range')
                        ->label(__('accounting::filament/clusters/reporting.pages.general-ledger.filters.date-range'))
                        ->suffixIcon('heroicon-o-calendar')
                        ->defaultThisMonth()
                        ->ranges([
                            'Today'        => [now()->startOfDay(), now()->endOfDay()],
                            'Yesterday'    => [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()],
                            'This Month'   => [now()->startOfMonth(), now()->endOfMonth()],
                            'Last Month'   => [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()],
                            'This Quarter' => [now()->startOfQuarter(), now()->endOfQuarter()],
                            'Last Quarter' => [now()->subQuarter()->startOfQuarter(), now()->subQuarter()->endOfQuarter()],
                            'This Year'    => [now()->startOfYear(), now()->endOfYear()],
                            'Last Year'    => [now()->subYear()->startOfYear(), now()->subYear()->endOfYear()],
                        ])
                        ->alwaysShowCalendar()
                        ->live()
                        ->afterStateUpdated(fn () => $this->resetExpandedState()),

                    Select::make('journals')
                        ->label(__('accounting::filament/clusters/reporting.pages.general-ledger.filters.journals'))
                        ->multiple()
                        ->options(fn () => Journal::query()
                            ->where('company_id', Auth::user()?->default_company_id)
                            ->pluck('name', 'id'))
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(fn () => $this->resetExpandedState()),

                    Select::make('accounts')
                        ->label('Postable accounts')
                        ->multiple()
                        ->options(fn (): array => Account::query()->postable()->where('deprecated', false)
                            ->whereHas('companies', fn ($query) => $query->where('companies.id', Auth::user()?->default_company_id))
                            ->orderBy('code')->limit(50)->get()
                            ->mapWithKeys(fn (Account $account): array => [$account->id => trim("{$account->code} {$account->name}")])->all())
                        ->getSearchResultsUsing(fn (string $search): array => Account::query()
                            ->postable()
                            ->where('deprecated', false)
                            ->whereHas('companies', fn ($query) => $query->where('companies.id', Auth::user()?->default_company_id))
                            ->where(fn ($query) => $query->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                            ->orderBy('code')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn (Account $account): array => [$account->id => trim("{$account->code} {$account->name}")])->all())
                        ->getOptionLabelsUsing(fn (array $values): array => Account::query()->whereKey($values)->get()
                            ->mapWithKeys(fn (Account $account): array => [$account->id => trim("{$account->code} {$account->name}")])->all())
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(fn () => $this->resetExpandedState()),

                    Select::make('groups')
                        ->label('Account groups')
                        ->multiple()
                        ->options(fn (): array => Account::query()->where('is_group', true)->where('deprecated', false)
                            ->whereHas('companies', fn ($query) => $query->where('companies.id', Auth::user()?->default_company_id))
                            ->orderBy('name')->limit(50)->pluck('name', 'id')->all())
                        ->getSearchResultsUsing(fn (string $search): array => Account::query()
                            ->where('is_group', true)
                            ->whereHas('companies', fn ($query) => $query->where('companies.id', Auth::user()?->default_company_id))
                            ->where(fn ($query) => $query->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                            ->orderBy('name')
                            ->limit(50)->pluck('name', 'id')->all())
                        ->getOptionLabelsUsing(fn (array $values): array => Account::query()->whereKey($values)->pluck('name', 'id')->all())
                        ->helperText('Groups filter the tree; journal postings remain limited to postable accounts.')
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(fn () => $this->resetExpandedState()),

                    Toggle::make('show_zero_activity')
                        ->label('Show zero-activity accounts')
                        ->default(true)
                        ->live()
                        ->afterStateUpdated(fn () => $this->resetExpandedState()),

                    Toggle::make('show_zero_balance')
                        ->label('Show zero-balance accounts')
                        ->default(true)
                        ->live()
                        ->afterStateUpdated(fn () => $this->resetExpandedState()),

                    Toggle::make('show_classification')
                        ->label('Show account classification')
                        ->default(false)
                        ->live(),
                    Select::make('currency_mode')
                        ->label('Currency mode')
                        ->options(ReportCurrencyMode::options())
                        ->default(ReportCurrencyMode::Company->value)
                        ->live()
                        ->afterStateUpdated(fn () => $this->resetExpandedState()),
                    Select::make('reporting_currency_id')
                        ->label('Reporting currency')
                        ->visible(fn (Get $get): bool => $get('currency_mode') === ReportCurrencyMode::Reporting->value)
                        ->options(fn () => Currency::query()
                            ->whereHas('enabledCompanies', fn ($query) => $query
                                ->where('companies.id', Auth::user()?->default_company_id)
                                ->where('accounting_company_currencies.reporting_enabled', true))
                            ->orderBy('display_order')->get()
                            ->mapWithKeys(fn (Currency $currency): array => [$currency->id => $currency->display_name]))
                        ->required(fn (Get $get): bool => $get('currency_mode') === ReportCurrencyMode::Reporting->value)
                        ->searchable()->live()
                        ->afterStateUpdated(fn () => $this->resetExpandedState()),
                ])
                ->columnSpanFull(),
        ];
    }

    protected function getFormStatePath(): string
    {
        return 'data';
    }

    #[Computed]
    public function generalLedgerData(): array
    {
        $dateRange = $this->parseDateRange();
        $dateFrom = $dateRange ? Carbon::parse($dateRange[0]) : now()->startOfYear();
        $dateTo = $dateRange ? Carbon::parse($dateRange[1]) : now();

        $state = $this->form->getState();
        $journalIds = $state['journals'] ?? [];
        $accountIds = $state['accounts'] ?? [];
        $groupIds = $state['groups'] ?? [];
        $companyId = Auth::user()->default_company_id;
        $companyCurrencyId = (int) Auth::user()?->defaultCompany?->currency_id;
        $currencyMode = $this->authorizedCurrencyMode();
        $isOriginal = $currencyMode === ReportCurrencyMode::Original->value;
        $debitExpression = $isOriginal
            ? 'COALESCE(accounts_account_move_lines.original_debit, accounts_account_move_lines.debit)'
            : 'accounts_account_move_lines.debit';
        $creditExpression = $isOriginal
            ? 'COALESCE(accounts_account_move_lines.original_credit, accounts_account_move_lines.credit)'
            : 'accounts_account_move_lines.credit';
        $balanceExpression = "({$debitExpression} - {$creditExpression})";
        $select = [
            'accounts_accounts.id',
            'accounts_accounts.code',
            'accounts_accounts.name',
            'accounts_accounts.account_type',
            'accounts_accounts.source_classification_path',
            DB::raw("COALESCE(SUM(CASE WHEN accounts_account_moves.date < ? THEN {$balanceExpression} ELSE 0 END), 0) as opening_balance"),
            DB::raw("COALESCE(SUM(CASE WHEN accounts_account_moves.date BETWEEN ? AND ? THEN {$debitExpression} ELSE 0 END), 0) as period_debit"),
            DB::raw("COALESCE(SUM(CASE WHEN accounts_account_moves.date BETWEEN ? AND ? THEN {$creditExpression} ELSE 0 END), 0) as period_credit"),
            DB::raw("COALESCE(SUM(CASE WHEN accounts_account_moves.date <= ? THEN {$balanceExpression} ELSE 0 END), 0) as ending_balance"),
        ];
        if ($isOriginal) {
            $select[] = DB::raw("COALESCE(accounts_account_move_lines.original_currency_id, accounts_account_move_lines.currency_id, {$companyCurrencyId}) as report_currency_id");
            $select[] = 'currencies.code as report_currency';
        }

        $accountsQuery = Account::select($select)
            ->leftJoin('accounts_account_move_lines', 'accounts_accounts.id', '=', 'accounts_account_move_lines.account_id')
            ->leftJoin('accounts_account_moves', function ($join) use ($companyId, $journalIds) {
                $join->on('accounts_account_move_lines.move_id', '=', 'accounts_account_moves.id')
                    ->where('accounts_account_moves.state', MoveState::POSTED)
                    ->where('accounts_account_moves.company_id', $companyId);

                if ($journalIds !== []) {
                    $join->whereIn('accounts_account_moves.journal_id', $journalIds);
                }
            })
            ->addBinding([$dateFrom, $dateFrom, $dateTo, $dateFrom, $dateTo, $dateTo], 'select')
            ->where('accounts_accounts.deprecated', false)
            ->where('accounts_accounts.is_group', false)
            ->whereHas('companies', fn ($query) => $query->where('companies.id', $companyId))
            ->groupBy(
                'accounts_accounts.id',
                'accounts_accounts.code',
                'accounts_accounts.name',
                'accounts_accounts.account_type',
                'accounts_accounts.source_classification_path',
            )
            ->orderBy('accounts_accounts.code');

        if ($isOriginal) {
            $accountsQuery->leftJoin(
                'currencies',
                DB::raw("COALESCE(accounts_account_move_lines.original_currency_id, accounts_account_move_lines.currency_id, {$companyCurrencyId})"),
                '=',
                'currencies.id',
            )->groupBy([
                DB::raw("COALESCE(accounts_account_move_lines.original_currency_id, accounts_account_move_lines.currency_id, {$companyCurrencyId})"),
                'currencies.code',
            ]);
        }

        if ($accountIds !== []) {
            $accountsQuery->whereIn('accounts_accounts.id', $accountIds);
        }

        if ($groupIds !== []) {
            $descendantIds = Account::query()
                ->whereIn('id', $groupIds)
                ->with('descendants')
                ->get()
                ->flatMap(fn (Account $group) => $group->getDescendantIds())
                ->unique()
                ->values()
                ->all();

            $accountsQuery->whereIn('accounts_accounts.id', $descendantIds);
        }

        if (! ($state['show_zero_activity'] ?? true)) {
            $accountsQuery->havingRaw('(period_debit != 0 OR period_credit != 0)');
        }

        if (! ($state['show_zero_balance'] ?? true)) {
            $accountsQuery->havingRaw('ending_balance != 0');
        }

        $accounts = $accountsQuery->get();
        $conversion = ['status' => 'complete', 'warnings' => [], 'rate_basis' => 'Posted company-currency debit and credit fields.'];
        if ($currencyMode === ReportCurrencyMode::Reporting->value) {
            [$accounts, $conversion] = $this->translateAccountSummaries($accounts, $companyId, $dateFrom, $dateTo, $journalIds);
        }
        foreach ($accounts as $account) {
            $account->ledger_key = $isOriginal ? "{$account->id}:{$account->report_currency_id}" : (string) $account->id;
            $account->report_currency ??= $currencyMode === ReportCurrencyMode::Company->value
                ? Auth::user()?->defaultCompany?->currency?->code
                : null;
        }

        return [
            'accounts'            => $accounts,
            'date_from'           => $dateFrom,
            'date_to'             => $dateTo,
            'show_classification' => (bool) ($state['show_classification'] ?? false),
            'currency_mode'       => $currencyMode,
            'conversion_status'   => $conversion['status'],
            'warnings'            => $conversion['warnings'],
            'rate_basis'          => $conversion['rate_basis'],
        ];
    }

    public function toggleAccountLines($accountId): void
    {
        if (in_array($accountId, $this->expandedAccounts)) {
            $this->expandedAccounts = array_values(array_diff($this->expandedAccounts, [$accountId]));
        } else {
            $this->expandedAccounts[] = $accountId;

            if (! isset($this->loadedMoveLines[$accountId])) {
                $this->loadedMoveLines[$accountId] = $this->fetchAccountMoves($accountId);
            }
        }
    }

    public function isAccountExpanded($accountId): bool
    {
        return in_array($accountId, $this->expandedAccounts);
    }

    public function expandAll(): void
    {
        $data = $this->generalLedgerData;
        $this->expandedAccounts = $data['accounts']->pluck('ledger_key')->toArray();

        foreach ($this->expandedAccounts as $accountId) {
            if (! isset($this->loadedMoveLines[$accountId])) {
                $this->loadedMoveLines[$accountId] = $this->fetchAccountMoves($accountId);
            }
        }
    }

    public function collapseAll(): void
    {
        $this->expandedAccounts = [];
    }

    public function resetExpandedState(): void
    {
        $this->expandedAccounts = [];

        $this->loadedMoveLines = [];
    }

    public function getAccountMoves($accountId): array
    {
        if (! isset($this->loadedMoveLines[$accountId])) {
            $this->loadedMoveLines[$accountId] = $this->fetchAccountMoves($accountId);
        }

        return $this->loadedMoveLines[$accountId];
    }

    protected function fetchAccountMoves($accountId): array
    {
        $dateRange = $this->parseDateRange();
        $dateFrom = $dateRange ? Carbon::parse($dateRange[0]) : now()->startOfYear();
        $dateTo = $dateRange ? Carbon::parse($dateRange[1]) : now();
        $journalIds = $this->form->getState()['journals'] ?? [];
        $companyId = Auth::user()->default_company_id;
        $currencyMode = $this->authorizedCurrencyMode();
        [$resolvedAccountId, $originalCurrencyId] = str_contains((string) $accountId, ':')
            ? array_map('intval', explode(':', (string) $accountId, 2))
            : [(int) $accountId, null];

        $query = MoveLine::select(
            'accounts_account_move_lines.*',
            'accounts_account_moves.name as move_name',
            'accounts_account_moves.move_type',
            'accounts_account_moves.date',
            'accounts_account_moves.reference as ref',
            'accounts_journals.name as journal_name',
            'partners_partners.name as partner_name'
        )
            ->join('accounts_account_moves', 'accounts_account_move_lines.move_id', '=', 'accounts_account_moves.id')
            ->leftJoin('accounts_journals', 'accounts_account_moves.journal_id', '=', 'accounts_journals.id')
            ->leftJoin('partners_partners', 'accounts_account_move_lines.partner_id', '=', 'partners_partners.id')
            ->where('accounts_account_move_lines.account_id', $resolvedAccountId)
            ->where('accounts_account_moves.state', MoveState::POSTED)
            ->where('accounts_account_moves.company_id', $companyId)
            ->whereBetween('accounts_account_moves.date', [$dateFrom, $dateTo])
            ->orderBy('accounts_account_moves.date')
            ->orderBy('accounts_account_moves.id');

        if (! empty($journalIds)) {
            $query->whereIn('accounts_account_moves.journal_id', $journalIds);
        }

        if ($currencyMode === ReportCurrencyMode::Original->value && $originalCurrencyId) {
            $query->whereRaw(
                'COALESCE(accounts_account_move_lines.original_currency_id, accounts_account_move_lines.currency_id) = ?',
                [$originalCurrencyId],
            );
        }

        $rows = $query->get();
        if ($currencyMode === ReportCurrencyMode::Original->value) {
            $currency = Currency::query()->find($originalCurrencyId);
            $rows->each(function (MoveLine $line) use ($currency): void {
                $line->debit = $line->original_debit ?? $line->debit;
                $line->credit = $line->original_credit ?? $line->credit;
                $line->report_currency = $currency?->code ?: $currency?->name;
            });
        } elseif ($currencyMode === ReportCurrencyMode::Reporting->value) {
            $company = Company::query()->with('currency')->findOrFail($companyId);
            $target = Currency::query()->findOrFail((int) $this->form->getState()['reporting_currency_id']);
            $rows->each(function (MoveLine $line) use ($company, $target): void {
                try {
                    $rate = app(ExchangeRateService::class)->resolve(
                        $company,
                        $company->currency,
                        $target,
                        $line->date->toDateString(),
                        [ExchangeRateType::Transaction, ExchangeRateType::Daily],
                    );
                    $line->debit = app(ExchangeRateService::class)->convert((string) $line->debit, $rate);
                    $line->credit = app(ExchangeRateService::class)->convert((string) $line->credit, $rate);
                    $line->report_currency = $target->code ?: $target->name;
                } catch (MissingExchangeRateException) {
                    $line->debit = '0';
                    $line->credit = '0';
                    $line->report_currency = $target->code ?: $target->name;
                }
            });
        } else {
            $currencyCode = Auth::user()?->defaultCompany?->currency?->code;
            $rows->each(fn (MoveLine $line) => $line->report_currency = $currencyCode);
        }

        return $rows->toArray();
    }

    private function authorizedCurrencyMode(): string
    {
        $mode = $this->form->getState()['currency_mode'] ?? ReportCurrencyMode::Company->value;
        if ($mode !== ReportCurrencyMode::Company->value) {
            abort_unless(Auth::user()?->can(AccountingPermissions::ViewMultiCurrencyReports), 403);
        }

        return $mode;
    }

    private function translateAccountSummaries($accounts, int $companyId, Carbon $dateFrom, Carbon $dateTo, array $journalIds): array
    {
        $company = Company::query()->with('currency')->findOrFail($companyId);
        $target = Currency::query()->findOrFail((int) ($this->form->getState()['reporting_currency_id'] ?? 0));
        abort_unless($company->enabledCurrencies()->where('currencies.id', $target->id)->wherePivot('reporting_enabled', true)->exists(), 422);

        $daily = DB::table('accounts_account_move_lines as lines')
            ->join('accounts_account_moves as moves', 'moves.id', '=', 'lines.move_id')
            ->where('moves.company_id', $companyId)
            ->where('moves.state', MoveState::POSTED->value)
            ->whereIn('lines.account_id', $accounts->pluck('id')->unique())
            ->whereDate('moves.date', '<=', $dateTo)
            ->when($journalIds !== [], fn ($query) => $query->whereIn('moves.journal_id', $journalIds))
            ->selectRaw('lines.account_id, moves.date, SUM(lines.debit) debit, SUM(lines.credit) credit')
            ->groupBy(['lines.account_id', 'moves.date'])
            ->get();
        $buckets = [];
        $warnings = [];

        foreach ($daily as $item) {
            try {
                $rate = app(ExchangeRateService::class)->resolve(
                    $company,
                    $company->currency,
                    $target,
                    substr((string) $item->date, 0, 10),
                    [ExchangeRateType::Transaction, ExchangeRateType::Daily],
                );
            } catch (MissingExchangeRateException $exception) {
                $warnings[$exception->getMessage()] = true;

                continue;
            }
            $debit = BigDecimal::of(app(ExchangeRateService::class)->convert((string) $item->debit, $rate));
            $credit = BigDecimal::of(app(ExchangeRateService::class)->convert((string) $item->credit, $rate));
            $bucket = &$buckets[(int) $item->account_id];
            $bucket ??= ['opening' => BigDecimal::zero(), 'debit' => BigDecimal::zero(), 'credit' => BigDecimal::zero(), 'ending' => BigDecimal::zero()];
            $net = $debit->minus($credit);
            $bucket['ending'] = $bucket['ending']->plus($net);
            if (substr((string) $item->date, 0, 10) < $dateFrom->toDateString()) {
                $bucket['opening'] = $bucket['opening']->plus($net);
            } else {
                $bucket['debit'] = $bucket['debit']->plus($debit);
                $bucket['credit'] = $bucket['credit']->plus($credit);
            }
            unset($bucket);
        }

        foreach ($accounts as $account) {
            $bucket = $buckets[$account->id] ?? ['opening' => BigDecimal::zero(), 'debit' => BigDecimal::zero(), 'credit' => BigDecimal::zero(), 'ending' => BigDecimal::zero()];
            $account->opening_balance = $bucket['opening']->__toString();
            $account->period_debit = $bucket['debit']->__toString();
            $account->period_credit = $bucket['credit']->__toString();
            $account->ending_balance = $bucket['ending']->__toString();
            $account->report_currency = $target->code ?: $target->name;
        }

        return [$accounts, [
            'status'     => $warnings === [] ? 'complete' : 'incomplete',
            'warnings'   => array_keys($warnings),
            'rate_basis' => 'Approved transaction-date/daily rates applied to each posted journal date.',
        ]];
    }
}
