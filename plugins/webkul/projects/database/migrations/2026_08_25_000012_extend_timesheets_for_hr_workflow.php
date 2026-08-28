<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytic_records', function (Blueprint $table): void {
            $table->foreignId('approved_by')->nullable()->after('creator_id')->constrained('users')->nullOnDelete();
            $table->foreignId('approval_request_id')->nullable()->after('approved_by')->constrained('support_approval_requests')->nullOnDelete();
            $table->boolean('is_billable')->default(false)->after('unit_amount');
            $table->decimal('overtime_hours', 10, 4)->default(0)->after('is_billable');
            $table->string('workflow_status', 30)->default('draft')->after('overtime_hours');
            $table->text('rejection_reason')->nullable()->after('workflow_status');
            $table->timestamp('submitted_at')->nullable()->after('rejection_reason');
            $table->timestamp('approved_at')->nullable()->after('submitted_at');

            $table->index(['company_id', 'user_id', 'workflow_status', 'date'], 'timesheet_hr_workflow_index');
        });
    }

    public function down(): void
    {
        Schema::table('analytic_records', function (Blueprint $table): void {
            $table->dropIndex('timesheet_hr_workflow_index');
            $table->dropConstrainedForeignId('approval_request_id');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn([
                'is_billable',
                'overtime_hours',
                'workflow_status',
                'rejection_reason',
                'submitted_at',
                'approved_at',
            ]);
        });
    }
};
