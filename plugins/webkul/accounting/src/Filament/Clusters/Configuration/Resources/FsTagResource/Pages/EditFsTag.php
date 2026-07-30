<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources\FsTagResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\FsTagResource;

class EditFsTag extends EditRecord
{
    protected static string $resource = FsTagResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
