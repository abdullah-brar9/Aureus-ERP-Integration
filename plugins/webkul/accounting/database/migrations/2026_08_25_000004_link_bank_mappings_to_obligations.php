<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('accounting_bank_transaction_mappings', 'matched_move_id')) {
            Schema::table('accounting_bank_transaction_mappings', function (Blueprint $table): void {
                $table->foreignId('matched_move_id')
                    ->nullable()
                    ->after('move_id')
                    ->constrained('accounts_account_moves')
                    ->nullOnDelete();
                $table->index(['company_id', 'matched_move_id'], 'bank_mappings_company_matched_move_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('accounting_bank_transaction_mappings', 'matched_move_id')) {
            Schema::table('accounting_bank_transaction_mappings', function (Blueprint $table): void {
                $table->dropIndex('bank_mappings_company_matched_move_idx');
                $table->dropConstrainedForeignId('matched_move_id');
            });
        }
    }
};
