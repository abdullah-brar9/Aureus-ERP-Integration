<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            ! Schema::hasTable('manufacturing_orders')
            || ! Schema::hasTable('inventories_procurement_groups')
            || ! Schema::hasColumn('manufacturing_orders', 'procurement_group_id')
        ) {
            return;
        }

        foreach (Schema::getForeignKeys('manufacturing_orders') as $foreignKey) {
            if ($foreignKey['columns'] === ['procurement_group_id']) {
                return;
            }
        }

        Schema::table('manufacturing_orders', function (Blueprint $table) {
            $table->foreign('procurement_group_id')
                ->references('id')
                ->on('inventories_procurement_groups')
                ->nullOnDelete();
        });
    }

    public function down(): void {}
};
