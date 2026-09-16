<?php

namespace App\Console\Commands;

use App\Observe\Retention\RetentionRunner;
use Illuminate\Console\Command;

/**
 * What the next retention pass would do (docs/78 B.2). Reads only: no run row, no audit row, no change.
 */
class RetentionPlanCommand extends Command
{
    protected $signature = 'hq:retention:plan';

    protected $description = 'Show what the next retention pass would roll up, remove and keep. Writes nothing.';

    public function handle(RetentionRunner $runner): int
    {
        $plan = $runner->plan();

        $this->line("Policy {$plan['policy']['version']}, days in {$plan['policy']['timezone']}.");
        $this->table(
            ['table', 'keep days', 'eligible before', 'watermark', 'would roll up and remove', 'kept forever'],
            collect($plan['tables'])->map(fn (array $t, string $table) => [
                $table, $t['keep_days'], $t['eligible_before'], $t['watermark'] ?? '-', $t['eligible_rows'], $t['kept_forever_rows'],
            ])->values()->all(),
        );

        $events = $plan['event_payloads'];
        $this->line("events.payload: keep {$events['keep_days']} days; {$events['eligible_rows']} processed event(s) created before {$events['eligible_before']} would keep only a digest.");
        $this->line('Kept forever: rows an agent, approval, evaluation result, conversation message or consent event refers to, rows sharing an agent run\'s correlation id, and rows that never finished.');

        return self::SUCCESS;
    }
}
