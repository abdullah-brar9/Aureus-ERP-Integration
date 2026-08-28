<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources\BusinessRuleResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\BusinessRuleResource;

class ListBusinessRules extends ListRecords
{
    protected static string $resource = BusinessRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
