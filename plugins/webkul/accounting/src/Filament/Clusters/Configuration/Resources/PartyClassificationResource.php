<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Webkul\Accounting\Filament\Clusters\Configuration;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\PartyClassificationResource\Pages\CreatePartyClassification;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\PartyClassificationResource\Pages\EditPartyClassification;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\PartyClassificationResource\Pages\ListPartyClassifications;
use Webkul\Accounting\Models\PartyClassification;
use Webkul\Accounting\Support\AccountingPermissions;

class PartyClassificationResource extends Resource
{
    protected static ?string $model = PartyClassification::class;

    protected static ?string $cluster = Configuration::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = 12;

    public static function getNavigationLabel(): string
    {
        return 'Party Classifications';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Auth::user()?->default_company_id);
    }

    public static function form(Schema $schema): Schema
    {
        $companyId = (int) Auth::user()?->default_company_id;

        return $schema->components([
            Section::make('Classification')->columns(2)->schema([
                Select::make('classification_type')->options([
                    'classification' => 'Classification', 'sector' => 'Sector', 'category' => 'Category',
                    'party_type'     => 'Party type', 'cost_center' => 'Cost center', 'tax_profile' => 'Tax profile',
                ])->required()->searchable(),
                TextInput::make('code')->required()->maxLength(60),
                TextInput::make('name')->required()->maxLength(255),
                Select::make('parent_id')->label('Parent')->searchable()
                    ->options(fn (): array => PartyClassification::query()->where('company_id', $companyId)->where('is_active', true)
                        ->orderBy('name')->limit(50)->pluck('name', 'id')->all())
                    ->getSearchResultsUsing(fn (string $search): array => PartyClassification::query()->where('company_id', $companyId)
                        ->where('is_active', true)->where('name', 'like', "%{$search}%")->orderBy('name')->limit(50)->pluck('name', 'id')->all())
                    ->getOptionLabelUsing(fn ($value): ?string => PartyClassification::query()->where('company_id', $companyId)->find($value)?->name),
                Toggle::make('is_active')->default(true),
                Select::make('company_id')->options([$companyId => Auth::user()?->defaultCompany?->name])->default($companyId)->disabled()->dehydrated(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('classification_type')->label('Type')->badge()->sortable(),
            TextColumn::make('code')->searchable()->sortable(),
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('parent.name')->placeholder('Top level'),
            IconColumn::make('is_active')->boolean(),
        ])->recordActions([
            EditAction::make()->authorize(AccountingPermissions::ManagePartyClassifications),
            DeleteAction::make()->authorize(AccountingPermissions::ManagePartyClassifications),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPartyClassifications::route('/'), 'create' => CreatePartyClassification::route('/create'),
            'edit'  => EditPartyClassification::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->can(AccountingPermissions::ManagePartyClassifications) ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->can(AccountingPermissions::ManagePartyClassifications) ?? false;
    }
}
