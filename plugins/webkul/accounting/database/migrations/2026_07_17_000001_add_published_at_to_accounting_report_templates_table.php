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
        Schema::table('accounting_report_templates', function (Blueprint $table) {
            $table->timestamp('published_at')
                ->nullable()
                ->after('version')
                ->comment('When this version was published (null = never published)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounting_report_templates', function (Blueprint $table) {
            $table->dropColumn('published_at');
        });
    }
};
