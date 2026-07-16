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
        Schema::create('accounting_report_line_accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('report_line_id')
                ->comment('Report line being bound')
                ->constrained('accounting_report_lines')
                ->cascadeOnDelete();

            $table->foreignId('account_id')
                ->comment('Chart-of-account account (existing accounts_accounts)')
                ->constrained('accounts_accounts')
                ->cascadeOnDelete();

            $table->smallInteger('sign')
                ->default(1)
                ->comment('+1 or -1: how this account contributes to the line value');

            $table->timestamps();

            $table->unique(['report_line_id', 'account_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounting_report_line_accounts');
    }
};
