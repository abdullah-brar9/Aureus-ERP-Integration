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
        Schema::create('accounting_report_columns', function (Blueprint $table) {
            $table->id();

            $table->foreignId('report_template_id')
                ->comment('Owning report template')
                ->constrained('accounting_report_templates')
                ->cascadeOnDelete();

            $table->foreignId('creator_id')
                ->nullable()
                ->comment('Creator')
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('company_id')
                ->nullable()
                ->comment('Entity scope override for this column (null = report run scope)')
                ->constrained('companies')
                ->nullOnDelete();

            $table->integer('sort')
                ->default(0)
                ->comment('Ordering position within the template');

            $table->string('label')
                ->nullable()
                ->comment('Column heading exactly as it must render (null = derived from the period)');

            $table->string('column_type')
                ->default('month')
                ->comment('month | range | full_year | spacer');

            $table->unsignedTinyInteger('start_month')
                ->nullable()
                ->comment('1-12: the month (column_type month) or range start (column_type range)');

            $table->unsignedTinyInteger('end_month')
                ->nullable()
                ->comment('1-12: range end (column_type range only)');

            $table->smallInteger('year_offset')
                ->default(0)
                ->comment('Offset from the report run year (e.g. -1 = prior year comparative)');

            $table->boolean('is_consolidated')
                ->default(false)
                ->comment('Whether this column consolidates across the full run company scope');

            $table->timestamps();

            $table->index(['report_template_id', 'sort']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounting_report_columns');
    }
};
