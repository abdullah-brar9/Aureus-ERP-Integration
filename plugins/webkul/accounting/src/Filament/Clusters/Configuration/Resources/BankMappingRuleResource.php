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
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\BankMappingRuleResource\Pages\CreateBankMappingRule;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\BankMappingRuleResource\Pages\EditBankMappingRule;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\BankMappingRuleResource\Pages\ListBankMappingRules;
use Webkul\Accounting\Models\BankMappingRule;

class BankMappingRuleResource extends Resource
{
    protected static ?string $model = BankMappingRule::class;

    protected static ?string $cluster = Configuration::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bolt';

    protected static ?int $navigationSort = 7;

    public static function getNavigationLabel(): string
    {
        return 'Bank Mapping Rules';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Auth::user()?->default_company_id);
    }

    public static function form(Schema $schema): Schema
    {
        $companyId = Auth::user()?->default_company_id;
        $accounts = fn () => Account::query()->postable()->where('deprecated', false)
            ->whereHas('companies', fn ($query) => $query->where('companies.id', $companyId))
            ->orderBy('code')->get()->mapWithKeys(fn (Account $account) => [$account->id => "{$account->code} {$account->name}"]);

        return $schema->components([
            Section::make('Rule')->columns(2)->schema([
                TextInput::make('name')->required(),
                TextInput::make('bank_account_number'),
                Select::make('bank_gl_account_id')->label('Bank GL')->options($accounts)->searchable()->preload(),
                Select::make('offset_account_id')->label('Offset GL')->options($accounts)->searchable()->preload()->required(),
                TextInput::make('description_pattern')->helperText('Case-insensitive text or a /regular expression/.'),
                TextInput::make('reference_pattern'),
                Select::make('direction')->options(['debit' => 'Debit/payment', 'credit' => 'Credit/receipt']),
                TextInput::make('counterparty_pattern'),
                TextInput::make('minimum_amount')->numeric()->minValue(0),
                TextInput::make('maximum_amount')->numeric()->minValue(0),
                TextInput::make('transaction_type'),
                TextInput::make('tax_treatment'),
                Select::make('cash_flow_category')->options(CashFlowCategory::options()),
                TextInput::make('confidence')->numeric()->minValue(0)->maxValue(1)->default(0.8)->required(),
                TextInput::make('priority')->numeric()->default(100)->required(),
                Toggle::make('is_active')->default(true),
                Select::make('company_id')->options([$companyId => Auth::user()?->defaultCompany?->name])->default($companyId)->disabled()->dehydrated(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('bank_account_number')->label('Bank account')->placeholder('Any'),
            TextColumn::make('description_pattern')->label('Description pattern')->placeholder('Any'),
            TextColumn::make('direction')->placeholder('Any'),
            TextColumn::make('offsetAccount.code')->label('Offset GL'),
            TextColumn::make('cash_flow_category')->label('Cash flow'),
            TextColumn::make('confidence')->numeric(2),
            TextColumn::make('usage_count')->label('Uses')->sortable(),
            IconColumn::make('is_active')->boolean(),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListBankMappingRules::route('/'),
            'create' => CreateBankMappingRule::route('/create'),
            'edit'   => EditBankMappingRule::route('/{record}/edit'),
        ];
    }
}
