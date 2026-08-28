<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_coa_import_batches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->comment('Company the accounts were imported into')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->foreignId('currency_id')
                ->nullable()
                ->comment('Currency chosen for the import')
                ->constrained('currencies')
                ->nullOnDelete();

            $table->foreignId('creator_id')
                ->nullable()
                ->comment('User who ran the import')
                ->constrained('users')
                ->nullOnDelete();

            $table->string('filename')->nullable();

            $table->string('mode')->default('structure_only')
                ->comment('structure_only | with_journals');

            $table->string('status')->default('pending')
                ->comment('pending | imported | failed');

            $table->date('opening_date')->nullable();
            $table->date('movement_date')->nullable();
            $table->date('adjustment_date')->nullable();

            $table->unsignedInteger('groups_created')->default(0);
            $table->unsignedInteger('accounts_created')->default(0);
            $table->unsignedInteger('accounts_updated')->default(0);
            $table->unsignedInteger('journals_created')->default(0);

            $table->json('options')->nullable()->comment('Column map, type overrides, flags acknowledged');
            $table->longText('error_report')->nullable()->comment('CSV error/warning report for download');

            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_coa_import_batches');
    }
};
