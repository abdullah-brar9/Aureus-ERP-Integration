<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources\PartyClassificationResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\PartyClassificationResource;

class CreatePartyClassification extends CreateRecord
{
    protected static string $resource = PartyClassificationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = auth()->user()?->default_company_id;

        return $data;
    }
}
