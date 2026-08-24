<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('accounting_import_profiles', 'failure_policy')) {
            Schema::table('accounting_import_profiles', function (Blueprint $table): void {
                $table->string('failure_policy', 30)->default('reject_file')->after('blank_row_rule');
            });
        }

        Schema::table('accounting_import_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('accounting_import_runs', 'duplicate_rows')) {
                $table->unsignedInteger('duplicate_rows')->default(0)->after('failed_rows');
            }
            if (! Schema::hasColumn('accounting_import_runs', 'duplicates_confirmed_at')) {
                $table->timestamp('duplicates_confirmed_at')->nullable()->after('confirmed_at');
            }
        });

        Schema::table('accounting_import_source_rows', function (Blueprint $table): void {
            if (! Schema::hasColumn('accounting_import_source_rows', 'fingerprint')) {
                $table->char('fingerprint', 64)->nullable()->after('status');
                $table->index(['company_id', 'fingerprint'], 'import_source_rows_company_fingerprint_idx');
            }
            if (! Schema::hasColumn('accounting_import_source_rows', 'duplicate_of_source_row_id')) {
                $table->foreignId('duplicate_of_source_row_id')
                    ->nullable()
                    ->after('fingerprint')
                    ->constrained('accounting_import_source_rows')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounting_import_source_rows', function (Blueprint $table): void {
            if (Schema::hasColumn('accounting_import_source_rows', 'duplicate_of_source_row_id')) {
                $table->dropConstrainedForeignId('duplicate_of_source_row_id');
            }
            if (Schema::hasColumn('accounting_import_source_rows', 'fingerprint')) {
                $table->dropIndex('import_source_rows_company_fingerprint_idx');
                $table->dropColumn('fingerprint');
            }
        });

        Schema::table('accounting_import_runs', function (Blueprint $table): void {
            $columns = array_values(array_filter(
                ['duplicate_rows', 'duplicates_confirmed_at'],
                fn (string $column): bool => Schema::hasColumn('accounting_import_runs', $column),
            ));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        if (Schema::hasColumn('accounting_import_profiles', 'failure_policy')) {
            Schema::table('accounting_import_profiles', function (Blueprint $table): void {
                $table->dropColumn('failure_policy');
            });
        }
    }
};
