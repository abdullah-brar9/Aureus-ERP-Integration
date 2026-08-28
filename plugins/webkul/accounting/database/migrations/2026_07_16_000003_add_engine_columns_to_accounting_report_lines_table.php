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
        Schema::table('accounting_report_lines', function (Blueprint $table) {
            $table->string('value_source')
                ->nullable()
                ->after('sign')
                ->comment('ledger | formula | manual | external (null = derived from line_type)');

            $table->string('value_basis')
                ->nullable()
                ->after('value_source')
                ->comment('movement | opening_balance | closing_balance (null = template default)');

            $table->string('external_provider')
                ->nullable()
                ->after('value_basis')
                ->comment('Registry key of the external value provider (value_source = external)');

            $table->boolean('is_check')
                ->default(false)
                ->after('is_bold')
                ->comment('Control-total row expected to evaluate to zero (e.g. balance sheet check)');

            $table->foreignId('company_id')
                ->nullable()
                ->after('creator_id')
                ->comment('Entity override: compute this line against this company regardless of column scope')
                ->constrained('companies')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounting_report_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['value_source', 'value_basis', 'external_provider', 'is_check']);
        });
    }
};
