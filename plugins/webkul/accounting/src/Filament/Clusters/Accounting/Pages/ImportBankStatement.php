<?php

namespace Webkul\Accounting\Filament\Clusters\Accounting\Pages;

use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Auth;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Accounting\Enums\BankImportStatus;
use Webkul\Accounting\Filament\Clusters\Accounting;
use Webkul\Accounting\Services\Bank\BankStatementImportService;
use Webkul\Accounting\Services\Bank\BankStatementParserRegistry;
use Webkul\Accounting\Services\Coa\CoaUploadPathResolver;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

class ImportBankStatement extends Page implements HasForms
{
    use HasPageShield, InteractsWithForms;

    protected string $view = 'accounting::filament.clusters.accounting.pages.import-bank-statement';

    protected static ?string $cluster = Accounting::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?int $navigationSort = 2;

    public ?array $data = [];

    protected static function getPagePermission(): ?string
    {
        return 'page_accounting_import_bank_statement';
    }

    public static function getNavigationLabel(): string
    {
        return 'Import Bank Statement';
    }

    public function getTitle(): string
    {
        return 'Import Bank Statement';
    }

    public function mount(): void
    {
        $company = Auth::user()?->defaultCompany;
        $this->form->fill([
            'company_id'  => $company?->id,
            'currency_id' => $company?->currency_id,
        ]);
    }

    protected function getFormSchema(): array
    {
        $companyId = Auth::user()?->default_company_id;

        return [
            Section::make('Statement file')->schema([
                FileUpload::make('file')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                    ->disk('local')
                    ->directory('bank-statement-imports')
                    ->storeFileNamesIn('file_original_name')
                    ->required(),
                Select::make('parser')
                    ->label('Bank format')
                    ->options(fn () => app(BankStatementParserRegistry::class)->options())
                    ->placeholder('Auto-detect')
                    ->searchable(),
                TextInput::make('sheet_name')
                    ->label('Worksheet name')
                    ->helperText('Optional. Use this when a workbook contains more than one bank statement sheet.'),
            ]),
            Section::make('Accounting target')->columns(2)->schema([
                Select::make('company_id')->options(Company::query()->whereKey($companyId)->pluck('name', 'id'))->required()->disabled()->dehydrated(),
                Select::make('currency_id')->options(Currency::query()->active()->pluck('name', 'id'))->required()->searchable(),
                Select::make('journal_id')
                    ->label('Bank journal')
                    ->options(Journal::query()->where('company_id', $companyId)->where('type', JournalType::BANK)->pluck('name', 'id'))
                    ->required()->searchable()->preload(),
                Select::make('bank_gl_account_id')
                    ->label('Bank GL account')
                    ->options(Account::query()->postable()->where('deprecated', false)
                        ->whereHas('companies', fn ($query) => $query->where('companies.id', $companyId))
                        ->orderBy('code')->get()->mapWithKeys(fn (Account $account) => [$account->id => "{$account->code} {$account->name}"]))
                    ->required()->searchable()->preload(),
            ]),
        ];
    }

    protected function getFormStatePath(): string
    {
        return 'data';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label('Validate and import')
                ->icon('heroicon-o-check')
                ->requiresConfirmation()
                ->action(function (): void {
                    try {
                        $state = $this->form->getState();
                        $path = app(CoaUploadPathResolver::class)->resolve($state['file'] ?? null, $state['file_original_name'] ?? null);
                        $statement = app(BankStatementImportService::class)->import(
                            path: $path,
                            company: Company::findOrFail($state['company_id']),
                            journal: Journal::findOrFail($state['journal_id']),
                            bankGlAccount: Account::findOrFail($state['bank_gl_account_id']),
                            currency: Currency::findOrFail($state['currency_id']),
                            parserKey: $state['parser'] ?: null,
                            sheetName: $state['sheet_name'] ?: null,
                            originalFilename: $state['file_original_name'] ?? null,
                        );

                        $failed = $statement->import_status === BankImportStatus::ReconciliationFailed->value;
                        Notification::make()
                            ->title($failed ? 'Imported for review — reconciliation failed' : 'Bank statement validated and imported')
                            ->body($failed ? collect($statement->validation_errors)->pluck('message')->implode(' ') : "{$statement->lines->count()} transactions imported as unposted mappings.")
                            ->{$failed ? 'warning' : 'success'}()
                            ->persistent()
                            ->send();
                    } catch (\Throwable $exception) {
                        Notification::make()->danger()->title('Bank statement import failed')->body($exception->getMessage())->persistent()->send();
                    }
                }),
        ];
    }
}
