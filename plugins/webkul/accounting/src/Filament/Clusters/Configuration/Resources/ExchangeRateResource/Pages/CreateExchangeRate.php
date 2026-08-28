<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources\ExchangeRateResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Webkul\Accounting\Enums\ExchangeRateApprovalStatus;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\ExchangeRateResource;

class CreateExchangeRate extends CreateRecord
{
    protected static string $resource = ExchangeRateResource::class;

    public function mount(): void
    {
        parent::mount();

        $this->form->fill([
            'company_id'         => Auth::user()?->default_company_id,
            'source_currency_id' => request()->integer('source_currency_id') ?: null,
            'target_currency_id' => request()->integer('target_currency_id') ?: null,
            'effective_date'     => request()->string('effective_date')->toString() ?: null,
            'rate_type'          => request()->string('rate_type')->toString() ?: null,
            'source_reference'   => request()->string('source_reference')->toString() ?: null,
        ]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Auth::user()?->default_company_id;
        $data['approval_status'] = ExchangeRateApprovalStatus::Draft->value;

        return $data;
    }
}
