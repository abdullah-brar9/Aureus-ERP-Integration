<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Account\Models\Account;
use Webkul\Accounting\Database\Factories\ReportLineAccountFactory;

class ReportLineAccount extends Model
{
    use HasFactory;

    protected $table = 'accounting_report_line_accounts';

    protected $fillable = [
        'report_line_id',
        'account_id',
        'sign',
    ];

    protected $casts = [
        'sign' => 'integer',
    ];

    /**
     * Touch the owning line (which in turn touches its template) so cached
     * report results are invalidated when an account binding changes.
     *
     * @var array<int, string>
     */
    protected $touches = ['line'];

    public function line(): BelongsTo
    {
        return $this->belongsTo(ReportLine::class, 'report_line_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    protected static function newFactory()
    {
        return ReportLineAccountFactory::new();
    }
}
