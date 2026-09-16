<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The shadow period as a record (docs/77 C.2): when an agent was switched on at A0, on what evidence,
 * by whom, what it was asked, and what each day of it showed.
 *
 * Until now "the seven-day shadow period" was a phrase in docs/48 and a filter on `agent_runs`.
 * ChatGPT's instruction of 16 September makes it a deliberate, audited act with daily evidence and
 * hard stop conditions, and an act like that needs a row: something the activation writes, the
 * scheduler reads, the evidence hangs off, and a stop closes.
 *
 * ⛔ `autonomy_level` is constrained to `a0` in the database. A shadow period is, by definition,
 * the level at which nothing the agent proposes is executed; a later milestone that wants a
 * different kind of period changes this constraint in its own migration and says why.
 *
 * ⛔ One open period per agent, by a partial unique index, so two activations cannot race each
 * other into two overlapping records of the same week.
 *
 * ⭐ `shadow_evidence_days` holds one row per COMPLETE day, written once. A day's numbers are
 * derived from `agent_runs`, `tool_calls` and `ai_runs`, which remain the ledger; the day row is the
 * snapshot that was read on the morning after, so the evidence a later autonomy decision cites is
 * the evidence that existed when it was collected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shadow_periods', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('agent_id')->constrained('agents')->restrictOnDelete();
            $table->string('agent_key', 64);
            $table->string('autonomy_level', 4)->default('a0');
            $table->string('status', 16)->default('open'); // open|closed|stopped

            $table->timestampTz('opened_at');
            $table->timestampTz('planned_end_at');
            $table->timestampTz('closed_at')->nullable();

            // Who switched it on and why. A console activation has no signed-in user; the label
            // then says which command did it, and the reason says on whose word.
            $table->foreignId('opened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('opened_by_label')->nullable();
            $table->text('reason');

            // What the activation rested on: the evaluation runs that passed, the prompt stamp in
            // force, and the agent's tool set at that moment (a change to it mid-period is a stop).
            $table->jsonb('evidence');
            $table->jsonb('allowed_tools');

            // The operator-owned questions the scheduler asks during the period (docs/77 C.2.6).
            $table->jsonb('questions');

            $table->text('end_reason')->nullable();
            $table->jsonb('violations')->nullable();
            $table->timestamps();

            $table->index(['agent_key', 'status']);
        });

        DB::statement("ALTER TABLE shadow_periods ADD CONSTRAINT shadow_periods_level_a0 CHECK (autonomy_level = 'a0')");
        DB::statement("ALTER TABLE shadow_periods ADD CONSTRAINT shadow_periods_status CHECK (status IN ('open', 'closed', 'stopped'))");
        DB::statement("CREATE UNIQUE INDEX shadow_periods_one_open_per_agent ON shadow_periods (agent_key) WHERE status = 'open'");

        Schema::create('shadow_evidence_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shadow_period_id')->constrained('shadow_periods')->restrictOnDelete();
            // The calendar day in the period's timezone (Asia/Dhaka), and the UTC window it covers.
            $table->date('day');
            $table->timestampTz('window_start');
            $table->timestampTz('window_end');
            $table->jsonb('metrics');
            $table->jsonb('violations');
            $table->timestampTz('computed_at');
            $table->timestamps();

            $table->unique(['shadow_period_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shadow_evidence_days');
        Schema::dropIfExists('shadow_periods');
    }
};
