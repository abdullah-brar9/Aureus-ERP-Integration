<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('accounts_account_move_lines', 'fs_tag_id')) {
            Schema::table('accounts_account_move_lines', function (Blueprint $table): void {
                $table->foreignId('fs_tag_id')
                    ->nullable()
                    ->after('account_id')
                    ->constrained('accounting_fs_tags')
                    ->nullOnDelete();
                $table->index(['company_id', 'fs_tag_id'], 'account_move_lines_company_fs_tag_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('accounts_account_move_lines', 'fs_tag_id')) {
            Schema::table('accounts_account_move_lines', function (Blueprint $table): void {
                $table->dropIndex('account_move_lines_company_fs_tag_idx');
                $table->dropConstrainedForeignId('fs_tag_id');
            });
        }
    }
};
