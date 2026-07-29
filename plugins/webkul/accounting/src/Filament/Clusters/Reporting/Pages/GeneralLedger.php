<?php

namespace Webkul\Accounting\Filament\Clusters\Reporting\Pages;

use Barryvdh\DomPDF\Facade\Pdf;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Maatwebsite\Excel\Facades\Excel;
use Malzariey\FilamentDaterangepickerFilter\Fields\DateRangePicker;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\MoveLine;
use Webkul\Accounting\Filament\Clusters\Reporting;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\Concerns\NormalizeDateFilter;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\Exports\GeneralLedgerExport;

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
            'accounts'             => $requestedAccountId > 0 ? [$requestedAccountId] : [],
            'show_zero_activity'   => true,
            'show_zero_balance'    => true,
            'show_classification'  => false,
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
                            $this->expandedAccounts
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
                        ->options(fn () => Account::query()
                            ->postable()
                            ->where('deprecated', false)
                            ->whereHas('companies', fn ($query) => $query->where('companies.id', Auth::user()?->default_company_id))
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn (Account $account) => [$account->id => trim("{$account->code} {$account->name}")]))
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(fn () => $this->resetExpandedState()),

                    Select::make('groups')
                        ->label('Account groups')
                        ->multiple()
                        ->options(fn () => Account::query()
                            ->where('is_group', true)
                            ->whereHas('companies', fn ($query) => $query->where('companies.id', Auth::user()?->default_company_id))
                            ->orderBy('name')
                            ->pluck('name', 'id'))
                        ->helperText('Groups filter the tree; journal postings remain limited to postable accounts.')
                        ->searchable()
                        ->preload()
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

        $accountsQuery = Account::select(
            'accounts_accounts.id',
            'accounts_accounts.code',
            'accounts_accounts.name',
            'accounts_accounts.account_type',
            'accounts_accounts.source_classification_path',
            DB::raw('COALESCE(SUM(CASE WHEN accounts_account_moves.date < ? THEN accounts_account_move_lines.balance ELSE 0 END), 0) as opening_balance'),
            DB::raw('COALESCE(SUM(CASE WHEN accounts_account_moves.date BETWEEN ? AND ? THEN accounts_account_move_lines.debit ELSE 0 END), 0) as period_debit'),
            DB::raw('COALESCE(SUM(CASE WHEN accounts_account_moves.date BETWEEN ? AND ? THEN accounts_account_move_lines.credit ELSE 0 END), 0) as period_credit'),
            DB::raw('COALESCE(SUM(CASE WHEN accounts_account_moves.date <= ? THEN accounts_account_move_lines.balance ELSE 0 END), 0) as ending_balance')
        )
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

        return [
            'accounts'            => $accountsQuery->get(),
            'date_from'           => $dateFrom,
            'date_to'             => $dateTo,
            'show_classification' => (bool) ($state['show_classification'] ?? false),
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
        $this->expandedAccounts = $data['accounts']->pluck('id')->toArray();

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
            ->where('accounts_account_move_lines.account_id', $accountId)
            ->where('accounts_account_moves.state', MoveState::POSTED)
            ->where('accounts_account_moves.company_id', $companyId)
            ->whereBetween('accounts_account_moves.date', [$dateFrom, $dateTo])
            ->orderBy('accounts_account_moves.date')
            ->orderBy('accounts_account_moves.id');

        if (! empty($journalIds)) {
            $query->whereIn('accounts_account_moves.journal_id', $journalIds);
        }

        return $query->get()->toArray();
    }
}
