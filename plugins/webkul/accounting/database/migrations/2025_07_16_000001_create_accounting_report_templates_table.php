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
        Schema::create('accounting_report_templates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->nullable()
                ->comment('Company (null = available to all companies)')
                ->constrained('companies')
                ->nullOnDelete();

            $table->foreignId('creator_id')
                ->nullable()
                ->comment('Creator')
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('parent_template_id')
                ->nullable()
                ->comment('Origin template this row is a version/duplicate of (self-reference)')
                ->constrained('accounting_report_templates')
                ->nullOnDelete();

            $table->integer('sort')
                ->default(0)
                ->comment('Navigation / ordering position');

            $table->string('name')
                ->comment('Report name exactly as it must appear (e.g. "BS Group")');

            $table->string('code')
                ->comment('Stable machine slug (e.g. "bs-group")');

            $table->string('layout_type')
                ->default('period_total')
                ->comment('period_total | monthly_matrix');

            $table->string('currency_mode')
                ->default('ledger_only')
                ->comment('ledger_only | usd_only | ledger_and_usd');

            $table->string('entity_mode')
                ->default('single_company')
                ->comment('single_company | multi_company_consolidated');

            $table->string('status')
                ->default('draft')
                ->comment('draft | published | archived');

            $table->unsignedInteger('version')
                ->default(1)
                ->comment('Layout version number');

            $table->text('description')
                ->nullable()
                ->comment('Optional internal description');

            $table->timestamps();

            $table->softDeletes();

            $table->unique(['company_id', 'code', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounting_report_templates');
    }
};
