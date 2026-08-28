<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts_account_moves', function (Blueprint $table): void {
            if (! Schema::hasColumn('accounts_account_moves', 'billing_address')) {
                $table->text('billing_address')->nullable()->after('invoice_source_email');
            }
            if (! Schema::hasColumn('accounts_account_moves', 'booking_id')) {
                $table->string('booking_id')->nullable()->after('invoice_origin');
                $table->index(['company_id', 'booking_id'], 'account_moves_company_booking_idx');
            }
            if (! Schema::hasColumn('accounts_account_moves', 'consolidated_number')) {
                $table->string('consolidated_number')->nullable()->after('booking_id');
                $table->index(['company_id', 'consolidated_number'], 'account_moves_company_consolidated_idx');
            }
            if (! Schema::hasColumn('accounts_account_moves', 'drop_off')) {
                $table->string('drop_off')->nullable()->after('consolidated_number');
            }
        });

        Schema::table('accounts_account_move_lines', function (Blueprint $table): void {
            if (! Schema::hasColumn('accounts_account_move_lines', 'source_product_service')) {
                $table->string('source_product_service')->nullable()->after('product_id');
            }
            if (! Schema::hasColumn('accounts_account_move_lines', 'source_tax_percent')) {
                $table->decimal('source_tax_percent', 10, 4)->nullable()->after('price_total');
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounts_account_move_lines', function (Blueprint $table): void {
            $columns = array_values(array_filter(
                ['source_product_service', 'source_tax_percent'],
                fn (string $column): bool => Schema::hasColumn('accounts_account_move_lines', $column),
            ));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('accounts_account_moves', function (Blueprint $table): void {
            if (Schema::hasColumn('accounts_account_moves', 'booking_id')) {
                $table->dropIndex('account_moves_company_booking_idx');
            }
            if (Schema::hasColumn('accounts_account_moves', 'consolidated_number')) {
                $table->dropIndex('account_moves_company_consolidated_idx');
            }
            $columns = array_values(array_filter(
                ['billing_address', 'booking_id', 'consolidated_number', 'drop_off'],
                fn (string $column): bool => Schema::hasColumn('accounts_account_moves', $column),
            ));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
