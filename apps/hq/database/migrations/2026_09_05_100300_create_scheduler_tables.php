<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduler foundation (ADR-005, ADR-011).
 *
 * scheduled_jobs is the durable definition; job_runs is one row per LOGICAL slot,
 * enforced by the unique (scheduled_job_id, scheduled_for) constraint. Execution
 * is at-least-once: the same run row is re-attempted (attempt counter + attempts
 * log), never duplicated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->string('name');
            $table->string('kind', 16); // once|cron|interval|event
            $table->string('cron_expression', 96)->nullable();
            $table->timestampTz('run_at')->nullable();
            $table->unsignedInteger('interval_seconds')->nullable();
            $table->string('event_type', 96)->nullable();
            $table->string('timezone', 64)->default('Asia/Dhaka');
            $table->string('tool_key', 64);
            $table->jsonb('payload')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approval_state', 16)->default('approved'); // pending|approved|rejected
            $table->unsignedSmallInteger('retry_max')->default(3);
            $table->unsignedInteger('retry_backoff_seconds')->default(60);
            $table->string('missed_policy', 24)->default('skip'); // skip|catch_up|run_latest_only
            $table->string('state', 16)->default('active'); // active|paused|cancelled|done
            $table->timestampTz('next_run_at')->nullable();
            $table->timestampTz('last_run_at')->nullable();
            $table->string('last_status', 16)->nullable();
            $table->string('created_from', 16)->default('ui'); // ui|nl|api|skill
            $table->text('nl_source_text')->nullable();
            $table->timestamps();
            $table->index(['state', 'approval_state', 'next_run_at']);
            $table->index('event_type');
        });

        Schema::create('job_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheduled_job_id')->constrained('scheduled_jobs')->cascadeOnDelete();
            $table->timestampTz('scheduled_for');
            $table->string('trigger', 16); // tick|run_now|retry|event|catch_up
            $table->unsignedSmallInteger('attempt')->default(0);
            $table->string('status', 16)->default('queued'); // queued|running|succeeded|failed|dead
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->jsonb('output')->nullable();
            $table->text('error')->nullable();
            $table->jsonb('attempts')->nullable(); // per-attempt log: [{attempt, started_at, finished_at, status, error}]
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['scheduled_job_id', 'scheduled_for']);
            $table->index(['status', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_runs');
        Schema::dropIfExists('scheduled_jobs');
    }
};
