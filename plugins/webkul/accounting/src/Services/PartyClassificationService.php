<?php

namespace Webkul\Accounting\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Webkul\Accounting\Models\PartyClassification;
use Webkul\Accounting\Models\PartyClassificationAssignment;
use Webkul\Support\Models\Company;

final class PartyClassificationService
{
    public function assign(Company $company, Model $party, string $type, ?string $value): void
    {
        $name = Str::of((string) $value)->squish()->value();
        if ($name === '') {
            return;
        }

        $normalized = Str::lower($name);
        $classification = PartyClassification::query()->firstOrCreate([
            'company_id'          => $company->id,
            'classification_type' => $type,
            'normalized_name'     => $normalized,
        ], [
            'code'      => $this->code($type, $name),
            'name'      => $name,
            'is_active' => true,
        ]);

        PartyClassificationAssignment::query()->firstOrCreate([
            'company_id'       => $company->id,
            'classification_id'=> $classification->id,
            'classifiable_type'=> $party->getMorphClass(),
            'classifiable_id'  => $party->getKey(),
        ]);
    }

    private function code(string $type, string $name): string
    {
        return Str::upper(Str::limit(Str::slug($type, ''), 8, '').'-'.Str::limit(Str::slug($name, ''), 40, ''));
    }
}
