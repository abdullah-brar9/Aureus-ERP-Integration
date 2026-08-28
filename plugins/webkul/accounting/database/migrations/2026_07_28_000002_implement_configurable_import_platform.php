<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_import_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('supersedes_profile_id')->nullable()->constrained('accounting_import_profiles')->nullOnDelete();
            $table->string('name');
            $table->string('entity_type', 40);
            $table->string('file_type', 10);
            $table->string('sheet_name')->nullable();
            $table->unsignedInteger('header_row')->default(1);
            $table->unsignedInteger('data_start_row')->default(2);
            $table->unsignedInteger('skip_rows')->default(0);
            $table->string('blank_row_rule', 30)->default('skip');
            $table->json('stop_rule')->nullable();
            $table->string('delimiter', 5)->default(',');
            $table->string('encoding', 30)->default('UTF-8');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(false);
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'name', 'version'], 'import_profiles_company_name_version_unique');
            $table->index(['company_id', 'entity_type', 'is_active'], 'import_profiles_company_entity_active_idx');
        });

        Schema::create('accounting_import_profile_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('accounting_import_profiles')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('source_header')->nullable();
            $table->unsignedInteger('source_position')->nullable();
            $table->json('source_aliases')->nullable();
            $table->string('target_field');
            $table->json('transformations')->nullable();
            $table->json('validation_rules')->nullable();
            $table->boolean('is_required')->default(false);
            $table->timestamps();

            $table->unique(['profile_id', 'target_field'], 'import_mappings_profile_target_unique');
            $table->index(['profile_id', 'position'], 'import_mappings_profile_position_idx');
        });

        Schema::create('accounting_business_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('profile_id')->nullable()->constrained('accounting_import_profiles')->cascadeOnDelete();
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('entity_type', 40);
            $table->unsignedInteger('priority')->default(100);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->json('conditions');
            $table->json('actions');
            $table->boolean('stop_processing')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'entity_type', 'is_active', 'priority'], 'business_rules_execution_idx');
        });

        Schema::create('accounting_import_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained('accounting_import_profiles')->restrictOnDelete();
            $table->foreignId('imported_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference')->unique();
            $table->string('status', 30)->default('previewed');
            $table->string('original_filename');
            $table->char('file_hash', 64);
            $table->string('source_sheet')->nullable();
            $table->unsignedInteger('profile_version');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('passed_rows')->default(0);
            $table->unsignedInteger('warning_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->json('summary')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status', 'created_at'], 'import_runs_company_status_created_idx');
            $table->index(['company_id', 'file_hash'], 'import_runs_company_hash_idx');
        });

        Schema::create('accounting_import_source_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('accounting_import_runs')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedInteger('source_row_number');
            $table->string('status', 20);
            $table->json('raw_values');
            $table->json('transformed_values')->nullable();
            $table->json('messages')->nullable();
            $table->string('canonical_type')->nullable();
            $table->unsignedBigInteger('canonical_id')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'source_row_number'], 'import_source_rows_run_row_unique');
            $table->index(['company_id', 'canonical_type', 'canonical_id'], 'import_source_rows_lineage_idx');
            $table->index(['run_id', 'status'], 'import_source_rows_run_status_idx');
        });

        Schema::create('accounting_fs_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts_accounts')->restrictOnDelete();
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code', 60);
            $table->string('name');
            $table->string('normalized_name');
            $table->string('cash_flow_category')->nullable();
            $table->string('tax_treatment')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'fs_tags_company_code_unique');
            $table->unique(['company_id', 'normalized_name'], 'fs_tags_company_name_unique');
            $table->index(['company_id', 'is_active'], 'fs_tags_company_active_idx');
        });

        Schema::create('accounting_party_classifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('accounting_party_classifications')->nullOnDelete();
            $table->string('classification_type', 40);
            $table->string('code', 60);
            $table->string('name');
            $table->string('normalized_name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'classification_type', 'code'], 'party_classifications_company_type_code_unique');
            $table->unique(['company_id', 'classification_type', 'normalized_name'], 'party_classifications_company_type_name_unique');
        });

        Schema::create('accounting_party_classification_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('classification_id');
            $table->string('classifiable_type');
            $table->unsignedBigInteger('classifiable_id');
            $table->timestamps();

            $table->unique(['classification_id', 'classifiable_type', 'classifiable_id'], 'party_classification_assignment_unique');
            $table->index(['company_id', 'classifiable_type', 'classifiable_id'], 'party_classification_assignment_lookup_idx');
            $table->foreign('company_id', 'party_class_assign_company_fk')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('classification_id', 'party_class_assign_classification_fk')->references('id')->on('accounting_party_classifications')->cascadeOnDelete();
        });

        Schema::create('accounting_configuration_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');
            $table->string('event', 40);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id', 'created_at'], 'configuration_audits_subject_idx');
            $table->index(['company_id', 'created_at'], 'configuration_audits_company_created_idx');
        });

        Schema::table('accounting_bank_transaction_mappings', function (Blueprint $table) {
            $table->foreignId('fs_tag_id')->nullable()->after('offset_account_id')->constrained('accounting_fs_tags')->nullOnDelete();
            $table->string('match_type', 30)->nullable()->after('mapping_rule_id');
            $table->string('matched_reference')->nullable()->after('match_type');
            $table->index(['company_id', 'fs_tag_id'], 'bank_mappings_company_fs_tag_idx');
        });
    }

    public function down(): void
    {
        Schema::table('accounting_bank_transaction_mappings', function (Blueprint $table) {
            $table->dropIndex('bank_mappings_company_fs_tag_idx');
            $table->dropForeign(['fs_tag_id']);
            $table->dropColumn(['fs_tag_id', 'match_type', 'matched_reference']);
        });

        Schema::dropIfExists('accounting_configuration_audits');
        Schema::dropIfExists('accounting_party_classification_assignments');
        Schema::dropIfExists('accounting_party_classifications');
        Schema::dropIfExists('accounting_fs_tags');
        Schema::dropIfExists('accounting_import_source_rows');
        Schema::dropIfExists('accounting_import_runs');
        Schema::dropIfExists('accounting_business_rules');
        Schema::dropIfExists('accounting_import_profile_mappings');
        Schema::dropIfExists('accounting_import_profiles');
    }
};
