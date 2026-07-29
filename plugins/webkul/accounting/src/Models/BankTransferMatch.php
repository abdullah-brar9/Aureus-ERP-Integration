<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Webkul\Account\Models\BankStatementLine;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class BankTransferMatch extends Model
{
    protected $table = 'accounting_bank_transfer_matches';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount'      => 'decimal:4',
            'confidence'  => 'decimal:4',
            'reviewed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function outgoingLine(): BelongsTo
    {
        return $this->belongsTo(BankStatementLine::class, 'outgoing_statement_line_id');
    }

    public function incomingLine(): BelongsTo
    {
        return $this->belongsTo(BankStatementLine::class, 'incoming_statement_line_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    protected static function booted(): void
    {
        static::creating(function (self $match): void {
            $match->match_reference ??= 'TRF-'.Str::upper(Str::random(12));
        });
    }
}
