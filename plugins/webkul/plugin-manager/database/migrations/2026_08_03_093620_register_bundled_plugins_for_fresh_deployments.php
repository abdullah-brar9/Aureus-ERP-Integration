<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $plugins = [
            'accounting',
            'accounts',
            'barcode',
            'blogs',
            'contacts',
            'employees',
            'inventories',
            'invoices',
            'maintenance',
            'manufacturing',
            'payments',
            'products',
            'projects',
            'purchases',
            'recruitments',
            'sales',
            'time-off',
            'timesheets',
            'website',
        ];

        DB::table('plugins')->insertOrIgnore(array_map(
            fn (string $name, int $index): array => [
                'name'         => $name,
                'author'       => 'Webkul',
                'is_active'    => true,
                'is_installed' => true,
                'sort'         => $index + 1,
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            $plugins,
            array_keys($plugins),
        ));
    }

    public function down(): void {}
};
