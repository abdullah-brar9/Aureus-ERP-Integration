<?php

namespace Webkul\Account\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Webkul\Accounting\Models\BankTransactionMapping;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

class BankStatementLine extends Model
{
    use HasFactory;

    protected $table = 'accounts_bank_statement_lines';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'transaction_date'       => 'date',
            'value_date'             => 'date',
            'debit'                  => 'decimal:4',
            'credit'                 => 'decimal:4',
            'running_balance'        => 'decimal:4',
            'amount'                 => 'decimal:4',
            'amount_currency'        => 'decimal:4',
            'amount_residual'        => 'decimal:4',
            'raw_row'                => 'array',
            'transaction_details'    => 'array',
            'is_reconciled'          => 'boolean',
        ];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'statement_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function mapping(): HasOne
    {
        return $this->hasOne(BankTransactionMapping::class, 'statement_line_id');
    }
}
