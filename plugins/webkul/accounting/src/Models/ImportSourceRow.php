<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Support\Models\Company;

class ImportSourceRow extends Model
{
    protected $table = 'accounting_import_source_rows';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'raw_values'         => 'array',
            'transformed_values' => 'array',
            'messages'           => 'array',
            'processed_at'       => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class, 'run_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
