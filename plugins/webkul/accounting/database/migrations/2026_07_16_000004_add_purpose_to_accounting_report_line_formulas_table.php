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
        Schema::table('accounting_report_line_formulas', function (Blueprint $table) {
            $table->string('purpose')
                ->default('value')
                ->after('report_line_id')
                ->comment('value = normal evaluation | consolidation = overrides the default sum in consolidated columns');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounting_report_line_formulas', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });
    }
};
