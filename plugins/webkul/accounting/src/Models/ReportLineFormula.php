<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Accounting\Database\Factories\ReportLineFormulaFactory;
use Webkul\Accounting\Enums\FormulaOperandType;
use Webkul\Accounting\Enums\FormulaOperator;

class ReportLineFormula extends Model
{
    use HasFactory;

    protected $table = 'accounting_report_line_formulas';

    protected $fillable = [
        'report_line_id',
        'operator',
        'operand_type',
        'operand_line_id',
        'operand_constant',
        'sign',
        'sort',
    ];

    protected $casts = [
        'operator'         => FormulaOperator::class,
        'operand_type'     => FormulaOperandType::class,
        'operand_constant' => 'decimal:6',
        'sign'             => 'integer',
        'sort'             => 'integer',
    ];

    public function line(): BelongsTo
    {
        return $this->belongsTo(ReportLine::class, 'report_line_id');
    }

    public function operandLine(): BelongsTo
    {
        return $this->belongsTo(ReportLine::class, 'operand_line_id');
    }

    protected static function newFactory()
    {
        return ReportLineFormulaFactory::new();
    }
}
