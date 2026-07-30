<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Pages;

use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Auth;
use Webkul\Account\Models\Account;
use Webkul\Accounting\Enums\ExchangeRateSource;
use Webkul\Accounting\Filament\Clusters\Configuration;
use Webkul\Accounting\Services\Currency\CompanyCurrencyService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

class CompanyCurrencySettings extends Page implements HasForms
{
    use HasPageShield, InteractsWithForms;

    protected string $view = 'accounting::filament.clusters.configuration.pages.company-currency-settings';

    protected static ?string $cluster = Configuration::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?int $navigationSort = 5;

    public ?array $data = [];

    protected static function getPagePermission(): ?string
    {
        return AccountingPermissions::CompanyCurrencySettingsPage;
    }

    public static function getNavigationLabel(): string
    {
        return 'Company Currencies';
    }

    public function mount(): void
    {
        $company = $this->company();
        $enabled = $company->enabledCurrencies()->get();

        $this->form->fill([
            'company_id'                        => $company->id,
            'currency_id'                       => $company->currency_id,
            'transaction_currency_ids'          => $enabled->where('pivot.transaction_enabled', true)->pluck('id')->all(),
            'reporting_currency_ids'            => $enabled->where('pivot.reporting_enabled', true)->pluck('id')->all(),
            'fx_gain_account_id'                => $company->fx_gain_account_id,
            'fx_loss_account_id'                => $company->fx_loss_account_id,
            'rate_source_priority'              => $company->rate_source_priority ?: [
                ExchangeRateSource::BankStatement->value,
                ExchangeRateSource::Manual->value,
                ExchangeRateSource::Api->value,
                ExchangeRateSource::ImportedFile->value,
            ],
            'allow_previous_rate_fallback'      => $company->allow_previous_rate_fallback,
            'pnl_translation_policy'            => $company->pnl_translation_policy,
            'balance_sheet_translation_policy'  => $company->balance_sheet_translation_policy,
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
            Section::make('Company currency policy')->columns(2)->schema([
                Select::make('company_id')
                    ->options(Company::query()->whereKey($companyId)->pluck('name', 'id'))
                    ->disabled()
                    ->dehydrated()
                    ->required(),
                $this->currencySelect('currency_id', 'Base/default currency')->required(),
                $this->currencySelect('transaction_currency_ids', 'Enabled transaction currencies')->multiple(),
                $this->currencySelect('reporting_currency_ids', 'Reporting currencies')->multiple(),
                $this->accountSelect('fx_gain_account_id', 'FX Gain account', $companyId),
                $this->accountSelect('fx_loss_account_id', 'FX Loss account', $companyId),
                Select::make('rate_source_priority')
                    ->label('Rate-source priority')
                    ->multiple()
                    ->options([
                        ExchangeRateSource::BankStatement->value => 'Bank statement',
                        ExchangeRateSource::Manual->value        => 'Manual',
                        ExchangeRateSource::Api->value           => 'API',
                        ExchangeRateSource::ImportedFile->value  => 'Imported file',
                    ])
                    ->required(),
                Toggle::make('allow_previous_rate_fallback')
                    ->label('Allow previous valid approved rate')
                    ->helperText('Disabled by default. When enabled, the latest prior approved rate may be used and is marked on the snapshot.'),
                Select::make('pnl_translation_policy')
                    ->label('Profit & Loss translation')
                    ->options(['transaction_date' => 'Transaction date', 'monthly_average' => 'Monthly average'])
                    ->required(),
                Select::make('balance_sheet_translation_policy')
                    ->label('Balance Sheet translation')
                    ->options(['period_closing' => 'Period closing'])
                    ->required(),
            ]),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->authorize(AccountingPermissions::ManageCompanyCurrencies)
                ->action(function (): void {
                    $state = $this->form->getState();
                    app(CompanyCurrencyService::class)->update(
                        company: $this->company(),
                        baseCurrencyId: (int) $state['currency_id'],
                        transactionCurrencyIds: $state['transaction_currency_ids'] ?? [],
                        reportingCurrencyIds: $state['reporting_currency_ids'] ?? [],
                        fxGainAccountId: isset($state['fx_gain_account_id']) ? (int) $state['fx_gain_account_id'] : null,
                        fxLossAccountId: isset($state['fx_loss_account_id']) ? (int) $state['fx_loss_account_id'] : null,
                        rateSourcePriority: $state['rate_source_priority'] ?? [],
                        allowPreviousRateFallback: (bool) ($state['allow_previous_rate_fallback'] ?? false),
                        pnlTranslationPolicy: $state['pnl_translation_policy'],
                        balanceSheetTranslationPolicy: $state['balance_sheet_translation_policy'],
                    );
                    Notification::make()->success()->title('Company currency settings saved.')->send();
                    $this->mount();
                }),
        ];
    }

    private function company(): Company
    {
        return Company::query()->findOrFail(Auth::user()?->default_company_id);
    }

    private function currencySelect(string $name, string $label): Select
    {
        return Select::make($name)
            ->label($label)
            ->searchable()
            ->options(fn (): array => Currency::query()->active()->orderBy('display_order')->limit(50)->get()
                ->mapWithKeys(fn (Currency $currency): array => [$currency->id => $currency->display_name])->all())
            ->getSearchResultsUsing(fn (string $search): array => Currency::query()
                ->active()
                ->where(fn ($query) => $query->where('code', 'like', "%{$search}%")
                    ->orWhere('full_name', 'like', "%{$search}%"))
                ->orderBy('display_order')
                ->limit(50)
                ->get()
                ->mapWithKeys(fn (Currency $currency): array => [$currency->id => $currency->display_name])
                ->all())
            ->getOptionLabelUsing(fn ($value): ?string => Currency::query()->find($value)?->display_name)
            ->getOptionLabelsUsing(fn (array $values): array => Currency::query()->whereKey($values)->get()
                ->mapWithKeys(fn (Currency $currency): array => [$currency->id => $currency->display_name])->all());
    }

    private function accountSelect(string $name, string $label, int $companyId): Select
    {
        return Select::make($name)
            ->label($label)
            ->searchable()
            ->options(fn (): array => Account::query()->postable()->where('deprecated', false)
                ->whereHas('companies', fn ($query) => $query->where('companies.id', $companyId))
                ->orderBy('code')->limit(50)->get()
                ->mapWithKeys(fn (Account $account): array => [$account->id => "{$account->code} {$account->name}"])->all())
            ->getSearchResultsUsing(fn (string $search): array => Account::query()
                ->postable()
                ->where('deprecated', false)
                ->whereHas('companies', fn ($query) => $query->where('companies.id', $companyId))
                ->where(fn ($query) => $query->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                ->orderBy('code')
                ->limit(50)
                ->get()
                ->mapWithKeys(fn (Account $account): array => [$account->id => "{$account->code} {$account->name}"])
                ->all())
            ->getOptionLabelUsing(function ($value): ?string {
                $account = Account::query()->find($value);

                return $account ? "{$account->code} {$account->name}" : null;
            });
    }
}
