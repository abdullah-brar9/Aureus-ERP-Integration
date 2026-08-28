<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->after('creator_id')->constrained('companies')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->after('company_id')->constrained('employees_departments')->nullOnDelete();
            $table->foreignId('manager_employee_id')->nullable()->after('department_id')->constrained('employees_employees')->nullOnDelete();
            $table->text('description')->nullable()->after('name');
            $table->boolean('is_active')->default(true)->after('description');

            $table->index(['company_id', 'department_id', 'is_active'], 'teams_hr_scope_index');
        });

        Schema::table('employees_employees', function (Blueprint $table): void {
            $table->foreignId('team_id')->nullable()->after('department_id')->constrained('teams')->nullOnDelete();
            $table->foreignId('salary_currency_id')->nullable()->after('bank_account_id')->constrained('currencies')->nullOnDelete();
            $table->string('employee_number', 80)->nullable()->after('name');
            $table->date('joining_date')->nullable()->after('birthday');
            $table->date('leaving_date')->nullable()->after('joining_date');
            $table->string('employment_status', 40)->default('active')->after('employee_type');
            $table->string('salary_grade', 80)->nullable()->after('employment_status');
            $table->decimal('base_salary', 20, 4)->nullable()->after('salary_grade');
            $table->string('emergency_relationship', 100)->nullable()->after('emergency_contact');
            $table->json('document_metadata')->nullable()->after('additional_note');

            $table->unique(['company_id', 'employee_number'], 'employees_company_number_unique');
            $table->index(['company_id', 'department_id', 'team_id', 'is_active'], 'employees_hr_scope_index');
        });

        Schema::create('employees_employee_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees_employees')->cascadeOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 40);
            $table->date('effective_date');
            $table->text('reason')->nullable();
            $table->json('previous_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'effective_date'], 'employee_status_history_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees_employee_status_histories');

        Schema::table('employees_employees', function (Blueprint $table): void {
            $table->dropUnique('employees_company_number_unique');
            $table->dropIndex('employees_hr_scope_index');
            $table->dropConstrainedForeignId('team_id');
            $table->dropConstrainedForeignId('salary_currency_id');
            $table->dropColumn([
                'employee_number',
                'joining_date',
                'leaving_date',
                'employment_status',
                'salary_grade',
                'base_salary',
                'emergency_relationship',
                'document_metadata',
            ]);
        });

        Schema::table('teams', function (Blueprint $table): void {
            $table->dropIndex('teams_hr_scope_index');
            $table->dropConstrainedForeignId('manager_employee_id');
            $table->dropConstrainedForeignId('department_id');
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['description', 'is_active']);
        });
    }
};
