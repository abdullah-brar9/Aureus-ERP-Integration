<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources\BusinessRuleResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\BusinessRuleResource;

class CreateBusinessRule extends CreateRecord
{
    protected static string $resource = BusinessRuleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = auth()->user()?->default_company_id;
        $data['creator_id'] = auth()->id();

        return $data;
    }
}
