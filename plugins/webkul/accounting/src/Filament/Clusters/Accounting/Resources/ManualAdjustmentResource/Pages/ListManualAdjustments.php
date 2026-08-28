<?php

namespace Webkul\Accounting\Filament\Clusters\Accounting\Resources\ManualAdjustmentResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\ManualAdjustmentResource;

class ListManualAdjustments extends ListRecords
{
    protected static string $resource = ManualAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
