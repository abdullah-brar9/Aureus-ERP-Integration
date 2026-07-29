<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive, forward-only. Tags migration journals so the Trial Balance can
     * separate movement from adjustment postings by KIND (not by date), and so
     * a re-import can detect journals it already created (idempotency /
     * reversal). Normal (non-migration) moves leave both columns null and are
     * treated as ordinary movement postings.
     */
    public function up(): void
    {
        Schema::table('accounts_account_moves', function (Blueprint $table) {
            if (! Schema::hasColumn('accounts_account_moves', 'coa_migration_kind')) {
                $table->string('coa_migration_kind')->nullable()->index()
                    ->comment('opening | movement | adjustment (migration journals only)');
            }
            if (! Schema::hasColumn('accounts_account_moves', 'coa_import_batch_id')) {
                $table->unsignedBigInteger('coa_import_batch_id')->nullable()->index()
                    ->comment('CoA import batch that created this migration journal');
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounts_account_moves', function (Blueprint $table) {
            if (Schema::hasColumn('accounts_account_moves', 'coa_migration_kind')) {
                $table->dropColumn('coa_migration_kind');
            }
            if (Schema::hasColumn('accounts_account_moves', 'coa_import_batch_id')) {
                $table->dropColumn('coa_import_batch_id');
            }
        });
    }
};
