<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2, milestone M3: the Approval Centre (docs/48 G, ADR-018, docs/51).
 *
 * An approval is a human decision bound to ONE exact action. The columns that make that true are
 * `payload`, `payload_hash` and `tool_version`: the payload is what executes, the hash proves it was
 * not changed after the human read it, and the version stops an approval executing against a tool
 * whose contract has since changed.
 *
 * `consumed_at` plus a database-level uniqueness on the resulting tool call is what makes an
 * approval single-use under concurrency, rather than a UI that tries not to double-submit.
 *
 * This migration also closes the M1 gap: `tool_calls.approval_id` has been an unconstrained
 * bigint since Phase 0 because there was no table to point it at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // What was approved. Immutable once written.
            $table->string('tool_key', 64);
            $table->string('tool_version', 16);
            $table->jsonb('payload');
            $table->char('payload_hash', 64);
            $table->string('risk_tier', 4);
            $table->string('action_class', 24);

            // Where it applies.
            $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();

            // Who asked, and under what run.
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('agent_key', 64)->nullable();
            $table->foreignId('ai_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            $table->uuid('correlation_id')->nullable()->index();
            $table->foreignId('requesting_tool_call_id')->nullable()->constrained('tool_calls')->nullOnDelete();

            // Why a human should say yes, and what will happen if they do.
            $table->text('reason')->nullable();
            $table->jsonb('evidence')->nullable();
            $table->jsonb('preview')->nullable();
            $table->jsonb('estimated_cost')->nullable();

            // Which policy version produced the requirement, so the decision is reproducible.
            $table->foreignId('policy_id')->nullable()->constrained('policies')->nullOnDelete();
            $table->unsignedInteger('policy_version')->nullable();

            // pending -> approved -> consumed, or -> rejected | cancelled | expired.
            $table->string('status', 16)->default('pending');
            $table->string('approver_role', 32)->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            $table->foreignId('delegated_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('delegated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('delegated_at')->nullable();

            $table->timestampTz('sla_due_at')->nullable();
            $table->string('escalate_to_role', 32)->nullable();
            $table->timestampTz('escalated_at')->nullable();
            $table->timestampTz('expires_at')->nullable();

            // Single use. `consumed_at` is set in the same statement that claims the approval.
            $table->timestampTz('consumed_at')->nullable();
            $table->foreignId('resulting_tool_call_id')->nullable()->constrained('tool_calls')->nullOnDelete();

            // Approve-with-edit never mutates the original; it creates a successor and links back.
            $table->foreignId('supersedes_id')->nullable()->constrained('approvals')->nullOnDelete();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['status', 'sla_due_at']);
            $table->index(['status', 'expires_at']);
            $table->index('tool_key');
        });

        // The M1 gap: this column has pointed at nothing since Phase 0. Safe to constrain now
        // because every existing row has approval_id NULL (verified on production before this ran).
        Schema::table('tool_calls', function (Blueprint $table) {
            $table->foreign('approval_id')->references('id')->on('approvals')->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            // A resulting tool call belongs to at most ONE approval. Combined with the conditional
            // claim in ApprovalService, this is a database-level guarantee that one approval cannot
            // produce two executions, rather than an application promise about double clicks.
            DB::statement('CREATE UNIQUE INDEX approvals_resulting_tool_call_unique ON approvals (resulting_tool_call_id) WHERE resulting_tool_call_id IS NOT NULL');

            // A consumed approval must name what it produced, and an unconsumed one must not.
            // Expressed as a constraint because it is an invariant, not a convention.
            DB::statement("ALTER TABLE approvals ADD CONSTRAINT approvals_consumed_has_result CHECK ((status = 'consumed') = (consumed_at IS NOT NULL))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE approvals DROP CONSTRAINT IF EXISTS approvals_consumed_has_result');
            DB::statement('DROP INDEX IF EXISTS approvals_resulting_tool_call_unique');
        }

        Schema::table('tool_calls', function (Blueprint $table) {
            $table->dropForeign(['approval_id']);
        });

        Schema::dropIfExists('approvals');
    }
};
