<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
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
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\BusinessRuleResource\Pages\CreateBusinessRule;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\BusinessRuleResource\Pages\EditBusinessRule;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\BusinessRuleResource\Pages\ListBusinessRules;
use Webkul\Accounting\Models\BusinessRule;
use Webkul\Accounting\Models\ImportProfile;
use Webkul\Accounting\Services\Import\ImportEntityRegistry;
use Webkul\Accounting\Support\AccountingPermissions;

class BusinessRuleResource extends Resource
{
    protected static ?string $model = BusinessRule::class;

    protected static ?string $cluster = Configuration::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-funnel';

    protected static ?int $navigationSort = 11;

    public static function getNavigationLabel(): string
    {
        return 'Import Rules';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Auth::user()?->default_company_id);
    }

    public static function form(Schema $schema): Schema
    {
        $companyId = (int) Auth::user()?->default_company_id;
        $fields = app(ImportEntityRegistry::class)->allFields();

        return $schema->components([
            Section::make('Rule')->columns(3)->schema([
                TextInput::make('name')->required()->maxLength(255),
                Select::make('entity_type')->options(app(ImportEntityRegistry::class)->entityOptions())->required()->searchable(),
                Select::make('profile_id')->label('Limit to profile')->searchable()
                    ->options(fn (): array => ImportProfile::forCompany($companyId)->orderBy('name')->limit(50)->get()->mapWithKeys(fn (ImportProfile $profile): array => [$profile->id => "{$profile->name} v{$profile->version}"])->all())
                    ->getSearchResultsUsing(fn (string $search): array => ImportProfile::forCompany($companyId)->where('name', 'like', "%{$search}%")->orderBy('name')->limit(50)->get()->mapWithKeys(fn (ImportProfile $profile): array => [$profile->id => "{$profile->name} v{$profile->version}"])->all())
                    ->getOptionLabelUsing(fn ($value): ?string => ($profile = ImportProfile::forCompany($companyId)->find($value)) ? "{$profile->name} v{$profile->version}" : null),
                TextInput::make('priority')->numeric()->minValue(1)->default(100)->required(),
                DatePicker::make('effective_from'),
                DatePicker::make('effective_until')->afterOrEqual('effective_from'),
                Toggle::make('stop_processing')->label('Stop after this rule'),
                Toggle::make('is_active')->default(true),
                Select::make('company_id')->options([$companyId => Auth::user()?->defaultCompany?->name])->default($companyId)->disabled()->dehydrated(),
            ]),
            Section::make('When all conditions match')->schema([
                Repeater::make('conditions')->columns(3)->minItems(1)->schema([
                    Select::make('field')->options($fields)->searchable()->required(),
                    Select::make('operator')->options([
                        'equals'      => 'Equals', 'not_equals' => 'Does not equal', 'contains' => 'Contains',
                        'starts_with' => 'Starts with', 'ends_with' => 'Ends with', 'greater_than' => 'Greater than',
                        'less_than'   => 'Less than', 'blank' => 'Is blank', 'not_blank' => 'Is not blank', 'in' => 'Is one of',
                    ])->required(),
                    TextInput::make('value'),
                ]),
            ]),
            Section::make('Apply these actions')->schema([
                Repeater::make('actions')->columns(4)->minItems(1)->schema([
                    Select::make('type')->options(['set' => 'Set value', 'copy' => 'Copy another field', 'default' => 'Set only when blank', 'map' => 'Map value'])->required(),
                    Select::make('field')->options($fields)->searchable()->required(),
                    Select::make('source_field')->options($fields)->searchable(),
                    TextInput::make('value'),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('entity_type')->badge()->sortable(),
            TextColumn::make('profile.name')->label('Profile')->placeholder('All profiles'),
            TextColumn::make('priority')->sortable(),
            TextColumn::make('effective_from')->date()->placeholder('Immediately'),
            TextColumn::make('effective_until')->date()->placeholder('No expiry'),
            IconColumn::make('stop_processing')->boolean(),
            IconColumn::make('is_active')->boolean(),
        ])->recordActions([
            EditAction::make()->authorize(AccountingPermissions::ManageBusinessRules),
            DeleteAction::make()->authorize(AccountingPermissions::ManageBusinessRules),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListBusinessRules::route('/'),
            'create' => CreateBusinessRule::route('/create'),
            'edit'   => EditBusinessRule::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->can(AccountingPermissions::ManageBusinessRules) ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->can(AccountingPermissions::ManageBusinessRules) ?? false;
    }
}
