<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/123 (W4 of docs/120): the S4 outcome-dependent part on the ruled S3 contract.
 *
 * 1. A follow-up is one more S3 action type (docs/107 D7: "one more action type, one column"):
 *    `outcome_attributions.follow_up_id`, typed, RESTRICT (ruling items 1 and 15), and the typed-action
 *    CHECK replaced so exactly the column matching `action_type` is set, now over nine columns.
 * 2. The S4 vocabulary is validated by the database as well as by the code (ruling item 12): every
 *    vocabulary column of `follow_ups` and `opportunities` gets a CHECK naming its exact values
 *    (`followups.vocabulary.v1`, `App\FollowUps\FollowUpVocabulary`), and a closed opportunity must
 *    carry the person's close reason.
 *
 * Additive. The values are written out here, not read from the enums, so a later code change cannot
 * silently change what this migration did. A rollback restores the eight-column CHECK; it refuses (the
 * CHECK fails) while any attribution credits a follow-up, which is the right answer for evidence.
 */
return new class extends Migration
{
    private const ACTION_COLUMNS_V1 = [
        'agent_run' => 'agent_run_id',
        'tool_call' => 'tool_call_id',
        'ai_run' => 'ai_run_id',
        'approval' => 'approval_id',
        'decision_record' => 'decision_record_id',
        'content_publication' => 'content_publication_id',
        'workflow_instance' => 'workflow_instance_id',
        'conversation_message' => 'conversation_message_id',
    ];

    private const VOCABULARY_CHECKS = [
        'follow_ups_origin_check' => ['follow_ups', 'origin', ['promise', 'opportunity', 'operator']],
        'follow_ups_state_check' => ['follow_ups', 'state', ['proposed', 'refused', 'approved', 'scheduled', 'declined', 'done', 'cancelled']],
        'opportunities_kind_check' => ['opportunities', 'kind', ['reorder', 'quotation', 'wholesale', 'product_interest', 'other']],
        'opportunities_state_check' => ['opportunities', 'state', ['proposed', 'open', 'declined', 'closed']],
        'opportunities_close_reason_check' => ['opportunities', 'close_reason', ['converted', 'lost', 'no_response', 'duplicate', 'other']],
    ];

    public function up(): void
    {
        Schema::table('outcome_attributions', function (Blueprint $table) {
            $table->foreignId('follow_up_id')->nullable()->constrained('follow_ups')->restrictOnDelete();
            $table->index('follow_up_id');
        });

        DB::statement('ALTER TABLE outcome_attributions DROP CONSTRAINT outcome_attributions_typed_action');
        DB::statement('ALTER TABLE outcome_attributions ADD CONSTRAINT outcome_attributions_typed_action CHECK ('
            .$this->actionTargetCheck(self::ACTION_COLUMNS_V1 + ['follow_up' => 'follow_up_id']).')');

        foreach (self::VOCABULARY_CHECKS as $name => [$table, $column, $values]) {
            $list = implode(', ', array_map(fn (string $v) => "'{$v}'", $values));
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$column} IS NULL OR {$column} IN ({$list}))");
        }
        DB::statement("ALTER TABLE opportunities ADD CONSTRAINT opportunities_closed_has_reason CHECK (state <> 'closed' OR close_reason IS NOT NULL)");
        foreach (['follow_ups' => ['origin', 'state'], 'opportunities' => ['kind', 'state']] as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} SET NOT NULL");
            }
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE opportunities DROP CONSTRAINT IF EXISTS opportunities_closed_has_reason');
        foreach (array_keys(self::VOCABULARY_CHECKS) as $name) {
            $table = str_starts_with($name, 'follow_ups') ? 'follow_ups' : 'opportunities';
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
        }

        // Evidence is never dropped by a rollback: an attribution crediting a follow-up stops it here.
        if (DB::table('outcome_attributions')->whereNotNull('follow_up_id')->exists()) {
            throw new RuntimeException('outcome_attributions credits follow-ups; rolling back would drop that evidence. Restore from backup instead.');
        }

        DB::statement('ALTER TABLE outcome_attributions DROP CONSTRAINT outcome_attributions_typed_action');
        Schema::table('outcome_attributions', function (Blueprint $table) {
            $table->dropForeign(['follow_up_id']);
            $table->dropIndex(['follow_up_id']);
            $table->dropColumn('follow_up_id');
        });
        DB::statement('ALTER TABLE outcome_attributions ADD CONSTRAINT outcome_attributions_typed_action CHECK ('
            .$this->actionTargetCheck(self::ACTION_COLUMNS_V1).')');
    }

    /** @param  array<string, string>  $columns  action type => column */
    private function actionTargetCheck(array $columns): string
    {
        $clauses = [];
        foreach ($columns as $type => $column) {
            $clauses[] = "(action_type = '{$type}' AND {$column} IS NOT NULL)";
        }

        return 'num_nonnulls('.implode(', ', array_values($columns)).') = 1 AND ('.implode(' OR ', $clauses).')';
    }
};
