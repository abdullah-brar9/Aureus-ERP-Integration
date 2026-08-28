<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_approval_workflows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('request_type', 100);
            $table->decimal('minimum_amount', 20, 4)->nullable();
            $table->decimal('maximum_amount', 20, 4)->nullable();
            $table->json('conditions')->nullable();
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'request_type', 'is_active'], 'approval_workflows_lookup_idx');
        });

        Schema::create('support_approval_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_id')->constrained('support_approval_workflows')->cascadeOnDelete();
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approver_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('name');
            $table->string('hierarchy_route', 50)->nullable();
            $table->unsignedInteger('required_approvals')->default(1);
            $table->unsignedInteger('sla_hours')->nullable();
            $table->json('conditions')->nullable();
            $table->timestamps();

            $table->unique(['workflow_id', 'sequence'], 'approval_steps_workflow_sequence_unique');
        });

        Schema::create('support_approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained('support_approval_workflows')->restrictOnDelete();
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->nullableMorphs('subject');
            $table->string('request_type', 100);
            $table->decimal('amount', 20, 4)->nullable();
            $table->json('context')->nullable();
            $table->string('status', 30)->default('pending');
            $table->unsignedInteger('current_step_sequence')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'request_type', 'status'], 'approval_requests_queue_idx');
        });

        Schema::create('support_approval_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->constrained('support_approval_requests')->cascadeOnDelete();
            $table->foreignId('step_id')->constrained('support_approval_steps')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 30);
            $table->text('reason')->nullable();
            $table->json('previous_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->index(['request_id', 'step_id', 'decision'], 'approval_decisions_step_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_approval_decisions');
        Schema::dropIfExists('support_approval_requests');
        Schema::dropIfExists('support_approval_steps');
        Schema::dropIfExists('support_approval_workflows');
    }
};
