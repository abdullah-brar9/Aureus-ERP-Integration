<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Pages;

use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Webkul\Account\Models\BankStatementLine;
use Webkul\Accounting\Enums\ConversionStatus;
use Webkul\Accounting\Enums\ExchangeRateType;
use Webkul\Accounting\Filament\Clusters\Configuration;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\ExchangeRateResource;
use Webkul\Accounting\Support\AccountingPermissions;

class MissingExchangeRates extends Page implements HasTable
{
    use HasPageShield, InteractsWithTable;

    protected string $view = 'accounting::filament.clusters.configuration.pages.missing-exchange-rates';

    protected static ?string $cluster = Configuration::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?int $navigationSort = 7;

    protected static function getPagePermission(): ?string
    {
        return AccountingPermissions::MissingRatesPage;
    }

    public static function getNavigationLabel(): string
    {
        return 'Missing Rates';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => BankStatementLine::query()
                ->where('company_id', Auth::user()?->default_company_id)
                ->whereIn('conversion_status', [ConversionStatus::MissingRate->value, ConversionStatus::Pending->value])
                ->with(['statement', 'originalCurrency', 'companyCurrency']))
            ->columns([
                TextColumn::make('company.name')->label('Company'),
                TextColumn::make('statement.name')->label('Source statement')->searchable(),
                TextColumn::make('transaction_date')->date()->sortable(),
                TextColumn::make('originalCurrency.code')->label('From'),
                TextColumn::make('companyCurrency.code')->label('To'),
                TextColumn::make('rate_type')->label('Required type')->formatStateUsing(fn ($state) => $state ?: ExchangeRateType::Transaction->value),
                TextColumn::make('description')->limit(50),
                TextColumn::make('original_signed_amount')->label('Original amount')->numeric(4)->alignRight(),
                TextColumn::make('conversion_status')->badge(),
            ])
            ->recordActions([
                Action::make('createRate')
                    ->label('Create rate')
                    ->icon('heroicon-o-plus')
                    ->authorize(AccountingPermissions::ManageExchangeRates)
                    ->url(fn (BankStatementLine $record): string => ExchangeRateResource::getUrl('create', [
                        'source_currency_id' => $record->original_currency_id,
                        'target_currency_id' => $record->company_currency_id,
                        'effective_date'     => $record->transaction_date?->toDateString(),
                        'rate_type'          => ExchangeRateType::Transaction->value,
                        'source_reference'   => $record->statement?->reference,
                    ])),
            ])
            ->defaultSort('transaction_date');
    }
}
