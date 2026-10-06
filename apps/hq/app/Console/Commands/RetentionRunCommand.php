<?php

namespace App\Console\Commands;

use App\Observe\Retention\RetentionRunner;
use Illuminate\Console\Command;
use Throwable;

/**
 * One retention pass (docs/78 B.2), nightly from the scheduler, after the 01:00 UTC backup. Bounded,
 * idempotent and recorded: a pass that reaches a bound says so, and the next one continues.
 */
class RetentionRunCommand extends Command
{
    protected $signature = 'hq:retention:run';

    protected $description = 'Apply the retention policy: roll old unattended detail up into daily aggregates, remove it, and reduce old event payloads to digests, and remove old finished, reconciled workflow runs.';

    public function handle(RetentionRunner $runner): int
    {
        try {
            $run = $runner->run();
        } catch (Throwable $e) {
            $this->error('Retention did not complete: '.get_class($e).': '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line("Pass {$run->uuid} ({$run->policy_version}) {$run->status}.");
        $this->table(
            ['table', 'removed', 'days drained', 'watermark', 'ids', 'stopped by'],
            collect($run->summary['tables'] ?? [])->map(fn (array $t, string $table) => [
                $table,
                $t['rows_aggregated_and_deleted'],
                $t['days_drained'],
                $t['watermark_after'] ?? '-',
                $t['min_source_id'] === null ? '-' : "{$t['min_source_id']}..{$t['max_source_id']}",
                $t['stopped_by'] ?? '-',
            ])->values()->all(),
        );

        $events = $run->summary['event_payloads'] ?? [];
        $this->line('events.payload: '.($events['compacted'] ?? 0).' reduced to a digest'.(($events['stopped_by'] ?? null) ? ", stopped by {$events['stopped_by']}" : '').'.');

        $wf = $run->summary['workflows'] ?? [];
        $this->line('workflows: '.($wf['runs_removed'] ?? 0).' finished run(s) removed'.(($wf['stopped_by'] ?? null) ? ", stopped by {$wf['stopped_by']}" : '').'.');

        return self::SUCCESS;
    }
}
