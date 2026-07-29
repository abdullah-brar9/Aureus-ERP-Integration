<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

/**
 * One Chart-of-Accounts import run: which company/currency it targeted, the
 * mode (structure only vs with migration journals), row counts, the acknowledged
 * options/warnings and a downloadable error report. Imported accounts and any
 * migration journals reference this batch for history, idempotency and reversal.
 */
class CoaImportBatch extends Model
{
    use HasFactory;

    protected $table = 'accounting_coa_import_batches';

    protected $fillable = [
        'company_id',
        'currency_id',
        'creator_id',
        'filename',
        'source_sheet',
        'header_row_number',
        'file_hash',
        'original_headers',
        'metadata_rows',
        'mode',
        'status',
        'opening_date',
        'movement_date',
        'adjustment_date',
        'groups_created',
        'accounts_created',
        'accounts_updated',
        'journals_created',
        'options',
        'error_report',
    ];

    protected $casts = [
        'opening_date'     => 'date',
        'movement_date'    => 'date',
        'adjustment_date'  => 'date',
        'groups_created'   => 'integer',
        'accounts_created' => 'integer',
        'accounts_updated' => 'integer',
        'journals_created' => 'integer',
        'options'          => 'array',
        'original_headers' => 'array',
        'metadata_rows'    => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceRows(): HasMany
    {
        return $this->hasMany(CoaImportSourceRow::class, 'batch_id')->orderBy('row_order');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($batch) {
            $batch->creator_id ??= Auth::id();
        });
    }
}
