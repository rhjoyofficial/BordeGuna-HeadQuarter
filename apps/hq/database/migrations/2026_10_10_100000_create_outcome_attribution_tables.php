<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S3: the ONE canonical outcome and attribution model (docs/107, ChatGPT ruling docs/103 §4).
 *
 * Three append-only ledgers: `outcomes` (a business result event, scoped to a business),
 * `outcome_result_refs` (which system-of-record records it is about, by natural key and, when HQ
 * mirrors them, by a typed foreign key) and `outcome_attributions` (which HQ action is credited, in
 * what role, by which method, with what confidence).
 *
 * ⛔ No amount, currency, status or lines column anywhere (docs/107 D3). The ERP and the shops own
 *    those numbers; HQ holds references and joins to the owner at read time.
 *
 * ⚠ Every foreign key is RESTRICT, never cascade or null-on-delete: both of those are writes, and the
 *    immutability trigger refuses writes (the consent ledger's reasoning, docs/107 D15). Retention is
 *    taught to keep any tool call, model call or workflow run an attribution points at (D18).
 *
 * Purely additive: three new tables, one trigger function. A rollback drops only what this created.
 */
return new class extends Migration
{
    private const ACTION_COLUMNS = [
        'agent_run' => 'agent_run_id',
        'tool_call' => 'tool_call_id',
        'ai_run' => 'ai_run_id',
        'approval' => 'approval_id',
        'decision_record' => 'decision_record_id',
        'content_publication' => 'content_publication_id',
        'workflow_instance' => 'workflow_instance_id',
        'conversation_message' => 'conversation_message_id',
    ];

    private const RESULT_COLUMNS = [
        'shop_order' => 'facts_order_id',
        'erp_order' => 'facts_order_id',
        'conversation' => 'conversation_id',
        'conversation_message' => 'conversation_message_id',
        'content_publication' => 'content_publication_id',
    ];

    public function up(): void
    {
        Schema::create('outcomes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('schema_version', 16);

            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->restrictOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->restrictOnDelete();

            $table->string('outcome_type', 64);
            $table->string('state', 16)->default('asserted'); // asserted | retracted

            // When it happened, when the source said it was true, and when HQ heard: three questions.
            $table->timestampTz('occurred_at');
            $table->timestampTz('source_as_of')->nullable();
            $table->timestampTz('recorded_at')->useCurrent();

            $table->string('source', 32);              // erp_read_back | shop_webhook | shop_reconcile | hq_internal | operator | platform_read
            $table->string('source_system', 16);       // erp | shop | hq | meta
            $table->string('source_ref', 191)->nullable();
            $table->string('provenance', 16);          // ADR-024

            $table->jsonb('evidence')->nullable();     // identifiers and digests, never copies (D5)
            $table->char('content_digest', 64);

            $table->string('actor_type', 16)->default('system');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label')->nullable();
            $table->string('correlation_id', 128)->nullable();

            $table->string('idempotency_key', 191)->unique();
            $table->unsignedBigInteger('supersedes_outcome_id')->nullable()->unique();
            $table->text('reason')->nullable();

            $table->unique(['id', 'business_id']);
            $table->index(['business_id', 'outcome_type', 'occurred_at']);
            $table->index(['business_id', 'recorded_at']);
            $table->index('correlation_id');
        });

        Schema::table('outcomes', function (Blueprint $table) {
            $table->foreign('supersedes_outcome_id')->references('id')->on('outcomes')->restrictOnDelete();
        });

        Schema::create('outcome_result_refs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outcome_id')->constrained('outcomes')->restrictOnDelete();
            $table->string('result_type', 32);
            $table->string('role', 16)->default('subject'); // subject | context
            $table->string('source_system', 16);
            $table->string('external_ref', 191);

            $table->foreignId('facts_order_id')->nullable()->constrained('facts_orders')->restrictOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->restrictOnDelete();
            $table->foreignId('conversation_message_id')->nullable()->constrained('conversation_messages')->restrictOnDelete();
            $table->foreignId('content_publication_id')->nullable()->constrained('content_publications')->restrictOnDelete();

            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['outcome_id', 'source_system', 'external_ref']);
            $table->index('facts_order_id');
            $table->index('conversation_id');
            $table->index('conversation_message_id');
            $table->index('content_publication_id');
            $table->index(['source_system', 'external_ref']);
        });

        Schema::create('outcome_attributions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('schema_version', 16);

            $table->unsignedBigInteger('outcome_id');
            $table->unsignedBigInteger('business_id');

            $table->string('action_type', 32);
            $table->foreignId('agent_run_id')->nullable()->constrained('agent_runs')->restrictOnDelete();
            $table->foreignId('tool_call_id')->nullable()->constrained('tool_calls')->restrictOnDelete();
            $table->foreignId('ai_run_id')->nullable()->constrained('ai_runs')->restrictOnDelete();
            $table->foreignId('approval_id')->nullable()->constrained('approvals')->restrictOnDelete();
            $table->foreignId('decision_record_id')->nullable()->constrained('decision_records')->restrictOnDelete();
            $table->foreignId('content_publication_id')->nullable()->constrained('content_publications')->restrictOnDelete();
            $table->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->restrictOnDelete();
            $table->foreignId('conversation_message_id')->nullable()->constrained('conversation_messages')->restrictOnDelete();
            $table->string('action_ref', 191);
            $table->string('proposal_ref', 64)->nullable();

            $table->string('role', 16);               // produced | proposed | approved | contributed
            $table->string('method', 32);             // deterministic_key | correlation | operator_asserted | rule_window | model_inferred
            $table->string('method_version', 32);
            $table->decimal('confidence', 5, 4);
            $table->string('state', 16)->default('asserted');

            $table->jsonb('evidence')->nullable();
            $table->char('content_digest', 64);

            $table->string('actor_type', 16)->default('system');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label')->nullable();
            $table->string('correlation_id', 128)->nullable();

            $table->string('idempotency_key', 191)->unique();
            $table->unsignedBigInteger('supersedes_attribution_id')->nullable()->unique();
            $table->text('reason')->nullable();
            $table->timestampTz('recorded_at')->useCurrent();

            // The database itself refuses an attribution whose business is not its outcome's (D16).
            $table->foreign(['outcome_id', 'business_id'])->references(['id', 'business_id'])->on('outcomes')->restrictOnDelete();
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();

            $table->index('outcome_id');
            $table->index(['business_id', 'method', 'recorded_at']);
            foreach (self::ACTION_COLUMNS as $column) {
                $table->index($column);
            }
        });

        Schema::table('outcome_attributions', function (Blueprint $table) {
            $table->foreign('supersedes_attribution_id')->references('id')->on('outcome_attributions')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE outcomes ADD CONSTRAINT outcomes_state_check CHECK (state IN ('asserted', 'retracted'))");
        DB::statement('ALTER TABLE outcomes ADD CONSTRAINT outcomes_not_self_superseding CHECK (supersedes_outcome_id IS NULL OR supersedes_outcome_id <> id)');
        DB::statement("ALTER TABLE outcomes ADD CONSTRAINT outcomes_retraction_supersedes CHECK (state <> 'retracted' OR supersedes_outcome_id IS NOT NULL)");

        DB::statement("ALTER TABLE outcome_result_refs ADD CONSTRAINT outcome_result_refs_role_check CHECK (role IN ('subject', 'context'))");
        DB::statement('ALTER TABLE outcome_result_refs ADD CONSTRAINT outcome_result_refs_typed_target CHECK ('.$this->resultTargetCheck().')');

        DB::statement("ALTER TABLE outcome_attributions ADD CONSTRAINT outcome_attributions_state_check CHECK (state IN ('asserted', 'retracted'))");
        DB::statement('ALTER TABLE outcome_attributions ADD CONSTRAINT outcome_attributions_confidence_range CHECK (confidence >= 0 AND confidence <= 1)');
        DB::statement('ALTER TABLE outcome_attributions ADD CONSTRAINT outcome_attributions_not_self_superseding CHECK (supersedes_attribution_id IS NULL OR supersedes_attribution_id <> id)');
        DB::statement("ALTER TABLE outcome_attributions ADD CONSTRAINT outcome_attributions_retraction_supersedes CHECK (state <> 'retracted' OR supersedes_attribution_id IS NOT NULL)");
        DB::statement('ALTER TABLE outcome_attributions ADD CONSTRAINT outcome_attributions_typed_action CHECK ('.$this->actionTargetCheck().')');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION hq_outcome_ledger_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% is append-only (% refused). Record a successor row; never edit history.', TG_TABLE_NAME, TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER outcomes_no_update BEFORE UPDATE ON outcomes
                FOR EACH ROW EXECUTE FUNCTION hq_outcome_ledger_immutable();
            CREATE TRIGGER outcomes_no_delete BEFORE DELETE ON outcomes
                FOR EACH ROW EXECUTE FUNCTION hq_outcome_ledger_immutable();
            CREATE TRIGGER outcome_result_refs_no_update BEFORE UPDATE ON outcome_result_refs
                FOR EACH ROW EXECUTE FUNCTION hq_outcome_ledger_immutable();
            CREATE TRIGGER outcome_result_refs_no_delete BEFORE DELETE ON outcome_result_refs
                FOR EACH ROW EXECUTE FUNCTION hq_outcome_ledger_immutable();
            CREATE TRIGGER outcome_attributions_no_update BEFORE UPDATE ON outcome_attributions
                FOR EACH ROW EXECUTE FUNCTION hq_outcome_ledger_immutable();
            CREATE TRIGGER outcome_attributions_no_delete BEFORE DELETE ON outcome_attributions
                FOR EACH ROW EXECUTE FUNCTION hq_outcome_ledger_immutable();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS outcome_attributions_no_delete ON outcome_attributions;'
                .' DROP TRIGGER IF EXISTS outcome_attributions_no_update ON outcome_attributions;'
                .' DROP TRIGGER IF EXISTS outcome_result_refs_no_delete ON outcome_result_refs;'
                .' DROP TRIGGER IF EXISTS outcome_result_refs_no_update ON outcome_result_refs;'
                .' DROP TRIGGER IF EXISTS outcomes_no_delete ON outcomes;'
                .' DROP TRIGGER IF EXISTS outcomes_no_update ON outcomes;'
                .' DROP FUNCTION IF EXISTS hq_outcome_ledger_immutable();'
            );
        }

        Schema::dropIfExists('outcome_attributions');
        Schema::dropIfExists('outcome_result_refs');
        Schema::dropIfExists('outcomes');
    }

    /** Exactly the column matching `action_type` is set, and no other action column. */
    private function actionTargetCheck(): string
    {
        $columns = array_values(self::ACTION_COLUMNS);
        $clauses = [];
        foreach (self::ACTION_COLUMNS as $type => $column) {
            $clauses[] = "(action_type = '{$type}' AND {$column} IS NOT NULL)";
        }

        return 'num_nonnulls('.implode(', ', $columns).') = 1 AND ('.implode(' OR ', $clauses).')';
    }

    /**
     * The column matching `result_type` is the only one that may be set. `external` sets none, and
     * `erp_order` may leave its column empty when HQ knows the ERP number but holds no mirror row.
     */
    private function resultTargetCheck(): string
    {
        $columns = array_values(array_unique(self::RESULT_COLUMNS));
        $clauses = ["(result_type = 'external' AND num_nonnulls(".implode(', ', $columns).') = 0)'];
        foreach (self::RESULT_COLUMNS as $type => $column) {
            $others = array_values(array_diff($columns, [$column]));
            $required = $type === 'erp_order' ? '' : " AND {$column} IS NOT NULL";
            $clauses[] = "(result_type = '{$type}'{$required} AND num_nonnulls(".implode(', ', $others).') = 0)';
        }

        return implode(' OR ', $clauses);
    }
};
