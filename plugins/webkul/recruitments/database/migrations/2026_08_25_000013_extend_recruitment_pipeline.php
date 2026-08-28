<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitments_stages', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->after('creator_id')->constrained('companies')->nullOnDelete();
            $table->string('pipeline_code', 40)->nullable()->after('name');

            $table->index(['company_id', 'pipeline_code'], 'recruitment_stage_company_pipeline_index');
        });

        Schema::table('recruitments_candidates', function (Blueprint $table): void {
            $table->string('resume_path')->nullable()->after('linkedin_profile');
            $table->string('portfolio_url')->nullable()->after('resume_path');
            $table->string('source_reference')->nullable()->after('portfolio_url');
        });

        Schema::table('recruitments_applicants', function (Blueprint $table): void {
            $table->string('external_application_id')->nullable()->after('medium_id');
            $table->string('source_details')->nullable()->after('external_application_id');
            $table->decimal('screening_score', 8, 2)->nullable()->after('probability');
            $table->decimal('interview_score', 8, 2)->nullable()->after('screening_score');
            $table->decimal('assessment_score', 8, 2)->nullable()->after('interview_score');
            $table->string('offer_status', 30)->nullable()->after('assessment_score');
            $table->date('offer_date')->nullable()->after('offer_status');

            $table->unique(['company_id', 'external_application_id'], 'applicants_company_external_unique');
        });

        Schema::table('employees_job_positions', function (Blueprint $table): void {
            $table->string('posting_status', 30)->default('draft')->after('is_active');
            $table->timestamp('published_at')->nullable()->after('posting_status');
            $table->json('posting_channels')->nullable()->after('published_at');
        });
    }

    public function down(): void
    {
        Schema::table('employees_job_positions', function (Blueprint $table): void {
            $table->dropColumn(['posting_status', 'published_at', 'posting_channels']);
        });

        Schema::table('recruitments_applicants', function (Blueprint $table): void {
            $table->dropUnique('applicants_company_external_unique');
            $table->dropColumn([
                'external_application_id',
                'source_details',
                'screening_score',
                'interview_score',
                'assessment_score',
                'offer_status',
                'offer_date',
            ]);
        });

        Schema::table('recruitments_candidates', function (Blueprint $table): void {
            $table->dropColumn(['resume_path', 'portfolio_url', 'source_reference']);
        });

        Schema::table('recruitments_stages', function (Blueprint $table): void {
            $table->dropIndex('recruitment_stage_company_pipeline_index');
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn('pipeline_code');
        });
    }
};
