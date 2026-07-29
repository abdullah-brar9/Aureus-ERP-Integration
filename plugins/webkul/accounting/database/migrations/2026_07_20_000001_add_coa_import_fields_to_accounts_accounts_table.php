<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive, forward-only. Adds the fields the Chart-of-Accounts importer
     * needs on the existing accounts table without touching existing data:
     *  - is_group: classification nodes are non-postable group accounts;
     *  - source_classification_path: the verbatim Nature > Class1..7 path;
     *  - import_batch_id: which import produced this account (for history /
     *    idempotency / reversal). Not FK-constrained to avoid create-order
     *    coupling; carries an index for lookups.
     */
    public function up(): void
    {
        Schema::table('accounts_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('accounts_accounts', 'is_group')) {
                $table->boolean('is_group')->default(false)->after('deprecated')
                    ->comment('Non-postable classification/group node (journal lines may not use it)');
            }
            if (! Schema::hasColumn('accounts_accounts', 'source_classification_path')) {
                $table->text('source_classification_path')->nullable()->after('is_group')
                    ->comment('Verbatim imported classification path (Nature > Classification 1..7)');
            }
            if (! Schema::hasColumn('accounts_accounts', 'import_batch_id')) {
                $table->unsignedBigInteger('import_batch_id')->nullable()->after('source_classification_path')
                    ->comment('CoA import batch that created this account');
                $table->index('import_batch_id', 'accounts_accounts_import_batch_id_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounts_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('accounts_accounts', 'import_batch_id')) {
                $table->dropIndex('accounts_accounts_import_batch_id_index');
                $table->dropColumn('import_batch_id');
            }
            if (Schema::hasColumn('accounts_accounts', 'source_classification_path')) {
                $table->dropColumn('source_classification_path');
            }
            if (Schema::hasColumn('accounts_accounts', 'is_group')) {
                $table->dropColumn('is_group');
            }
        });
    }
};
