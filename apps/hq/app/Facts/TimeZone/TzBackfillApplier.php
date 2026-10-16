<?php

namespace App\Facts\TimeZone;

use App\Models\User;
use App\Support\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * The ONLY writer of the time-zone backfill (docs/133, ruling docs/131 section 2 item 8). Reached from
 * `hq:facts:tz-backfill --apply` and nowhere else, and only after Joy has read the dry run and authorized it.
 *
 * One call, one batch:
 *  - a PostgreSQL advisory lock refuses a second apply while one runs;
 *  - in ONE transaction the plan is recomputed from the live rows and its batch key compared with the key the
 *    person reviewed: a different key refuses everything (the data changed since the dry run), unless the live
 *    plan is empty and that key is already recorded, which is a replay and changes nothing;
 *  - each correctable timestamp is updated only while it still holds the reviewed old value (a guarded UPDATE
 *    that must touch exactly one row), and each change is appended to `facts_tz_corrections` with its old and
 *    new value, source event and raw string; one `facts.tz_backfill.applied` audit row names the batch;
 *  - before commit the plan is computed again: no correctable timestamp may remain, and every corrected column
 *    must now hold its new value. Otherwise the whole transaction rolls back and nothing is changed.
 *
 * ⛔ Ambiguous timestamps are never touched: they are not in the batch. No other column is written but
 *    `updated_at`.
 */
final class TzBackfillApplier
{
    /** The advisory lock the backfill takes for its whole run. */
    private const LOCK = 133_000_133;

    public function __construct(private readonly TzBackfillPlanner $planner, private readonly Auditor $auditor) {}

    /** @return array<string, mixed> */
    public function apply(string $key, User $actor, string $reason): array
    {
        $reason = trim($reason);
        if (strlen($reason) < 10) {
            throw new InvalidArgumentException('The backfill needs a reason of at least ten characters.');
        }
        if (! $actor->is_active) {
            throw new InvalidArgumentException("HQ user [{$actor->email}] is disabled.");
        }

        if (! (bool) DB::selectOne('SELECT pg_try_advisory_lock(?) AS ok', [self::LOCK])->ok) {
            throw new InvalidArgumentException('Another time-zone backfill is applying right now; nothing was changed.');
        }
        try {
            return DB::transaction(fn () => $this->applyLocked($key, $actor, $reason));
        } finally {
            DB::selectOne('SELECT pg_advisory_unlock(?) AS ok', [self::LOCK]);
        }
    }

    /** @return array<string, mixed> */
    private function applyLocked(string $key, User $actor, string $reason): array
    {
        DB::statement('LOCK TABLE facts_orders IN SHARE ROW EXCLUSIVE MODE');
        $plan = $this->planner->plan();

        if ($plan['batch_key'] !== $key) {
            if ($plan['correctable'] === [] && DB::table('facts_tz_corrections')->where('batch_key', $key)->exists()) {
                return ['status' => 'already_applied', 'batch_key' => $key, 'changed' => 0, 'plan' => $plan];
            }
            throw new InvalidArgumentException("The live plan's key is {$plan['batch_key']}, not [{$key}]: the data changed since the dry run, or the key is wrong. Run the dry run again and review it. Nothing was changed.");
        }
        if ($plan['correctable'] === []) {
            return ['status' => 'nothing_to_correct', 'batch_key' => $key, 'changed' => 0, 'plan' => $plan];
        }

        $now = now();
        foreach ($plan['correctable'] as $c) {
            $column = $c['column'];
            if (! in_array($column, TzBackfillPlanner::COLUMNS, true)) {
                throw new RuntimeException("Unexpected column [{$column}].");
            }
            $touched = DB::table('facts_orders')
                ->where('id', $c['facts_order_id'])
                ->where($column, $c['stored_utc'].'+00')
                ->update([$column => $c['expected_utc'].'+00', 'updated_at' => $now]);
            if ($touched !== 1) {
                throw new RuntimeException("facts_orders#{$c['facts_order_id']}.{$column} no longer holds the reviewed value; nothing was changed.");
            }
            DB::table('facts_tz_corrections')->insert([
                'batch_key' => $key,
                'facts_order_id' => $c['facts_order_id'],
                'business_id' => $c['business_id'],
                'column_name' => $column,
                'old_value' => $c['stored_utc'].'+00',
                'new_value' => $c['expected_utc'].'+00',
                'source_event_id' => $c['source_event_id'],
                'source_string' => mb_substr($c['source_string'], 0, 64),
                'reason' => $reason,
                'applied_by' => $actor->getKey(),
                'created_at' => $now,
            ]);
        }

        $verification = $this->verify($key, $plan['correctable']);
        if (! $verification['ok']) {
            throw new RuntimeException('Verification failed, the transaction is rolled back and nothing was changed: '.implode('; ', $verification['failures']));
        }

        $this->auditor->record('facts.tz_backfill.applied', 'facts_orders', after: [
            'batch_key' => $key,
            'changed' => count($plan['correctable']),
            'by_column' => array_count_values(array_column($plan['correctable'], 'column')),
            'ambiguous_left_untouched' => $plan['totals']['ambiguous'],
            'verification' => $verification,
        ], reason: $reason, actorUserId: $actor->getKey());

        return ['status' => 'applied', 'batch_key' => $key, 'changed' => count($plan['correctable']), 'verification' => $verification, 'plan' => $plan];
    }

    /**
     * @param  list<array<string, mixed>>  $applied
     * @return array{ok: bool, checks: int, failures: list<string>}
     */
    private function verify(string $key, array $applied): array
    {
        $failures = [];
        $checks = 0;
        foreach ($applied as $c) {
            $checks++;
            $now = DB::selectOne("SELECT to_char({$c['column']} AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS v FROM facts_orders WHERE id = ?", [$c['facts_order_id']])->v;
            if ($now !== $c['expected_utc']) {
                $failures[] = "facts_orders#{$c['facts_order_id']}.{$c['column']} holds {$now}, expected {$c['expected_utc']}";
            }
        }
        $checks++;
        $logged = DB::table('facts_tz_corrections')->where('batch_key', $key)->count();
        if ($logged !== count($applied)) {
            $failures[] = "{$logged} correction row(s) logged for ".count($applied).' change(s)';
        }
        $checks++;
        $again = $this->planner->plan();
        if ($again['correctable'] !== []) {
            $failures[] = count($again['correctable']).' correctable timestamp(s) remain after the apply';
        }

        return ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
    }
}
