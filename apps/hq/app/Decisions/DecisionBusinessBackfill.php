<?php

namespace App\Decisions;

use Illuminate\Support\Facades\DB;

/**
 * Fills `decision_records.business_id` from RELIABLE context only (ruling docs/120 item 21): the context row the
 * record names exists and itself carries a business. Run once by the migration that adds the column.
 *
 * ⛔ It never guesses and never overwrites: only rows whose `business_id` is still null are touched, and only
 *    from an approval (by id), an agent run or a conversation (by uuid) that names a business. Everything else
 *    stays null and is listed by `hq:decisions:report --unassigned` (`UnassignedDecisions`). Running it again
 *    assigns nothing new unless a context row changed, and changes no assigned row.
 */
final class DecisionBusinessBackfill
{
    /** @return array{approval: int, agent_run: int, conversation: int} rows assigned per context type */
    public function run(): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return ['approval' => 0, 'agent_run' => 0, 'conversation' => 0];
        }

        return [
            'approval' => DB::update(<<<'SQL'
                UPDATE decision_records d SET business_id = a.business_id
                FROM approvals a
                WHERE d.business_id IS NULL AND d.context_type = 'approval' AND d.context_id ~ '^[0-9]{1,18}$'
                  AND a.id = d.context_id::bigint AND a.business_id IS NOT NULL
            SQL),
            'agent_run' => DB::update(<<<'SQL'
                UPDATE decision_records d SET business_id = r.business_id
                FROM agent_runs r
                WHERE d.business_id IS NULL AND d.context_type = 'agent_run' AND r.uuid::text = d.context_id AND r.business_id IS NOT NULL
            SQL),
            'conversation' => DB::update(<<<'SQL'
                UPDATE decision_records d SET business_id = c.business_id
                FROM conversations c
                WHERE d.business_id IS NULL AND d.context_type = 'conversation' AND c.uuid::text = d.context_id AND c.business_id IS NOT NULL
            SQL),
        ];
    }
}
