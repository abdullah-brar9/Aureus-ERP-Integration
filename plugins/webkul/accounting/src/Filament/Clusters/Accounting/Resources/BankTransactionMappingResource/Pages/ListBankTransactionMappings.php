<?php

namespace Webkul\Accounting\Filament\Clusters\Accounting\Resources\BankTransactionMappingResource\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Webkul\Account\Models\BankStatement;
use Webkul\Accounting\Enums\BankReviewStatus;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\BankTransactionMappingResource;
use Webkul\Accounting\Models\BankTransactionMapping;
use Webkul\Accounting\Services\Bank\BankJournalService;
use Webkul\Accounting\Services\Bank\BankMappingService;
use Webkul\Accounting\Services\Bank\BankTransferMatchingService;

class ListBankTransactionMappings extends ListRecords
{
    protected static string $resource = BankTransactionMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('suggestMappings')
                ->label('Suggest mappings')
                ->icon('heroicon-o-sparkles')
                ->action(function (): void {
                    $updated = BankStatement::query()
                        ->where('company_id', Auth::user()->default_company_id)
                        ->get()
                        ->sum(fn (BankStatement $statement): int => app(BankMappingService::class)->suggestForStatement($statement));

                    Notification::make()->success()->title($updated.' mapping suggestion(s) applied.')->send();
                }),
            Action::make('detectTransfers')
                ->label('Detect transfers')
                ->icon('heroicon-o-arrows-right-left')
                ->action(function (): void {
                    $matches = app(BankTransferMatchingService::class)->detect(Auth::user()->default_company_id);
                    Notification::make()->success()->title(count($matches).' transfer pair(s) detected.')->send();
                }),
            Action::make('generateDrafts')
                ->label('Generate approved drafts')
                ->icon('heroicon-o-document-plus')
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
