<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources\BusinessRuleResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\BusinessRuleResource;

class EditBusinessRule extends EditRecord
{
    protected static string $resource = BusinessRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['company_id'] = auth()->user()?->default_company_id;

        return $data;
    }
}
