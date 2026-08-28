<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Webkul\Accounting\Filament\Clusters\Configuration;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\CurrencyResource\Pages\CreateCurrency;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\CurrencyResource\Pages\EditCurrency;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\CurrencyResource\Pages\ListCurrencies;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\CurrencyResource\Pages\ViewCurrency;
use Webkul\Accounting\Models\Currency;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Support\Filament\Resources\CurrencyResource as BaseCurrencyResource;

class CurrencyResource extends BaseCurrencyResource
{
    protected static ?string $model = Currency::class;

    protected static bool $shouldRegisterNavigation = true;

    protected static ?int $navigationSort = 5;

    protected static ?string $cluster = Configuration::class;

    public static function getModelLabel(): string
    {
        return __('accounting::filament/clusters/configurations/resources/currency.model-label');
    }

    public static function getNavigationLabel(): string
    {
        return __('accounting::filament/clusters/configurations/resources/currency.navigation.title');
    }

    public static function getNavigationGroup(): string
    {
        return __('accounting::filament/clusters/configurations/resources/currency.navigation.group');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('ISO 4217 currency')->columns(2)->schema([
                TextInput::make('code')->label('ISO code')->disabled()->dehydrated(),
                TextInput::make('iso_numeric')->label('Numeric code')->disabled()->dehydrated(),
                TextInput::make('name')->label('Legacy code')->disabled()->dehydrated(),
                TextInput::make('full_name')->label('Name')->required()->maxLength(255),
                TextInput::make('symbol')->maxLength(10),
                TextInput::make('decimal_places')->label('Minor-unit precision')->integer()->minValue(0)->maxValue(6)->required(),
                TextInput::make('rounding')->disabled()->dehydrated(),
                TextInput::make('display_order')->integer()->minValue(0),
                Toggle::make('active'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('ISO code')->searchable()->sortable(),
                TextColumn::make('full_name')->label('Name')->searchable()->sortable(),
                TextColumn::make('symbol')->searchable(),
                TextColumn::make('iso_numeric')->label('Numeric')->sortable(),
                TextColumn::make('decimal_places')->label('Precision')->sortable(),
                ToggleColumn::make('active')->sortable(),
            ])
            ->filters([TernaryFilter::make('active')])
            ->defaultSort('display_order');
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->can(AccountingPermissions::ManageCurrencies) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return Auth::user()?->can(AccountingPermissions::ManageCurrencies) ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListCurrencies::route('/'),
            'create' => CreateCurrency::route('/create'),
            'edit'   => EditCurrency::route('/{record}/edit'),
            'view'   => ViewCurrency::route('/{record}'),
        ];
    }
}
