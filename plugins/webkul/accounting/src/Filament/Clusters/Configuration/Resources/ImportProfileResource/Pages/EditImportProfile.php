<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources\ImportProfileResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\ImportProfileResource;

class EditImportProfile extends EditRecord
{
    protected static string $resource = ImportProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->visible(fn (): bool => ! $this->record->runs()->exists())];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['company_id'] = auth()->user()?->default_company_id;
        $data['owner_id'] = auth()->id();

        return $data;
    }
}
