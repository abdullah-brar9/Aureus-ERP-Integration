<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources\FsTagResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\FsTagResource;
use Webkul\Accounting\Services\FsTagService;
use Webkul\Support\Models\Company;

class CreateFsTag extends CreateRecord
{
    protected static string $resource = FsTagResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $company = Company::query()->findOrFail(auth()->user()?->default_company_id);

        return app(FsTagService::class)->create($company, $data);
    }
}
