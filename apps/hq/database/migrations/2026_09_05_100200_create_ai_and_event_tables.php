<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cost / AI / tracing foundation and the event inbox-outbox.
 * Phase 0 creates the tables only; no model is called and no agent exists yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('agent_key', 64)->nullable()->index();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->string('workflow_ref')->nullable();
            $table->string('task', 96);
            $table->string('provider', 32)->nullable();
            $table->string('model', 96)->nullable();
            $table->string('model_tier', 16)->nullable(); // low|medium|high|specialised
            $table->string('prompt_version', 64)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cached_tokens')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->decimal('estimated_cost_usd', 12, 6)->default(0);
            $table->string('status', 24)->default('pending'); // pending|succeeded|failed|refused
            $table->text('error')->nullable();
            $table->string('input_ref')->nullable();
            $table->string('output_ref')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'created_at']);
        });

        Schema::create('tool_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            $table->string('tool_key', 64)->index();
            $table->jsonb('input')->nullable();
            $table->jsonb('output')->nullable();
            $table->string('policy_result', 24)->nullable(); // allow|require_approval|deny
            $table->unsignedBigInteger('approval_id')->nullable();
            $table->string('idempotency_key', 128)->nullable()->index();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('status', 24); // succeeded|failed|uncertain|refused
            $table->text('error')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('connector_calls', function (Blueprint $table) {
            $table->id();
            $table->string('connector', 64)->index();
            $table->string('operation', 96);
            $table->string('idempotency_key', 128)->nullable()->index();
            $table->string('request_ref')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('provider_ref')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('status', 24); // succeeded|failed|uncertain
            $table->text('error')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('type', 96)->index();
            $table->string('direction', 8); // inbound|outbound
            $table->string('source', 64);
            $table->string('aggregate_type', 64)->nullable();
            $table->string('aggregate_id', 96)->nullable();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->jsonb('payload');
            $table->string('dedupe_key', 191)->unique();
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('processed_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->string('status', 16)->default('pending'); // pending|processing|processed|failed|dead
            $table->text('last_error')->nullable();
            $table->index(['status', 'next_attempt_at']);
            $table->index(['aggregate_type', 'aggregate_id']);
        });

        Schema::create('owner_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('key', 96);
            $table->jsonb('value');
            $table->timestamps();
            $table->unique(['user_id', 'key']);
        });

        Schema::create('decision_records', function (Blueprint $table) {
            $table->id();
            $table->string('agent_key', 64)->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('context_type', 64);
            $table->string('context_id', 96);
            $table->jsonb('proposal')->nullable();
            $table->jsonb('final')->nullable();
            $table->jsonb('delta')->nullable();
            $table->string('decision', 16); // approved|edited|rejected
            $table->text('reason')->nullable();
            $table->jsonb('outcome')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['context_type', 'context_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decision_records');
        Schema::dropIfExists('owner_preferences');
        Schema::dropIfExists('events');
        Schema::dropIfExists('connector_calls');
        Schema::dropIfExists('tool_calls');
        Schema::dropIfExists('ai_runs');
    }
};
