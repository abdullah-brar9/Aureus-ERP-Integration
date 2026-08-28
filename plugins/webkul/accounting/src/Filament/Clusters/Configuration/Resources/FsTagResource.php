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
use Webkul\Account\Models\Account;
use Webkul\Accounting\Enums\CashFlowCategory;
use Webkul\Accounting\Filament\Clusters\Configuration;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\FsTagResource\Pages\CreateFsTag;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\FsTagResource\Pages\EditFsTag;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\FsTagResource\Pages\ListFsTags;
use Webkul\Accounting\Models\FsTag;
use Webkul\Accounting\Support\AccountingPermissions;

class FsTagResource extends Resource
{
    protected static ?string $model = FsTag::class;

    protected static ?string $cluster = Configuration::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static ?int $navigationSort = 8;

    public static function getNavigationLabel(): string
    {
        return 'FS Tags';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Auth::user()?->default_company_id);
    }

    public static function form(Schema $schema): Schema
    {
        $companyId = (int) Auth::user()?->default_company_id;
        $accountQuery = fn (): Builder => Account::query()
            ->postable()
            ->where('deprecated', false)
            ->whereHas('companies', fn ($query) => $query->where('companies.id', $companyId));
        $format = fn (Account $account): string => trim("{$account->code} {$account->name}");

        return $schema->components([
            Section::make('Financial statement tag')->columns(2)->schema([
                TextInput::make('code')->label('Tag code')->helperText('Leave blank when creating to generate the next FS code.')->maxLength(60),
                TextInput::make('name')->required()->maxLength(255),
                Select::make('account_id')
                    ->label('Mapped GL account')
                    ->helperText('Optional until the tag is ready for posting. Only active postable accounts in this company are available.')
                    ->searchable()
                    ->options(fn (): array => $accountQuery()->orderBy('code')->limit(50)->get()->mapWithKeys(fn (Account $account): array => [$account->id => $format($account)])->all())
                    ->getSearchResultsUsing(fn (string $search): array => $accountQuery()
                        ->where(fn ($query) => $query->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                        ->orderBy('code')->limit(50)->get()->mapWithKeys(fn (Account $account): array => [$account->id => $format($account)])->all())
                    ->getOptionLabelUsing(fn ($value): ?string => ($account = $accountQuery()->find($value)) ? $format($account) : null),
                Select::make('cash_flow_category')->options(CashFlowCategory::options())->searchable(),
                TextInput::make('tax_treatment')->maxLength(255),
                Toggle::make('is_active')->default(true)->required(),
                Select::make('company_id')->options([$companyId => Auth::user()?->defaultCompany?->name])->default($companyId)->disabled()->dehydrated(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->searchable()->sortable(),
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('account.code')->label('GL code')->placeholder('Not mapped'),
            TextColumn::make('account.name')->label('GL account')->placeholder('Not mapped'),
            TextColumn::make('cash_flow_category')->label('Cash flow')->placeholder('Not set'),
            TextColumn::make('tax_treatment')->placeholder('Not set')->toggleable(),
            IconColumn::make('is_active')->boolean(),
        ])->recordActions([
            EditAction::make()->authorize(AccountingPermissions::ManageFsTags),
            DeleteAction::make()->authorize(AccountingPermissions::ManageFsTags),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListFsTags::route('/'),
            'create' => CreateFsTag::route('/create'),
            'edit'   => EditFsTag::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->can(AccountingPermissions::ManageFsTags) ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->can(AccountingPermissions::ManageFsTags) ?? false;
    }
}
