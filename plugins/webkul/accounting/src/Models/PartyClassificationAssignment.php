<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Support\Models\Company;

class PartyClassificationAssignment extends Model
{
    protected $table = 'accounting_party_classification_assignments';

    protected $guarded = [];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function classification(): BelongsTo
    {
        return $this->belongsTo(PartyClassification::class, 'classification_id');
    }
}
