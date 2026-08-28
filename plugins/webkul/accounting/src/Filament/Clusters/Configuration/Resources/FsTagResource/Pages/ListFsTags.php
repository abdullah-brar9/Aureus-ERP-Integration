<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources\FsTagResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\FsTagResource;

class ListFsTags extends ListRecords
{
    protected static string $resource = FsTagResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
