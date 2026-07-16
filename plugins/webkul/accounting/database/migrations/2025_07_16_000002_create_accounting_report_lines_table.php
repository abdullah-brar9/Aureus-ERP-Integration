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
        Schema::create('accounting_report_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('report_template_id')
                ->comment('Owning report template')
                ->constrained('accounting_report_templates')
                ->cascadeOnDelete();

            $table->foreignId('parent_id')
                ->nullable()
                ->comment('Parent line for nesting (self-reference)')
                ->constrained('accounting_report_lines')
                ->nullOnDelete();

            $table->foreignId('creator_id')
                ->nullable()
                ->comment('Creator')
                ->constrained('users')
                ->nullOnDelete();

            $table->integer('sort')
                ->default(0)
                ->comment('Ordering position within the template');

            $table->string('line_type')
                ->default('detail')
                ->comment('section_header | detail | subtotal | spacer');

            $table->string('caption')
                ->nullable()
                ->comment('Exact text to render, verbatim (null for spacer)');

            $table->string('code')
                ->nullable()
                ->comment('Optional stable reference used by subtotal formulas');

            $table->smallInteger('sign')
                ->default(1)
                ->comment('+1 or -1: contribution direction within a subtotal');

            $table->boolean('is_visible')
                ->default(true)
                ->comment('Whether the line renders');

            $table->boolean('is_bold')
                ->default(false)
                ->comment('Render emphasised (subtotals/headers)');

            $table->unsignedTinyInteger('indent_level')
                ->default(0)
                ->comment('Visual indentation depth');

            $table->string('dimension_type')
                ->nullable()
                ->comment('Generic dimension filter type (e.g. analytic_account, partner); null = none');

            $table->unsignedBigInteger('dimension_id')
                ->nullable()
                ->comment('Generic dimension filter target id; null = none');

            $table->timestamps();

            $table->index(['report_template_id', 'sort']);

            $table->index(['dimension_type', 'dimension_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounting_report_lines');
    }
};
