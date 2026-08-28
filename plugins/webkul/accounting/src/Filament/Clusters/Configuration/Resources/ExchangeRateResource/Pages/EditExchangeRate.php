<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources\ExchangeRateResource\Pages;

use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\ExchangeRateResource;

class EditExchangeRate extends EditRecord
{
    protected static string $resource = ExchangeRateResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['company_id'] = Auth::user()?->default_company_id;

        return $data;
    }
}
