<?php

namespace Webkul\Accounting\Filament\Clusters\Accounting\Resources;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Webkul\Account\Models\Account;
use Webkul\Accounting\Enums\BankPostingStatus;
use Webkul\Accounting\Enums\BankReviewStatus;
use Webkul\Accounting\Enums\CashFlowCategory;
use Webkul\Accounting\Filament\Clusters\Accounting;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\BankTransactionMappingResource\Pages\ListBankTransactionMappings;
use Webkul\Accounting\Models\BankTransactionMapping;
use Webkul\Accounting\Services\Bank\BankJournalService;
use Webkul\Accounting\Services\Bank\BankMappingService;
use Webkul\Accounting\Services\Bank\BankTransferMatchingService;

class BankTransactionMappingResource extends Resource
{
    protected static ?string $model = BankTransactionMapping::class;

    protected static ?string $cluster = Accounting::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?int $navigationSort = 4;

    public static function getNavigationLabel(): string
    {
        return 'Transaction Mapping';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Auth::user()?->default_company_id)
            ->with(['statementLine.statement', 'bankGlAccount', 'offsetAccount', 'mappingRule', 'reviewer', 'transferMatch']);
    }

    public static function form(Schema $schema): Schema
    {
        $companyId = Auth::user()?->default_company_id;
        $accountOptions = fn () => Account::query()->postable()->where('deprecated', false)
            ->whereHas('companies', fn ($query) => $query->where('companies.id', $companyId))
            ->orderBy('code')->get()->mapWithKeys(fn (Account $account) => [$account->id => "{$account->code} {$account->name}"]);

        return $schema->components([
            Section::make('Mapping review')->columns(2)->schema([
                TextInput::make('transaction_type'),
                TextInput::make('counterparty'),
                TextInput::make('supporting_document')->label('Supporting document/reference'),
                Select::make('bank_gl_account_id')->label('Bank GL')->options($accountOptions)->searchable()->preload()->required(),
                Select::make('offset_account_id')->label('Offset GL')->options($accountOptions)->searchable()->preload()->required(),
                TextInput::make('tax_treatment'),
                Select::make('cash_flow_category')->options(CashFlowCategory::options())->required(),
                TextInput::make('confidence')->numeric()->minValue(0)->maxValue(1)->disabled(),
                Select::make('review_status')->options(BankReviewStatus::options())->disabled(),
                TextInput::make('map_reference')->disabled(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('map_reference')->label('Map ID')->searchable()->sortable(),
                TextColumn::make('statementLine.statement.bank_name')->label('Bank'),
                TextColumn::make('statementLine.transaction_date')->label('Date')->date()->sortable(),
                TextColumn::make('statementLine.description')->label('Description')->limit(45)->searchable()->tooltip(fn (BankTransactionMapping $record) => $record->statementLine?->description),
                TextColumn::make('statementLine.reference')->label('Reference')->searchable(),
                TextColumn::make('statementLine.debit')->label('Debit')->numeric(2)->alignRight(),
                TextColumn::make('statementLine.credit')->label('Credit')->numeric(2)->alignRight(),
                TextColumn::make('transaction_type')->label('Transaction type')->placeholder('—'),
                TextColumn::make('counterparty')->placeholder('—')->toggleable(),
                TextColumn::make('supporting_document')->label('Supporting document')->placeholder('—')->toggleable(),
                TextColumn::make('bankGlAccount.code')->label('Bank GL'),
                TextColumn::make('offsetAccount.code')->label('Offset GL')->placeholder('—'),
                TextColumn::make('tax_treatment')->placeholder('—')->toggleable(),
                TextColumn::make('company.name')->label('Entity')->toggleable(),
                TextColumn::make('review_status')->badge(),
                TextColumn::make('transferMatch.match_reference')->label('Transfer Match ID')->placeholder('—'),
                TextColumn::make('posting_status')->badge(),
                TextColumn::make('cash_flow_category')->label('Cash Flow Category')->placeholder('—'),
                TextColumn::make('confidence')->numeric(2)->toggleable(),
                TextColumn::make('mappingRule.name')->label('Mapping rule used')->placeholder('—')->toggleable(),
                TextColumn::make('reviewer.name')->placeholder('—')->toggleable(),
                TextColumn::make('reviewed_at')->dateTime()->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('review_status')->options(BankReviewStatus::options()),
                SelectFilter::make('posting_status')->options([
                    'not_posted' => 'Not Posted', 'draft' => 'Draft', 'review' => 'Review',
                    'posted'     => 'Posted', 'matched_do_not_post' => 'Matched — Do Not Post', 'do_not_post' => 'Do Not Post',
                ]),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (BankTransactionMapping $record) => $record->move_id === null
                    && ! in_array($record->posting_status, [BankPostingStatus::Posted, BankPostingStatus::MatchedDoNotPost], true)),
                Action::make('approve')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (BankTransactionMapping $record) => $record->transfer_match_id === null && $record->review_status !== BankReviewStatus::Posted)
                    ->action(function (BankTransactionMapping $record): void {
                        app(BankMappingService::class)->approve($record, Auth::user());
                        Notification::make()->success()->title('Mapping approved; learned rule updated.')->send();
                    }),
                Action::make('approveTransfer')
                    ->label('Approve transfer')
                    ->icon('heroicon-o-arrows-right-left')
                    ->visible(fn (BankTransactionMapping $record) => $record->transferMatch?->status === 'suggested'
                        && $record->transferMatch?->outgoing_statement_line_id === $record->statement_line_id)
                    ->action(function (BankTransactionMapping $record): void {
                        app(BankTransferMatchingService::class)->approve($record->transferMatch, Auth::user());
                        Notification::make()->success()->title('Transfer pair approved.')->send();
                    }),
                Action::make('draft')
                    ->label('Generate draft')
                    ->icon('heroicon-o-document-plus')
                    ->visible(fn (BankTransactionMapping $record) => $record->move_id === null
                        && ($record->review_status === BankReviewStatus::Approved || $record->transferMatch?->status === 'approved'))
                    ->action(function (BankTransactionMapping $record): void {
                        app(BankJournalService::class)->createDraft($record);
                        Notification::make()->success()->title('Balanced draft journal created.')->send();
                    }),
                Action::make('post')
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (BankTransactionMapping $record) => $record->posting_status === BankPostingStatus::Draft)
                    ->action(function (BankTransactionMapping $record): void {
                        app(BankJournalService::class)->post($record, Auth::user());
                        Notification::make()->success()->title('Journal posted to the ledger.')->send();
                    }),
                Action::make('doNotPost')
                    ->label('Do not post')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (BankTransactionMapping $record) => $record->move_id === null)
                    ->action(fn (BankTransactionMapping $record) => $record->update([
                        'review_status'  => BankReviewStatus::DoNotPost,
                        'posting_status' => BankPostingStatus::DoNotPost,
                        'reviewer_id'    => Auth::id(),
                        'reviewed_at'    => now(),
                    ])),
                Action::make('reject')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (BankTransactionMapping $record) => $record->move_id === null
                        && ! in_array($record->review_status, [BankReviewStatus::Rejected, BankReviewStatus::Posted], true))
                    ->action(fn (BankTransactionMapping $record) => $record->update([
                        'review_status'  => BankReviewStatus::Rejected,
                        'posting_status' => BankPostingStatus::DoNotPost,
                        'reviewer_id'    => Auth::id(),
                        'reviewed_at'    => now(),
                    ])),
            ])
            ->defaultSort('statement_line_id');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListBankTransactionMappings::route('/')];
    }
}
