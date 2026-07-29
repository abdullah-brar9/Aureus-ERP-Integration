<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources;

use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Webkul\Account\Filament\Resources\AccountResource as BaseAccountResource;
use Webkul\Accounting\Filament\Clusters\Configuration;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\AccountResource\Pages\ManageAccounts;
use Webkul\Accounting\Models\Account;

class AccountResource extends BaseAccountResource
{
    protected static ?string $model = Account::class;

    protected static bool $shouldRegisterNavigation = true;

    protected static bool $isGloballySearchable = true;

    protected static ?int $navigationSort = 3;

    protected static ?string $cluster = Configuration::class;

    public static function getModelLabel(): string
    {
        return __('accounting::filament/clusters/configurations/resources/account.model-label');
    }

    public static function getNavigationLabel(): string
    {
        return __('accounting::filament/clusters/configurations/resources/account.navigation.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('accounting::filament/clusters/configurations/resources/account.navigation.group');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('companies', fn (Builder $query) => $query->where('companies.id', Auth::user()?->default_company_id))
            ->with(['latestSourceRow', 'parent', 'companies', 'currency']);
    }

    public static function table(Table $table): Table
    {
        return parent::table($table)
            ->columns([
                TextColumn::make('code')->label('Code')->searchable()->sortable()->placeholder('Group'),
                TextColumn::make('name')->label('Title')->searchable()->sortable(),
                TextColumn::make('latestSourceRow.nature')->label('Nature')->placeholder('—')->toggleable(),
                TextColumn::make('latestSourceRow.classification_1')->label('Classification 1')->placeholder('—')->toggleable(),
                TextColumn::make('latestSourceRow.classification_2')->label('Classification 2')->placeholder('—')->toggleable(),
                TextColumn::make('latestSourceRow.classification_3')->label('Classification 3')->placeholder('—')->toggleable(),
                TextColumn::make('latestSourceRow.classification_4')->label('Classification 4')->placeholder('—')->toggleable(),
                TextColumn::make('latestSourceRow.classification_5')->label('Classification 5')->placeholder('—')->toggleable(),
                TextColumn::make('latestSourceRow.classification_6')->label('Classification 6')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('latestSourceRow.classification_7')->label('Classification 7')->placeholder('—')->toggleable(),
                TextColumn::make('source_classification_path')->label('Canonical path')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('account_type')->label('Account type')->badge()->sortable(),
                TextColumn::make('parent.name')->label('Parent')->placeholder('—')->toggleable(),
                TextColumn::make('is_group')
                    ->label('Posting')
                    ->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Group' : 'Postable')
                    ->color(fn (bool $state) => $state ? 'gray' : 'success'),
                TextColumn::make('companies.name')->label('Company')->listWithLineBreaks()->toggleable(),
                TextColumn::make('currency.name')->label('Currency')->placeholder('Company default')->toggleable(),
                IconColumn::make('deprecated')->label('Inactive')->boolean()->sortable(),
                TextColumn::make('import_batch_id')->label('Source batch')->prefix('#')->placeholder('Manual')->toggleable(),
            ])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAccounts::route('/'),
        ];
    }
}
