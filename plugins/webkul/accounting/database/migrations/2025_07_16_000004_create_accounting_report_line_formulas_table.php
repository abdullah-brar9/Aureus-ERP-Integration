<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('accounting_report_line_formulas', function (Blueprint $table) {
            $table->id();

            $table->foreignId('report_line_id')
                ->comment('Subtotal / computed line that owns this formula component')
                ->constrained('accounting_report_lines')
                ->cascadeOnDelete();

            $table->string('operator')
                ->default('+')
                ->comment('+ | - | * | / : how this operand combines with the running result');

            $table->string('operand_type')
                ->default('line')
                ->comment('line | constant');

            $table->foreignId('operand_line_id')
                ->nullable()
                ->comment('Line whose value feeds in (null when operand_type = constant)')
                ->constrained('accounting_report_lines')
                ->cascadeOnDelete();

            $table->decimal('operand_constant', 20, 6)
                ->nullable()
                ->comment('Literal value (used when operand_type = constant, e.g. 100 for %)');

            $table->smallInteger('sign')
                ->default(1)
                ->comment('+1 or -1: overall sign applied to this operand');

            $table->integer('sort')
                ->default(0)
                ->comment('Evaluation order of operands');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounting_report_line_formulas');
    }
};
