<?php

namespace Webkul\Support\Filament\Resources\ApprovalWorkflowResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Webkul\Support\Filament\Resources\ApprovalWorkflowResource;

class EditApprovalWorkflow extends EditRecord
{
    protected static string $resource = ApprovalWorkflowResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
