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
        Schema::create('accounting_report_line_inputs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('report_line_id')
                ->comment('Manual-value report line this entry feeds')
                ->constrained('accounting_report_lines')
                ->cascadeOnDelete();

            $table->foreignId('company_id')
                ->nullable()
                ->comment('Entity the value belongs to (null = applies to any scope)')
                ->constrained('companies')
                ->nullOnDelete();

            $table->foreignId('creator_id')
                ->nullable()
                ->comment('Creator')
                ->constrained('users')
                ->nullOnDelete();

            $table->date('date')
                ->comment('Point-in-time the value belongs to; a period sums the entries inside its range');

            $table->decimal('value', 20, 6)
                ->default(0)
                ->comment('The manual value');

            $table->timestamps();

            $table->unique(['report_line_id', 'company_id', 'date'], 'acc_report_line_inputs_line_company_date_unique');

            $table->index(['report_line_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounting_report_line_inputs');
    }
};
