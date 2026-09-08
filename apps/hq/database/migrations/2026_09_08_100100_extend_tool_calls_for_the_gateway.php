<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2, M1: make `tool_calls` a real control-plane record.
 *
 * The table was created in Phase 0 and, until this milestone, no code path had ever written a
 * single row to it. It was shaped for an agent-only world: an `ai_run_id`, a tool key, input,
 * output and a policy result. The gateway records every caller, not only agents, and a row has to
 * answer "who asked, as whom, on which business, under which correlation id, and what did policy
 * decide" without joining to a run that may not exist.
 *
 * Everything here is additive and nullable, so the existing (empty) table and its indexes are
 * untouched and the migration is reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tool_calls', function (Blueprint $table) {
            $table->string('tool_version', 16)->nullable()->after('tool_key');
            $table->string('risk_tier', 4)->nullable()->after('tool_version');

            // Who asked, and as whom. caller_kind is the door the call came through; actor_* is the
            // identity it ran under. They are different questions: a scheduler call runs as the
            // job's owning human, and losing either one loses half the story.
            $table->string('caller_kind', 16)->nullable()->after('risk_tier');
            $table->string('actor_type', 16)->nullable()->after('caller_kind'); // human|agent|service|system
            $table->unsignedBigInteger('actor_id')->nullable()->after('actor_type');
            $table->string('actor_label')->nullable()->after('actor_id');
            $table->string('agent_key', 64)->nullable()->after('actor_label');
            $table->foreignId('enabling_user_id')->nullable()->after('agent_key')->constrained('users')->nullOnDelete();

            $table->foreignId('business_id')->nullable()->after('enabling_user_id')->constrained('businesses')->nullOnDelete();
            $table->foreignId('store_id')->nullable()->after('business_id')->constrained('stores')->nullOnDelete();
            $table->foreignId('job_run_id')->nullable()->after('store_id')->constrained('job_runs')->nullOnDelete();

            $table->uuid('correlation_id')->nullable()->after('job_run_id');
            $table->text('policy_reason')->nullable()->after('policy_result');
            $table->string('failure_class', 24)->nullable()->after('error'); // refused|unavailable|uncertain|validation_failed|unsupported|internal
            $table->boolean('replayed')->default(false)->after('failure_class');
            $table->unsignedSmallInteger('attempt')->default(1)->after('replayed');

            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            // Phase 0 gave this table only `created_at`, because a row was assumed to be written
            // once at the end. The gateway records intent on entry and the outcome on completion,
            // so a row is updated exactly once and needs somewhere to say when.
            $table->timestampTz('updated_at')->nullable();

            $table->index('correlation_id');
            $table->index(['tool_key', 'idempotency_key']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('tool_calls', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropIndex(['tool_key', 'idempotency_key']);
            $table->dropIndex(['correlation_id']);

            $table->dropConstrainedForeignId('job_run_id');
            $table->dropConstrainedForeignId('store_id');
            $table->dropConstrainedForeignId('business_id');
            $table->dropConstrainedForeignId('enabling_user_id');

            $table->dropColumn([
                'tool_version', 'risk_tier', 'caller_kind', 'actor_type', 'actor_id', 'actor_label',
                'agent_key', 'correlation_id', 'policy_reason', 'failure_class', 'replayed',
                'attempt', 'started_at', 'finished_at', 'updated_at',
            ]);
        });
    }
};
