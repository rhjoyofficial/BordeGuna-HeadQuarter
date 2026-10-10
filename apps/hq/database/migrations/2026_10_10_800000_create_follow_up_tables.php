<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S4 part 1 (docs/113 section 9): the Opportunity and Follow-up records, business-scoped.
 *
 * Working records with a state, updated in place through a conditional update and audited on every
 * transition (docs/113 F3, F11); never deleted. No amount, price or quantity anywhere (F2); no
 * message body (F1); the follow-up's workflow run is referenced by key and uuid, never by a foreign
 * key, because runs are pruned and the follow-up outlives its timer (section 7).
 *
 * ⚠ Every foreign key is RESTRICT (F12). Purely additive: two new tables. A rollback drops them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->restrictOnDelete();

            $table->string('kind', 32);
            $table->string('subject', 500);
            $table->date('expected_at')->nullable();
            $table->string('state', 16);

            $table->string('proposer_type', 16);
            $table->foreignId('proposed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('proposed_by_agent_run_id')->nullable()->constrained('agent_runs')->restrictOnDelete();
            $table->string('proposal_ref', 64)->nullable();
            $table->jsonb('evidence')->nullable();

            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->string('decision_reason', 1000)->nullable();

            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->string('close_reason', 32)->nullable();
            $table->string('close_note', 1000)->nullable();

            $table->timestampsTz();

            $table->index(['business_id', 'state', 'created_at']);
            $table->index('customer_id');
        });

        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->restrictOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained('opportunities')->restrictOnDelete();

            $table->string('origin', 16);
            $table->string('kind', 16);
            $table->string('channel', 16);
            $table->timestampTz('due_at');
            $table->string('intent', 500);
            $table->string('state', 16);

            $table->string('proposer_type', 16);
            $table->foreignId('proposed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('proposed_by_agent_run_id')->nullable()->constrained('agent_runs')->restrictOnDelete();
            $table->string('proposal_ref', 64)->nullable();
            $table->jsonb('evidence')->nullable();

            // docs/113 section 6: what the S7 ledger answered, when. A record, never a permission.
            $table->timestampTz('consent_checked_at')->nullable();
            $table->boolean('consent_permitted')->nullable();
            $table->string('consent_refusal', 32)->nullable();
            $table->foreignId('consent_event_id')->nullable()->constrained('consent_events')->restrictOnDelete();

            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->string('decision_reason', 1000)->nullable();

            // docs/113 section 7: the WorkflowEngine run, by key and uuid. No foreign key on purpose.
            $table->string('workflow_type', 64)->nullable();
            $table->string('workflow_instance_key', 191)->nullable()->unique();
            $table->uuid('workflow_run_uuid')->nullable();

            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->string('close_note', 1000)->nullable();

            $table->timestampsTz();

            $table->index(['business_id', 'state', 'due_at']);
            $table->index('customer_id');
            $table->index('opportunity_id');
        });

        foreach (['opportunities', 'follow_ups'] as $t) {
            DB::statement("ALTER TABLE {$t} ADD CONSTRAINT {$t}_proposer_check CHECK (
                (proposer_type = 'person' AND proposed_by_user_id IS NOT NULL AND proposed_by_agent_run_id IS NULL)
                OR (proposer_type = 'agent_run' AND proposed_by_agent_run_id IS NOT NULL AND proposed_by_user_id IS NULL)
            )");
        }
        DB::statement("ALTER TABLE follow_ups ADD CONSTRAINT follow_ups_kind_check CHECK (kind IN ('service', 'marketing'))");
        DB::statement("ALTER TABLE follow_ups ADD CONSTRAINT follow_ups_channel_check CHECK (channel <> 'internal')");
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_ups');
        Schema::dropIfExists('opportunities');
    }
};
