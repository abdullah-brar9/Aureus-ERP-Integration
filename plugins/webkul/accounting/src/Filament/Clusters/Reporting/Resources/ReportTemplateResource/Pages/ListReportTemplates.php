<?php

namespace Webkul\Accounting\Filament\Clusters\Reporting\Resources\ReportTemplateResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Webkul\Accounting\Filament\Clusters\Reporting\Resources\ReportTemplateResource;

class ListReportTemplates extends ListRecords
{
    protected static string $resource = ReportTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('accounting::filament/clusters/reporting/resources/report-template.pages.list.create')),
        ];
    }
}
