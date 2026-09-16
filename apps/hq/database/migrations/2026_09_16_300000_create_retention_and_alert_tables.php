<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 M7's operational foundation (docs/78 B.2): retention with lineage, and alerts.
 *
 * `retention_runs` is the record of every retention pass: the policy it applied, what it rolled up,
 * what it removed, the id ranges and how it ended. `daily_aggregates` is what a removed detail row
 * becomes: one row per day, source table, grouping AND run, so each aggregate row is exactly one
 * pass's contribution and can always be told apart from the detail it replaced and traced to the pass
 * that wrote it. `retention_watermarks` is how far each table has been fully drained, so a rerun
 * starts where the last pass finished instead of rescanning days that hold only kept rows.
 *
 * `alerts` holds one row per open condition, keyed by a fingerprint, so a condition that stays true is
 * one alert with a count rather than a message every five minutes; resolving it closes the row, and a
 * recurrence is a new row.
 *
 * The three `created_at` indexes and the partial `events` index exist for the retention scans: the
 * first two tables had no index a date range could use, and the partial index holds exactly the event
 * rows whose payload is still eligible for compaction, so it shrinks as the policy is applied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retention_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('policy_version', 32);
            // The policy as it was applied: keep days per table, batch sizes and bounds.
            $table->jsonb('policy');
            $table->string('status', 16)->default('running'); // running|succeeded|failed|abandoned
            $table->jsonb('summary')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at']);
        });

        Schema::create('retention_watermarks', function (Blueprint $table) {
            $table->string('source_table', 32)->primary();
            // Every Dhaka calendar day up to and including this one has been drained of prunable rows.
            $table->date('through_day');
            $table->foreignId('retention_run_id')->constrained('retention_runs')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('daily_aggregates', function (Blueprint $table) {
            $table->id();
            $table->date('day'); // the Asia/Dhaka calendar day the detail rows were created on
            $table->string('source_table', 32);
            // The grouping the rows were counted under, and its sha256 for the unique key.
            $table->jsonb('dimension');
            $table->char('dimension_hash', 64);
            $table->unsignedInteger('rows');
            $table->decimal('sum_cost_usd', 14, 6)->nullable();
            $table->unsignedBigInteger('sum_duration_ms')->nullable();
            $table->unsignedBigInteger('sum_tokens_in')->nullable();
            $table->unsignedBigInteger('sum_tokens_out')->nullable();
            // Table-specific sums that have no column of their own.
            $table->jsonb('measures')->nullable();
            $table->unsignedBigInteger('min_source_id');
            $table->unsignedBigInteger('max_source_id');
            $table->foreignId('retention_run_id')->constrained('retention_runs')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['day', 'source_table', 'dimension_hash', 'retention_run_id']);
            $table->index(['source_table', 'day']);
        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->string('rule', 64);
            $table->string('fingerprint', 191);
            $table->string('severity', 16); // info|warning|critical
            $table->string('subject');
            $table->text('body');
            $table->jsonb('context')->nullable();
            // Null when HQ's alert engine sends the message; otherwise what already pages this
            // condition (the host monitor, the model runtime, the approvals sweep), so it is recorded
            // here without a second message.
            $table->string('paged_by', 64)->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            // Consecutive checks that found the condition gone; it resolves at the configured count.
            $table->unsignedSmallInteger('clear_checks')->default(0);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('notified_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['rule', 'resolved_at']);
            $table->index(['resolved_at', 'severity']);
        });

        Schema::table('tool_calls', function (Blueprint $table) {
            $table->index('created_at');
        });

        Schema::table('connector_calls', function (Blueprint $table) {
            $table->index('created_at');
        });

        // One OPEN alert per fingerprint; a resolved one may recur as a new row.
        DB::statement('CREATE UNIQUE INDEX alerts_open_fingerprint ON alerts (fingerprint) WHERE resolved_at IS NULL');

        // The processed events whose payload has not yet been reduced to a digest. `jsonb_exists` is the
        // function behind the `?` operator, spelled out because a `?` in a statement is a placeholder to
        // PDO; the retention query uses the same spelling so the planner can match this predicate.
        DB::statement("CREATE INDEX events_payload_retainable ON events (created_at, id) WHERE status = 'processed' AND NOT jsonb_exists(payload, '_retention')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS events_payload_retainable');

        Schema::table('connector_calls', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        Schema::table('tool_calls', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        Schema::dropIfExists('alerts');
        Schema::dropIfExists('daily_aggregates');
        Schema::dropIfExists('retention_watermarks');
        Schema::dropIfExists('retention_runs');
    }
};
