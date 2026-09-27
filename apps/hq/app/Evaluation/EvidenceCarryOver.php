<?php

namespace App\Evaluation;

use Illuminate\Support\Facades\DB;

/**
 * Carries a run's own evidence across the rollback of the fixture it ran on (docs/91 A.1).
 *
 * The Support agent is measured on a real thread that exists for the whole run and is rolled back
 * afterwards, so no test conversation ever reaches production data. Rolling back the fixture must not
 * also roll back what the RUN wrote: the model calls (`ai_runs`, whose cost is the budget's only
 * ledger, so erasing them would erase money actually spent), the raw replies, the run itself, its tool
 * calls, its approvals and connector calls, and its audit trail. Those are the evidence a gate is read
 * from.
 *
 * So the rows created in these tables after the fixture was built (`mark()`, taken after the thread
 * exists and before the agent runs) are read before the rollback (`collect()`) and written back after
 * it (`restore()`), with their own ids. Postgres sequences are not rolled back, so the ids stay unused.
 * Nothing in these tables has a foreign key to a conversation, which is why carrying them cannot carry
 * the fixture; the conversation tables themselves are never carried.
 *
 * ⚠ Only INSERTED rows are carried. An update the run made to a row that existed before it (none on the
 * Support thread path today: its tools are the thread read and the reply proposal) is rolled back with
 * the fixture; `docs/91-ab-record.md` says so.
 */
final class EvidenceCarryOver
{
    /** In the order they are restored, parents first. */
    public const TABLES = ['ai_runs', 'ai_run_replies', 'agent_runs', 'tool_calls', 'approvals', 'connector_calls', 'audit_logs'];

    /** @return array<string, int> the highest id in each table before the run */
    public function mark(): array
    {
        $marks = [];
        foreach (self::TABLES as $table) {
            $marks[$table] = (int) DB::table($table)->max('id');
        }

        return $marks;
    }

    /**
     * @param  array<string, int>  $marks
     * @return array<string, list<array<string, mixed>>>
     */
    public function collect(array $marks): array
    {
        $rows = [];
        foreach (self::TABLES as $table) {
            $rows[$table] = DB::table($table)->where('id', '>', $marks[$table] ?? 0)->orderBy('id')->get()
                ->map(fn ($row) => (array) $row)->all();
        }

        return $rows;
    }

    /**
     * Writes the collected rows back after the rollback. `tool_calls` and `approvals` point at each other,
     * so a tool call goes in without its approval id, the approvals follow, and the link is restored.
     *
     * @param  array<string, list<array<string, mixed>>>  $rows
     */
    public function restore(array $rows): void
    {
        $approvalLinks = [];
        foreach (self::TABLES as $table) {
            foreach ($rows[$table] ?? [] as $row) {
                if ($table === 'tool_calls' && ($row['approval_id'] ?? null) !== null) {
                    $approvalLinks[$row['id']] = $row['approval_id'];
                    $row['approval_id'] = null;
                }
                // ⛔ Only a row the rollback removed goes back. On production other processes write these
                //    tables while a gate runs (the scheduler ticks every minute: tool calls, audit rows) and
                //    commit on their own connections, so a row newer than the mark may still exist after the
                //    rollback. Re-inserting it would collide on its id and end the gate mid-run; skipping it
                //    is exact, because it was never lost (ON CONFLICT DO NOTHING on the primary key).
                DB::table($table)->insertOrIgnore($row);
            }
        }
        foreach ($approvalLinks as $toolCallId => $approvalId) {
            DB::table('tool_calls')->where('id', $toolCallId)->update(['approval_id' => $approvalId]);
        }
    }
}
