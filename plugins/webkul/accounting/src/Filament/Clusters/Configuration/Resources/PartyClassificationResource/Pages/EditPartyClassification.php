<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources\PartyClassificationResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\PartyClassificationResource;

class EditPartyClassification extends EditRecord
{
    protected static string $resource = PartyClassificationResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
