<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees_attendance_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees_employees')->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('attendance_date');
            $table->timestamp('scheduled_start')->nullable();
            $table->timestamp('scheduled_end')->nullable();
            $table->timestamp('check_in')->nullable();
            $table->timestamp('check_out')->nullable();
            $table->decimal('worked_hours', 10, 4)->default(0);
            $table->decimal('overtime_hours', 10, 4)->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_departure_minutes')->default(0);
            $table->string('status', 40)->default('present');
            $table->string('source', 40)->default('manual');
            $table->string('source_reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'employee_id', 'attendance_date'], 'attendance_employee_date_unique');
            $table->index(['company_id', 'attendance_date', 'status'], 'attendance_reporting_index');
        });

        Schema::create('employees_performance_cycles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 30)->default('draft');
            $table->json('competency_framework')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status', 'starts_on'], 'performance_cycles_lookup');
        });

        Schema::create('employees_performance_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('cycle_id')->constrained('employees_performance_cycles')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees_employees')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('employees_employees')->nullOnDelete();
            $table->decimal('self_rating', 8, 2)->nullable();
            $table->decimal('manager_rating', 8, 2)->nullable();
            $table->json('competency_ratings')->nullable();
            $table->text('self_comments')->nullable();
            $table->text('manager_comments')->nullable();
            $table->text('improvement_plan')->nullable();
            $table->text('promotion_recommendation')->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['cycle_id', 'employee_id'], 'performance_cycle_employee_unique');
            $table->index(['company_id', 'status', 'employee_id'], 'performance_reviews_lookup');
        });

        Schema::create('employees_performance_goals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('review_id')->constrained('employees_performance_reviews')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('weight', 8, 2)->default(0);
            $table->decimal('target_value', 20, 4)->nullable();
            $table->decimal('actual_value', 20, 4)->nullable();
            $table->decimal('rating', 8, 2)->nullable();
            $table->string('status', 30)->default('open');
            $table->date('due_date')->nullable();
            $table->timestamps();
        });

        Schema::create('employees_request_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('journal_id')->nullable()->constrained('accounts_journals')->nullOnDelete();
            $table->foreignId('debit_account_id')->nullable()->constrained('accounts_accounts')->nullOnDelete();
            $table->foreignId('credit_account_id')->nullable()->constrained('accounts_accounts')->nullOnDelete();
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code', 80);
            $table->string('name');
            $table->string('category', 60)->default('custom');
            $table->string('approval_request_type', 100);
            $table->boolean('is_financial')->default(false);
            $table->boolean('requires_amount')->default(false);
            $table->boolean('requires_document')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('configuration')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'employee_request_types_company_code_unique');
        });

        Schema::create('employees_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees_employees')->restrictOnDelete();
            $table->foreignId('request_type_id')->constrained('employees_request_types')->restrictOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->foreignId('approval_request_id')->nullable()->constrained('support_approval_requests')->nullOnDelete();
            $table->foreignId('accounting_move_id')->nullable()->constrained('accounts_account_moves')->nullOnDelete();
            $table->string('reference', 100)->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('amount', 20, 4)->nullable();
            $table->json('payload')->nullable();
            $table->json('attachments')->nullable();
            $table->string('status', 40)->default('draft');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('posted_to_accounting_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'reference'], 'employee_requests_company_reference_unique');
            $table->index(['company_id', 'status', 'request_type_id'], 'employee_requests_queue_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees_requests');
        Schema::dropIfExists('employees_request_types');
        Schema::dropIfExists('employees_performance_goals');
        Schema::dropIfExists('employees_performance_reviews');
        Schema::dropIfExists('employees_performance_cycles');
        Schema::dropIfExists('employees_attendance_records');
    }
};
