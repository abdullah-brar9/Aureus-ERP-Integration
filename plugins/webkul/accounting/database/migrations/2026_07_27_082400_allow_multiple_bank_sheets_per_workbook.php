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
        Schema::table('accounts_bank_statements', function (Blueprint $table) {
            $table->dropUnique('bank_statements_company_file_hash_unique');
            $table->unique(
                ['company_id', 'file_hash', 'parser'],
                'bank_statements_company_file_parser_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts_bank_statements', function (Blueprint $table) {
            $table->dropUnique('bank_statements_company_file_parser_unique');
            $table->unique(
                ['company_id', 'file_hash'],
                'bank_statements_company_file_hash_unique',
            );
        });
    }
};
