<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources\PartyClassificationResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\PartyClassificationResource;

class ListPartyClassifications extends ListRecords
{
    protected static string $resource = PartyClassificationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
