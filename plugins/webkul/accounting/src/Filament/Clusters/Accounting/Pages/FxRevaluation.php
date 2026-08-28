<?php

namespace Webkul\Accounting\Filament\Clusters\Accounting\Pages;

use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Auth;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Models\Journal;
use Webkul\Accounting\Filament\Clusters\Accounting;
use Webkul\Accounting\Models\FxRevaluation as FxRevaluationModel;
use Webkul\Accounting\Services\Currency\FxRevaluationService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

class FxRevaluation extends Page implements HasForms
{
    use HasPageShield, InteractsWithForms;

    protected string $view = 'accounting::filament.clusters.accounting.pages.fx-revaluation';

    protected static ?string $cluster = Accounting::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calculator';

    protected static ?int $navigationSort = 6;

    public ?array $data = [];

    protected static function getPagePermission(): ?string
    {
        return AccountingPermissions::FxRevaluationPage;
    }

    public static function getNavigationLabel(): string
    {
        return 'FX Revaluation';
    }

    public function mount(): void
    {
        $this->form->fill([
            'company_id'    => Auth::user()?->default_company_id,
            'period_end'    => now()->endOfMonth()->toDateString(),
            'reversal_date' => now()->addMonthNoOverflow()->startOfMonth()->toDateString(),
        ]);
    }

    protected function getFormStatePath(): string
    {
        return 'data';
    }

    protected function getFormSchema(): array
    {
        $companyId = (int) Auth::user()?->default_company_id;

        return [
            Section::make('Closing-rate revaluation')->columns(2)->schema([
                Select::make('company_id')->options(Company::query()->whereKey($companyId)->pluck('name', 'id'))
                    ->disabled()->dehydrated()->required(),
                Select::make('currency_id')
                    ->label('Foreign currency')
                    ->searchable()
                    ->options(fn (): array => Currency::query()->active()->whereKeyNot(Auth::user()?->defaultCompany?->currency_id)
                        ->whereHas('enabledCompanies', fn ($query) => $query->where('companies.id', $companyId))
                        ->orderBy('display_order')->limit(50)->get()
                        ->mapWithKeys(fn (Currency $currency): array => [$currency->id => $currency->display_name])->all())
                    ->getSearchResultsUsing(fn (string $search): array => Currency::query()
                        ->active()
                        ->whereKeyNot(Auth::user()?->defaultCompany?->currency_id)
                        ->whereHas('enabledCompanies', fn ($query) => $query->where('companies.id', $companyId))
                        ->where(fn ($query) => $query->where('code', 'like', "%{$search}%")->orWhere('full_name', 'like', "%{$search}%"))
                        ->limit(50)->get()->mapWithKeys(fn (Currency $currency): array => [$currency->id => $currency->display_name])->all())
                    ->getOptionLabelUsing(fn ($value): ?string => Currency::query()->find($value)?->display_name)
                    ->required(),
                Select::make('journal_id')
                    ->label('General journal')
                    ->searchable()
                    ->options(fn (): array => Journal::query()->where('company_id', $companyId)->where('type', JournalType::GENERAL)
                        ->orderBy('name')->limit(50)->pluck('name', 'id')->all())
                    ->getSearchResultsUsing(fn (string $search): array => Journal::query()
                        ->where('company_id', $companyId)->where('type', JournalType::GENERAL)
                        ->where('name', 'like', "%{$search}%")->limit(50)->pluck('name', 'id')->all())
                    ->getOptionLabelUsing(fn ($value): ?string => Journal::query()->where('company_id', $companyId)->find($value)?->name)
                    ->required(),
                DatePicker::make('period_end')->required(),
                DatePicker::make('reversal_date')->label('Optional reversal date')->after('period_end'),
            ]),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createDraft')
                ->label('Create revaluation draft')
                ->authorize(AccountingPermissions::RunFxRevaluation)
                ->requiresConfirmation()
                ->action(function (): void {
                    try {
                        $state = $this->form->getState();
                        $revaluation = app(FxRevaluationService::class)->createDraft(
                            Company::query()->findOrFail(Auth::user()?->default_company_id),
                            Currency::query()->findOrFail($state['currency_id']),
                            $state['period_end'],
                            Journal::query()->findOrFail($state['journal_id']),
                            $state['reversal_date'] ?: null,
                        );
                        Notification::make()->success()->title(
                            $revaluation->move_id ? 'FX revaluation and reversal drafts created.' : 'No FX adjustment was required.',
                        )->send();
                    } catch (\Throwable $exception) {
                        Notification::make()->danger()->title('FX revaluation could not be created.')->body($exception->getMessage())->persistent()->send();
                    }
                }),
        ];
    }

    protected function getViewData(): array
    {
        return [
            'revaluations' => FxRevaluationModel::query()
                ->where('company_id', Auth::user()?->default_company_id)
                ->with(['currency', 'exchangeRate', 'move', 'reversalMove'])
                ->latest('period_end')
                ->limit(25)
                ->get(),
        ];
    }
}
