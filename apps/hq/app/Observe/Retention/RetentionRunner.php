<?php

namespace App\Observe\Retention;

use App\Models\RetentionRun;
use App\Models\RetentionWatermark;
use App\Support\Audit\Auditor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Applies the retention policy (docs/78 B.2).
 *
 * **Deterministic.** Days are Asia/Dhaka calendar days and a detail row is eligible only when its whole
 * day is older than the table's keep window, so the same data and the same date give the same pass.
 * **Bounded.** Rows per transaction, rows and days per table, and wall time all have ceilings; a pass
 * that reaches one stops between transactions and says which. **Auditable.** Every pass is a
 * `retention_runs` row with the policy it applied and what it did, and an audit row; every aggregate
 * names its pass and the id range it stands for. **Idempotent and safe to retry.** Each chunk rolls its
 * rows up and deletes exactly those rows in ONE transaction, so a failure leaves either both or
 * neither; a watermark records each fully drained day, and the next pass starts after it.
 *
 * ⛔ The only DELETE here is on a detail table named by `RetentionPolicy`, by id, for ids selected under
 * that table's keep condition (the workflow state has its own, in `WorkflowRetention`; the follow-up tables theirs, in `FollowUpRetention`). The only UPDATE outside retention's own tables replaces the payload of a
 * `processed` event with its digest. Nothing here can name a permanent table.
 */
final class RetentionRunner
{
    private const LOCK = 'hq:retention:run';

    public function __construct(
        private readonly RetentionPolicy $policy,
        private readonly Auditor $auditor,
        private readonly WorkflowRetention $workflows,
        private readonly FollowUpRetention $followUps,
    ) {}

    /**
     * What a pass would do now, per table: the cutoff, the watermark, and how many rows are eligible
     * and how many are kept forever. Reads only.
     */
    public function plan(?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $tables = [];

        foreach ($this->policy->detailTables() as $detail) {
            $cutoff = $this->firstKeptDay($detail->keepDays, $now);
            $watermark = RetentionWatermark::find($detail->table);
            $from = $watermark !== null ? $this->dayStart($watermark->through_day->toDateString())->addDay() : null;

            $base = fn () => DB::table("{$detail->table} as t")
                ->where('t.created_at', '<', $cutoff->utc())
                ->when($from !== null, fn ($q) => $q->where('t.created_at', '>=', $from->utc()));

            $tables[$detail->table] = [
                'keep_days' => $detail->keepDays,
                'eligible_before' => $cutoff->toDateString(),
                'watermark' => $watermark?->through_day->toDateString(),
                'eligible_rows' => $base()->whereRaw("NOT ({$detail->keepCondition})")->count(),
                'kept_forever_rows' => $base()->whereRaw("({$detail->keepCondition})")->count(),
            ];
        }

        $eventCutoff = $this->firstKeptDay($this->policy->eventPayloadKeepDays(), $now);

        return [
            'policy' => $this->policy->snapshot(),
            'tables' => $tables,
            'event_payloads' => [
                'keep_days' => $this->policy->eventPayloadKeepDays(),
                'eligible_before' => $eventCutoff->toDateString(),
                'eligible_rows' => $this->retainableEvents($eventCutoff)->count(),
            ],
            'workflows' => $this->workflows->plan($this->firstKeptDay($this->policy->workflowKeepDays(), $now)),
            'follow_ups' => $this->followUps->plan($this->firstKeptDay($this->policy->followUpKeepDays(), $now)),
        ];
    }

    /** One pass. Refuses to start while another pass holds the lock. */
    public function run(?CarbonImmutable $now = null): RetentionRun
    {
        // UTC: bounds are bound as zone-less strings, and `ai_runs` keeps zone-less UTC times.
        $now = ($now ?? CarbonImmutable::now())->utc();
        $lock = Cache::lock(self::LOCK, $this->policy->maxSeconds() + 120);

        if (! $lock->get()) {
            throw new RuntimeException('Another retention pass is running; this one did not start.');
        }

        try {
            $this->abandonStale($now);

            $run = RetentionRun::create([
                'uuid' => (string) Str::uuid(),
                'policy_version' => $this->policy->version(),
                'policy' => $this->policy->snapshot(),
                'status' => RetentionRun::RUNNING,
                'started_at' => $now,
            ]);

            $deadline = microtime(true) + $this->policy->maxSeconds();
            $summary = ['tables' => [], 'event_payloads' => null, 'workflows' => null, 'follow_ups' => null];

            try {
                foreach ($this->policy->detailTables() as $detail) {
                    $summary['tables'][$detail->table] = $this->drain($detail, $run, $now, $deadline);
                }
                $summary['event_payloads'] = $this->compactEvents($run, $now, $deadline);
                $summary['workflows'] = $this->workflows->prune($run, $this->firstKeptDay($this->policy->workflowKeepDays(), $now), $deadline);
                $summary['follow_ups'] = $this->followUps->prune($this->firstKeptDay($this->policy->followUpKeepDays(), $now), $deadline);
            } catch (Throwable $e) {
                $run->forceFill([
                    'status' => RetentionRun::FAILED,
                    'summary' => $summary,
                    'error' => get_class($e).': '.Str::limit($e->getMessage(), 2000),
                    'finished_at' => CarbonImmutable::now(),
                ])->save();
                $this->auditor->recordSystem('retention.failed', $run, after: [
                    'uuid' => $run->uuid, 'policy_version' => $run->policy_version, 'summary' => $summary, 'error' => get_class($e),
                ]);

                throw $e;
            }

            $run->forceFill([
                'status' => RetentionRun::SUCCEEDED,
                'summary' => $summary,
                'finished_at' => CarbonImmutable::now(),
            ])->save();

            $this->auditor->recordSystem('retention.completed', $run, after: [
                'uuid' => $run->uuid,
                'policy_version' => $run->policy_version,
                'summary' => $summary,
            ]);

            return $run;
        } finally {
            $lock->release();
        }
    }

    /**
     * Drain one table day by day from its watermark to its cutoff, within the pass's bounds.
     *
     * @return array<string, mixed>
     */
    private function drain(DetailTable $detail, RetentionRun $run, CarbonImmutable $now, float $deadline): array
    {
        $firstKept = $this->firstKeptDay($detail->keepDays, $now);
        $watermark = RetentionWatermark::find($detail->table);
        $result = [
            'keep_days' => $detail->keepDays,
            'eligible_before' => $firstKept->toDateString(),
            'watermark_before' => $watermark?->through_day->toDateString(),
            'watermark_after' => $watermark?->through_day->toDateString(),
            'days_drained' => 0,
            'rows_aggregated_and_deleted' => 0,
            'min_source_id' => null,
            'max_source_id' => null,
            'stopped_by' => null,
        ];

        if ($watermark !== null) {
            $day = $this->dayStart($watermark->through_day->toDateString())->addDay();
        } else {
            $oldest = DB::table($detail->table)->where('created_at', '<', $firstKept->utc())->min('created_at');
            if ($oldest === null) {
                return $result;
            }
            $day = CarbonImmutable::parse($oldest)->setTimezone($this->policy->timezone())->startOfDay();
        }

        $maxRows = $this->policy->maxRowsPerTable();

        while ($day->lt($firstKept)) {
            if ($result['days_drained'] >= $this->policy->maxDaysPerTable()) {
                $result['stopped_by'] = 'max_days';
                break;
            }

            $drained = false;
            while (true) {
                $room = $maxRows - $result['rows_aggregated_and_deleted'];
                if ($room <= 0) {
                    $result['stopped_by'] = 'max_rows';
                    break 2;
                }
                if (microtime(true) >= $deadline) {
                    $result['stopped_by'] = 'max_seconds';
                    break 2;
                }

                $limit = min($this->policy->batchSize(), $room);
                [$count, $minId, $maxId] = $this->chunk($detail, $run, $day, $limit);

                if ($count > 0) {
                    $result['rows_aggregated_and_deleted'] += $count;
                    $result['min_source_id'] = $result['min_source_id'] === null ? $minId : min($result['min_source_id'], $minId);
                    $result['max_source_id'] = $result['max_source_id'] === null ? $maxId : max($result['max_source_id'], $maxId);
                }

                if ($count < $limit) {
                    $drained = true;
                    break;
                }
            }

            if ($drained) {
                RetentionWatermark::updateOrCreate(
                    ['source_table' => $detail->table],
                    ['through_day' => $day->toDateString(), 'retention_run_id' => $run->id],
                );
                $result['days_drained']++;
                $result['watermark_after'] = $day->toDateString();
            }

            $day = $day->addDay();
        }

        return $result;
    }

    /**
     * Roll up and delete at most `$limit` eligible rows of one day, in one transaction.
     *
     * @return array{0: int, 1: int|null, 2: int|null}
     */
    private function chunk(DetailTable $detail, RetentionRun $run, CarbonImmutable $day, int $limit): array
    {
        return DB::transaction(function () use ($detail, $run, $day, $limit) {
            $ids = DB::table("{$detail->table} as t")
                ->where('t.created_at', '>=', $day->utc())
                ->where('t.created_at', '<', $day->addDay()->utc())
                ->whereRaw("NOT ({$detail->keepCondition})")
                ->orderBy('t.id')
                ->limit($limit)
                ->lockForUpdate()
                ->pluck('t.id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($ids === []) {
                return [0, null, null];
            }

            $idArray = '{'.implode(',', $ids).'}';

            DB::insert(
                <<<SQL
                INSERT INTO daily_aggregates (day, source_table, dimension, dimension_hash, rows, sum_cost_usd, sum_duration_ms,
                    sum_tokens_in, sum_tokens_out, measures, min_source_id, max_source_id, retention_run_id, created_at, updated_at)
                SELECT ?::date, ?, g.dimension, encode(sha256(convert_to(g.dimension::text, 'UTF8')), 'hex'), g.row_count, g.cost,
                    g.duration, g.tokens_in, g.tokens_out, g.measures, g.min_id, g.max_id, ?, now(), now()
                FROM (
                    SELECT {$detail->dimensionSql()} AS dimension,
                        count(*) AS row_count,
                        {$detail->costSql()} AS cost,
                        {$detail->sumSql($detail->durationColumn)} AS duration,
                        {$detail->sumSql($detail->tokensInColumn)} AS tokens_in,
                        {$detail->sumSql($detail->tokensOutColumn)} AS tokens_out,
                        {$detail->measuresSql()} AS measures,
                        min(t.id) AS min_id,
                        max(t.id) AS max_id
                    FROM {$detail->table} t
                    WHERE t.id = ANY(?::bigint[])
                    GROUP BY 1
                ) g
                ON CONFLICT (day, source_table, dimension_hash, retention_run_id) DO UPDATE SET
                    rows = daily_aggregates.rows + EXCLUDED.rows,
                    sum_cost_usd = CASE WHEN EXCLUDED.sum_cost_usd IS NULL THEN daily_aggregates.sum_cost_usd
                        ELSE COALESCE(daily_aggregates.sum_cost_usd, 0) + EXCLUDED.sum_cost_usd END,
                    sum_duration_ms = CASE WHEN EXCLUDED.sum_duration_ms IS NULL THEN daily_aggregates.sum_duration_ms
                        ELSE COALESCE(daily_aggregates.sum_duration_ms, 0) + EXCLUDED.sum_duration_ms END,
                    sum_tokens_in = CASE WHEN EXCLUDED.sum_tokens_in IS NULL THEN daily_aggregates.sum_tokens_in
                        ELSE COALESCE(daily_aggregates.sum_tokens_in, 0) + EXCLUDED.sum_tokens_in END,
                    sum_tokens_out = CASE WHEN EXCLUDED.sum_tokens_out IS NULL THEN daily_aggregates.sum_tokens_out
                        ELSE COALESCE(daily_aggregates.sum_tokens_out, 0) + EXCLUDED.sum_tokens_out END,
                    measures = CASE
                        WHEN daily_aggregates.measures IS NULL THEN EXCLUDED.measures
                        WHEN EXCLUDED.measures IS NULL THEN daily_aggregates.measures
                        ELSE (
                            SELECT jsonb_object_agg(k, COALESCE((daily_aggregates.measures ->> k)::numeric, 0) + COALESCE((EXCLUDED.measures ->> k)::numeric, 0))
                            FROM jsonb_object_keys(daily_aggregates.measures || EXCLUDED.measures) AS k
                        )
                    END,
                    min_source_id = LEAST(daily_aggregates.min_source_id, EXCLUDED.min_source_id),
                    max_source_id = GREATEST(daily_aggregates.max_source_id, EXCLUDED.max_source_id),
                    updated_at = now()
                SQL,
                [$day->toDateString(), $detail->table, $run->id, $idArray],
            );

            $deleted = DB::table($detail->table)->whereIn('id', $ids)->delete();

            if ($deleted !== count($ids)) {
                // Rolls the aggregate back with it: a count that does not match what was deleted is
                // a lineage that lies, and none is better.
                throw new RuntimeException("Retention on {$detail->table} rolled up ".count($ids)." rows but deleted {$deleted}.");
            }

            return [$deleted, min($ids), max($ids)];
        });
    }

    /**
     * Replace the payload of processed events older than the payload window with a digest.
     *
     * @return array<string, mixed>
     */
    private function compactEvents(RetentionRun $run, CarbonImmutable $now, float $deadline): array
    {
        $cutoff = $this->firstKeptDay($this->policy->eventPayloadKeepDays(), $now);
        $result = [
            'keep_days' => $this->policy->eventPayloadKeepDays(),
            'eligible_before' => $cutoff->toDateString(),
            'compacted' => 0,
            'min_event_id' => null,
            'max_event_id' => null,
            'stopped_by' => null,
        ];
        $maxRows = $this->policy->maxRowsPerTable();

        while (true) {
            $room = $maxRows - $result['compacted'];
            if ($room <= 0) {
                $result['stopped_by'] = 'max_rows';
                break;
            }
            if (microtime(true) >= $deadline) {
                $result['stopped_by'] = 'max_seconds';
                break;
            }

            $limit = min($this->policy->batchSize(), $room);

            $ids = DB::transaction(function () use ($cutoff, $limit, $run, $now) {
                $ids = $this->retainableEvents($cutoff)
                    ->orderBy('id')
                    ->limit($limit)
                    ->lockForUpdate()
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                if ($ids === []) {
                    return [];
                }

                $updated = DB::update(
                    <<<'SQL'
                    UPDATE events SET payload = jsonb_build_object('_retention', jsonb_build_object(
                        'policy', ?::text,
                        'run', ?::text,
                        'compacted_at', ?::text,
                        'sha256', encode(sha256(convert_to(payload::text, 'UTF8')), 'hex'),
                        'bytes', octet_length(payload::text),
                        'keys', (
                            SELECT COALESCE(jsonb_agg(k ORDER BY k COLLATE "C"), '[]'::jsonb)
                            FROM jsonb_object_keys(CASE WHEN jsonb_typeof(payload) = 'object' THEN payload ELSE '{}'::jsonb END) AS k
                        ),
                        'store', CASE WHEN jsonb_typeof(payload) = 'object' THEN payload -> '_store' END,
                        'correlation_id', CASE WHEN jsonb_typeof(payload) = 'object' THEN payload -> '_correlation_id' END
                    ))
                    WHERE id = ANY(?::bigint[]) AND status = 'processed' AND NOT jsonb_exists(payload, '_retention')
                    SQL,
                    [$this->policy->version(), $run->uuid, $now->toIso8601String(), '{'.implode(',', $ids).'}'],
                );

                if ($updated !== count($ids)) {
                    throw new RuntimeException('Retention selected '.count($ids)." event payloads but compacted {$updated}.");
                }

                return $ids;
            });

            if ($ids === []) {
                break;
            }

            $result['compacted'] += count($ids);
            $result['min_event_id'] = $result['min_event_id'] === null ? min($ids) : min($result['min_event_id'], min($ids));
            $result['max_event_id'] = $result['max_event_id'] === null ? max($ids) : max($result['max_event_id'], max($ids));

            if (count($ids) < $limit) {
                break;
            }
        }

        return $result;
    }

    private function retainableEvents(CarbonImmutable $cutoff)
    {
        return DB::table('events')
            ->where('status', 'processed')
            ->where('created_at', '<', $cutoff->utc())
            ->whereRaw("NOT jsonb_exists(payload, '_retention')");
    }

    /** A pass left `running` well past its own time limit died with its process. */
    private function abandonStale(CarbonImmutable $now): void
    {
        RetentionRun::query()
            ->where('status', RetentionRun::RUNNING)
            ->where('started_at', '<', $now->subSeconds($this->policy->maxSeconds() * 2))
            ->update(['status' => RetentionRun::ABANDONED, 'finished_at' => $now, 'error' => 'the process ended without finishing the pass']);
    }

    /** The first Dhaka calendar day a table keeps in full; every earlier day is eligible. */
    private function firstKeptDay(int $keepDays, CarbonImmutable $now): CarbonImmutable
    {
        return $now->setTimezone($this->policy->timezone())->startOfDay()->subDays($keepDays);
    }

    private function dayStart(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, $this->policy->timezone())->startOfDay();
    }
}
