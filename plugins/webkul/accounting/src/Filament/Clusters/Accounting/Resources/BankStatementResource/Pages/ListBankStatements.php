<?php

namespace Webkul\Accounting\Filament\Clusters\Accounting\Resources\BankStatementResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Webkul\Accounting\Filament\Clusters\Accounting\Pages\ImportBankStatement;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\BankStatementResource;

class ListBankStatements extends ListRecords
{
    protected static string $resource = BankStatementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')->label('Import statement')->icon('heroicon-o-arrow-up-tray')->url(ImportBankStatement::getUrl()),
        ];
    }
}
