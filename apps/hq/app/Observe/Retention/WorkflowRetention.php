<?php

namespace App\Observe\Retention;

use App\Models\RetentionRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Ruling Q3c (ChatGPT, HQ thread, 6 Oct 2026; docs/101 section 12): retention of the workflow
 * engine's operational state (docs/102 section 3).
 *
 * A run is removed only when it is terminal (completed or cancelled), reconciled
 * (`RetentionPolicy::WORKFLOW_KEEP_CONDITION` does not hold) and finished before the first kept
 * Dhaka day of `hq.retention.workflow_keep_days`. Open, waiting and failed runs are never touched; a
 * failed run can still be retried. Its steps and signals go with it by the schema's cascade.
 *
 * ⛔ What a run points at is NOT removed here: its tool calls stay under the detail-table rules (and a
 * day already drained keeps them), its approvals and its audit rows are permanent, and the audit rows
 * carry the run's instance key and correlation id, so the history a person reads survives the run.
 * The only DELETE is on `workflow_instances`, by id, for ids selected under the rule above.
 */
final class WorkflowRetention
{
    public function __construct(private readonly RetentionPolicy $policy) {}

    /** @return array<string, mixed> */
    public function plan(CarbonImmutable $firstKept): array
    {
        return [
            'keep_days' => $this->policy->workflowKeepDays(),
            'eligible_before' => $firstKept->toDateString(),
            'eligible_runs' => $this->eligible($firstKept)->count(),
            'kept_unreconciled_runs' => $this->terminalBefore($firstKept)->whereRaw('('.RetentionPolicy::WORKFLOW_KEEP_CONDITION.')')->count(),
        ];
    }

    /**
     * Remove eligible runs in bounded transactions.
     *
     * @return array<string, mixed>
     */
    public function prune(RetentionRun $run, CarbonImmutable $firstKept, float $deadline): array
    {
        $result = [
            'keep_days' => $this->policy->workflowKeepDays(),
            'eligible_before' => $firstKept->toDateString(),
            'runs_removed' => 0,
            'min_instance_id' => null,
            'max_instance_id' => null,
            'stopped_by' => null,
        ];
        $maxRows = $this->policy->maxRowsPerTable();

        while (true) {
            $room = $maxRows - $result['runs_removed'];
            if ($room <= 0) {
                $result['stopped_by'] = 'max_rows';
                break;
            }
            if (microtime(true) >= $deadline) {
                $result['stopped_by'] = 'max_seconds';
                break;
            }

            $limit = min($this->policy->batchSize(), $room);

            $ids = DB::transaction(function () use ($firstKept, $limit) {
                $ids = $this->eligible($firstKept)
                    ->orderBy('w.id')
                    ->limit($limit)
                    ->lockForUpdate()
                    ->pluck('w.id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                if ($ids === []) {
                    return [];
                }

                $deleted = DB::table('workflow_instances')->whereIn('id', $ids)->delete();

                if ($deleted !== count($ids)) {
                    throw new RuntimeException('Workflow retention selected '.count($ids)." runs but removed {$deleted}.");
                }

                return $ids;
            });

            if ($ids === []) {
                break;
            }

            $result['runs_removed'] += count($ids);
            $result['min_instance_id'] = $result['min_instance_id'] === null ? min($ids) : min($result['min_instance_id'], min($ids));
            $result['max_instance_id'] = $result['max_instance_id'] === null ? max($ids) : max($result['max_instance_id'], max($ids));

            if (count($ids) < $limit) {
                break;
            }
        }

        return $result;
    }

    private function eligible(CarbonImmutable $firstKept): Builder
    {
        return $this->terminalBefore($firstKept)->whereRaw('NOT ('.RetentionPolicy::WORKFLOW_KEEP_CONDITION.')');
    }

    private function terminalBefore(CarbonImmutable $firstKept): Builder
    {
        return DB::table('workflow_instances as w')
            ->whereIn('w.status', RetentionPolicy::WORKFLOW_TERMINAL)
            ->whereNotNull('w.finished_at')
            ->where('w.finished_at', '<', $firstKept->utc());
    }
}
