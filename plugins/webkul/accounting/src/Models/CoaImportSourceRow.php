<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Account\Models\Account;
use Webkul\Support\Models\Company;

class CoaImportSourceRow extends Model
{
    protected $table = 'accounting_coa_import_source_rows';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'classification_values'  => 'array',
            'raw_row'                => 'array',
            'raw_row_by_header'      => 'array',
            'opening_debit'          => 'decimal:4',
            'opening_credit'         => 'decimal:4',
            'movement_debit'         => 'decimal:4',
            'movement_credit'        => 'decimal:4',
            'adjustment_debit'       => 'decimal:4',
            'adjustment_credit'      => 'decimal:4',
            'closing_debit'          => 'decimal:4',
            'closing_credit'         => 'decimal:4',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CoaImportBatch::class, 'batch_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function canonicalAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'canonical_account_id');
    }
}
