<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Account\Models\Move;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

class FxRevaluation extends Model
{
    protected $table = 'accounting_fx_revaluations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_end'               => 'date',
            'reversal_date'            => 'date',
            'original_balance'         => 'decimal:4',
            'book_company_balance'     => 'decimal:4',
            'revalued_company_balance' => 'decimal:4',
            'difference'               => 'decimal:4',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function exchangeRate(): BelongsTo
    {
        return $this->belongsTo(ExchangeRate::class);
    }

    public function move(): BelongsTo
    {
        return $this->belongsTo(Move::class);
    }

    public function reversalMove(): BelongsTo
    {
        return $this->belongsTo(Move::class, 'reversal_move_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
