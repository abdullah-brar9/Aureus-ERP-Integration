<?php

namespace Webkul\Accounting\Filament\Clusters\Accounting\Resources;

use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Webkul\Account\Models\BankStatement;
use Webkul\Accounting\Filament\Clusters\Accounting;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\BankStatementResource\Pages\ListBankStatements;
use Webkul\Accounting\Support\AccountingPermissions;

class BankStatementResource extends Resource
{
    protected static ?string $model = BankStatement::class;

    protected static ?string $cluster = Accounting::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return 'Bank Statements';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Auth::user()?->default_company_id)
            ->withCount('lines');
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('bank_name')->label('Bank')->searchable()->sortable(),
            TextColumn::make('bank_account_number')->label('Account / IBAN')->searchable(),
            TextColumn::make('statement_start_date')->label('From')->date()->sortable(),
            TextColumn::make('statement_end_date')->label('To')->date()->sortable(),
            TextColumn::make('opening_balance')->money(fn (BankStatement $record) => $record->currency?->name ?? 'PKR')->alignRight(),
            TextColumn::make('total_debits')->alignRight(),
            TextColumn::make('total_credits')->alignRight(),
            TextColumn::make('closing_balance')->alignRight(),
            TextColumn::make('currency.code')->label('Original currency'),
            TextColumn::make('company_closing_balance')->label('Company closing')->numeric(4)->alignRight()->placeholder('Missing rate'),
            TextColumn::make('companyCurrency.code')->label('Company currency'),
            TextColumn::make('conversion_status')->badge(),
            TextColumn::make('lines_count')->label('Transactions')->alignRight(),
            TextColumn::make('import_status')->badge(),
            IconColumn::make('is_completed')->label('Posted/closed')->boolean(),
            TextColumn::make('original_filename')->label('File')->toggleable(),
            TextColumn::make('file_hash')->label('SHA-256')->limit(12)->tooltip(fn (BankStatement $record) => $record->file_hash)->toggleable(isToggledHiddenByDefault: true),
        ])->defaultSort('statement_end_date', 'desc');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->can(AccountingPermissions::BankStatements) ?? false;
    }

    public static function getPages(): array
    {
        return ['index' => ListBankStatements::route('/')];
    }
}
