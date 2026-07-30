<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources\ImportProfileResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\ImportProfileResource;

class CreateImportProfile extends CreateRecord
{
    protected static string $resource = ImportProfileResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = auth()->user()?->default_company_id;
        $data['owner_id'] = auth()->id();
        $data['activated_at'] = ($data['is_active'] ?? false) ? now() : null;

        return $data;
    }
}
