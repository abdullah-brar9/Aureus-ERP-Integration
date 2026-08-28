<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_off_leaves', function (Blueprint $table): void {
            $table->foreignId('approval_request_id')
                ->nullable()
                ->after('second_approver_id')
                ->constrained('support_approval_requests')
                ->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('state');
            $table->timestamp('approved_at')->nullable()->after('submitted_at');
            $table->timestamp('rejected_at')->nullable()->after('approved_at');
            $table->text('rejection_reason')->nullable()->after('rejected_at');
        });
    }

    public function down(): void
    {
        Schema::table('time_off_leaves', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approval_request_id');
            $table->dropColumn([
                'submitted_at',
                'approved_at',
                'rejected_at',
                'rejection_reason',
            ]);
        });
    }
};
