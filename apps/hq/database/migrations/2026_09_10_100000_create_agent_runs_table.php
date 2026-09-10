<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One agent run: the unit a human reviews during the shadow period (docs/48 M5, H.4).
 *
 * ## Why this table exists when `ai_runs` and `tool_calls` already do
 *
 * An agent run SPANS several of both. The loop may take up to three model turns and eight tool
 * calls, and each of those already writes its own row keyed on the same `correlation_id`. What
 * neither can express is the thing Joy actually reviews: the question, the answer, and whether the
 * answer was any good. Reconstructing that by grouping two other tables on a correlation id would
 * work exactly until somebody needed to record a verdict against it, and a verdict has nowhere to
 * live in a grouping.
 *
 * ⭐ **Cost is deliberately NOT stored here.** `ai_runs` is the ledger (docs/52), and a rollup
 * column would be a second number that can disagree with it. The list screen sums `ai_runs` by
 * `correlation_id` in one grouped query instead. The rule from `docs/52` holds: a cost you cannot
 * re-derive is a number rather than a record.
 *
 * ⛔ **`shadow` is a fact about the run, not a setting.** It records that the answer was withheld
 * from the operator because the resolved autonomy was A0, and it is written from the resolved level
 * rather than from a flag somebody could set independently. The two cannot drift because only one
 * of them is authored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Both the id and the key. The id is the relation; the key is what survives an agent
            // row being renamed or retired, and the shadow log has to stay readable after either.
            $table->foreignId('agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->string('agent_key', 64);

            // The join to ai_runs and tool_calls. Not unique: it IS the grouping.
            $table->string('correlation_id', 64)->index();

            $table->string('trigger', 24);              // operator|schedule|evaluation|console
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();

            $table->text('question');

            // What the autonomy plane decided, and why. Stored rather than recomputed because the
            // grants, the evidence and the context that produced it are all mutable, and a run that
            // cannot say what rule governed it is not auditable six months later.
            $table->string('autonomy_level', 4);
            $table->text('autonomy_reason')->nullable();
            $table->jsonb('autonomy_constraints')->nullable();

            // True when the answer was withheld because autonomy resolved to A0.
            $table->boolean('shadow')->default(true);

            $table->string('status', 16)->default('running'); // running|answered|refused|stopped|failed
            $table->string('stop_reason', 32)->nullable();    // loop_bound|repeated_denial|no_evidence|model_error
            $table->text('answer')->nullable();
            $table->text('error')->nullable();

            // [{tool, tool_call_id, source, as_of}] - what the answer rests on.
            $table->jsonb('evidence')->nullable();
            // Tool calls the agent PROPOSED and the runtime did not execute, with the reason. This
            // is the substance of shadow mode: what it would have done.
            $table->jsonb('proposals')->nullable();
            // Provenance labels and digests only, never the text (ADR-024).
            $table->jsonb('context_provenance')->nullable();

            $table->string('model', 64)->nullable();
            $table->string('model_tier', 16)->nullable();
            $table->string('prompt_version', 64)->nullable();
            $table->string('policy_version', 32)->nullable();

            $table->unsignedSmallInteger('turns')->default(0);
            $table->unsignedSmallInteger('tool_calls_count')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();

            // The shadow review. Feeds decision_records, which is the alignment corpus (docs/48 I.3).
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            // The same three words `decision_records.decision` uses, so the alignment corpus does
            // not end up holding two vocabularies for one idea.
            $table->string('review_verdict', 16)->nullable(); // approved|edited|rejected
            $table->text('review_note')->nullable();

            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();

            $table->index(['agent_key', 'created_at']);
            $table->index(['status']);
            $table->index(['shadow', 'reviewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
    }
};
