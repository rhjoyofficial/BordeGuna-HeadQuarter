<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/101 §11 ruling 9.1, docs/102: the HQ-native WorkflowEngine's durable state. Three tables.
 *
 *   workflow_instances  one row per run: identity, status, where it waits, its lease
 *   workflow_steps      the step ledger, one row per (instance, step): attempts, idempotency key,
 *                       the tool call and approval it produced, the persisted outcome
 *   workflow_signals    the signal inbox: buffered until the run reaches the wait that takes it,
 *                       deduplicated per instance so a redelivered event is one signal
 *
 * Nothing here is a second approval mechanism (approvals stay in `approvals`) or a second record of
 * an effect (that stays in `tool_calls`); the ledger points at both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_instances', function (Blueprint $table) {
            $table->id();
            // The run id: generated here, carried into every step's idempotency key.
            $table->uuid('uuid')->unique();
            // The caller's business key, e.g. "order-follow-up:lal:1234". Starting it twice is one run.
            $table->string('instance_key', 191)->unique();
            $table->string('type', 96);
            $table->unsignedInteger('definition_version');
            $table->string('status', 16)->index();
            $table->string('current_step', 64)->nullable();
            $table->jsonb('input')->nullable();
            $table->jsonb('output')->nullable();
            // Where it waits: signal | timer | approval, what for, and until when.
            $table->string('wait_kind', 16)->nullable();
            $table->string('wait_for', 191)->nullable();
            $table->timestampTz('wake_at')->nullable()->index();
            // Events about this aggregate ("order:1234") are delivered to the run as signals.
            $table->string('correlation_key', 191)->nullable()->index();
            $table->uuid('correlation_id');
            $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
            // The named requester every step runs as; null = the narrow `system` identity.
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // A worker's claim on the run. An expired lease means the worker is presumed dead.
            $table->uuid('lease_token')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $table->string('step', 64);
            $table->unsignedSmallInteger('seq');
            $table->string('kind', 16); // tool | signal | sleep
            $table->string('status', 16);
            $table->unsignedSmallInteger('attempt')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(1);
            // wf:{run uuid}:{step}: the same for every attempt, so a retry replays rather than repeats.
            $table->string('idempotency_key', 191)->unique();
            $table->string('tool_key', 96)->nullable();
            $table->foreignId('tool_call_id')->nullable()->constrained('tool_calls')->nullOnDelete();
            $table->foreignId('approval_id')->nullable()->constrained('approvals')->nullOnDelete();
            $table->jsonb('outcome')->nullable();
            $table->text('error')->nullable();
            // A sleep's end, a signal's deadline, or a retry's earliest time. Persisted on first visit,
            // so every later advance computes the same answer.
            $table->timestampTz('wake_at')->nullable();
            // A human accepted that an uncertain call older than the gateway's replay ceiling may run again.
            $table->timestampTz('uncertain_accepted_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['workflow_instance_id', 'step']);
        });

        Schema::create('workflow_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $table->string('name', 128);
            $table->jsonb('payload')->nullable();
            $table->string('dedupe_key', 191);
            $table->string('consumed_by_step', 64)->nullable();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestamps();
            $table->unique(['workflow_instance_id', 'dedupe_key']);
            $table->index(['workflow_instance_id', 'name', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_signals');
        Schema::dropIfExists('workflow_steps');
        Schema::dropIfExists('workflow_instances');
    }
};
