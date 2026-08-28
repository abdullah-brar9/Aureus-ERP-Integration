<?php

namespace Webkul\Accounting\Filament\Clusters\Accounting\Resources\BankTransactionMappingResource\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Webkul\Accounting\Enums\BankReviewStatus;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\BankTransactionMappingResource;
use Webkul\Accounting\Models\BankTransactionMapping;
use Webkul\Accounting\Services\Bank\BankJournalService;
use Webkul\Accounting\Services\Bank\BankMatchingPriorityService;
use Webkul\Accounting\Services\Bank\BankTransferMatchingService;
use Webkul\Accounting\Support\AccountingPermissions;

class ListBankTransactionMappings extends ListRecords
{
    protected static string $resource = BankTransactionMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('suggestMappings')
                ->label('Run priority matching')
                ->icon('heroicon-o-sparkles')
                ->authorize(AccountingPermissions::ReviewBankTransactions)
                ->action(function (): void {
                    $result = app(BankMatchingPriorityService::class)->run(Auth::user()->default_company_id);
                    Notification::make()->success()->title(array_sum($result).' match(es) suggested')
                        ->body("Obligations {$result['obligations']}; payments {$result['payments']}; transfers {$result['transfers']}; rules {$result['rules']}.")->send();
                }),
            Action::make('detectTransfers')
                ->label('Detect transfers')
                ->icon('heroicon-o-arrows-right-left')
                ->authorize(AccountingPermissions::ReviewBankTransactions)
                ->action(function (): void {
                    $matches = app(BankTransferMatchingService::class)->detect(Auth::user()->default_company_id);
                    Notification::make()->success()->title(count($matches).' transfer pair(s) detected.')->send();
                }),
            Action::make('generateDrafts')
                ->label('Generate approved drafts')
                ->icon('heroicon-o-document-plus')
                ->authorize(AccountingPermissions::GenerateJournal)
                ->action(function (): void {
                    $mappings = BankTransactionMapping::query()
                        ->where('company_id', Auth::user()->default_company_id)
                        ->whereNull('move_id')
                        ->where('review_status', BankReviewStatus::Approved)
                        ->get();

                    foreach ($mappings as $mapping) {
                        app(BankJournalService::class)->createDraft($mapping);
                    }

                    Notification::make()->success()->title($mappings->count().' draft journal(s) generated.')->send();
                }),
        ];
    }
}
