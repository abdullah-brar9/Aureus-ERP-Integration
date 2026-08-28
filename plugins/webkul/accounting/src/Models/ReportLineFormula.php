<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Accounting\Database\Factories\ReportLineFormulaFactory;
use Webkul\Accounting\Enums\FormulaOperandType;
use Webkul\Accounting\Enums\FormulaOperator;
use Webkul\Accounting\Enums\FormulaPurpose;
use Webkul\Accounting\Models\Concerns\InteractsWithReportTemplate;

class ReportLineFormula extends Model
{
    use HasFactory, InteractsWithReportTemplate;

    protected $table = 'accounting_report_line_formulas';

    protected $fillable = [
        'report_line_id',
        'purpose',
        'operator',
        'operand_type',
        'operand_line_id',
        'operand_constant',
        'sign',
        'sort',
    ];

    protected $casts = [
        'purpose'          => FormulaPurpose::class,
        'operator'         => FormulaOperator::class,
        'operand_type'     => FormulaOperandType::class,
        'operand_constant' => 'decimal:6',
        'sign'             => 'integer',
        'sort'             => 'integer',
    ];

    /**
     * Touch the owning line (which in turn touches its template) so cached
     * report results are invalidated when a formula changes.
     *
     * @var array<int, string>
     */
    protected $touches = ['line'];

    public function line(): BelongsTo
    {
        return $this->belongsTo(ReportLine::class, 'report_line_id');
    }

    public function operandLine(): BelongsTo
    {
        return $this->belongsTo(ReportLine::class, 'operand_line_id');
    }

    public function owningTemplate(): ?ReportTemplate
    {
        return $this->line?->template;
    }

    public function getModelTitle(): string
    {
        return 'Formula operand on "'.($this->line?->caption ?? "line #{$this->report_line_id}").'"';
    }

    /**
     * @return array<int|string, string>
     */
    public function getLogAttributeLabels(): array
    {
        return [
            'purpose'             => 'Purpose',
            'operator'            => 'Operator',
            'operand_type'        => 'Operand Type',
            'operandLine.caption' => 'Operand Line',
            'operand_constant'    => 'Constant',
            'sign'                => 'Sign',
        ];
    }

    protected static function newFactory()
    {
        return ReportLineFormulaFactory::new();
    }
}
