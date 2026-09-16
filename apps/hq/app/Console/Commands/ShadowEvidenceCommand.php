<?php

namespace App\Console\Commands;

use App\Agents\Shadow\ShadowEvidence;
use App\Agents\Shadow\ShadowPeriods;
use App\Models\ShadowPeriod;
use Illuminate\Console\Command;

/**
 * A shadow period's evidence (docs/77 C.2.4-5).
 *
 * - no option: today's numbers so far for every open period, printed, nothing stored;
 * - `--store`: snapshot every complete day not stored yet (the scheduler runs this after midnight);
 * - `--check`: the A0 safety conditions for today so far; any violation STOPS the period at once
 *   (the scheduler runs this every hour, and `hq:shadow:tick` runs it before asking anything).
 *
 * `--period=` reads one period, open or not, instead of every open one.
 */
class ShadowEvidenceCommand extends Command
{
    protected $signature = 'hq:shadow:evidence
        {--period= : one period id instead of every open period}
        {--store : write the complete days not stored yet}
        {--check : check the A0 safety conditions and stop a period that broke one}
        {--json : print JSON}';

    protected $description = 'Print, store, or safety-check the evidence of open shadow periods.';

    public function handle(ShadowEvidence $evidence, ShadowPeriods $periods): int
    {
        $list = ($id = $this->option('period')) !== null
            ? ShadowPeriod::query()->whereKey($id)->get()
            : ShadowPeriod::query()->where('status', ShadowPeriod::STATUS_OPEN)->orderBy('id')->get();

        if ($list->isEmpty()) {
            $this->line('No shadow period to read.');

            return self::SUCCESS;
        }

        $status = self::SUCCESS;
        foreach ($list as $period) {
            if ($this->option('store')) {
                $rows = $evidence->store($period);
                $this->line("period #{$period->id}: ".count($rows).' day row(s) written'
                    .($rows === [] ? '' : ' ('.implode(', ', array_map(fn ($r) => $r->day->toDateString(), $rows)).')'));

                continue;
            }

            [$from, $to] = $evidence->today($period);
            $violations = $period->isOpen() ? $evidence->violations($period, $from, $to) : ((array) $period->violations);

            if ($this->option('check')) {
                if ($violations !== [] && $period->isOpen()) {
                    $periods->stop($period, $violations, 'An A0 safety condition broke: '.implode('; ', array_map(fn ($v) => "{$v['condition']}: {$v['detail']}", $violations)));
                    $this->error("period #{$period->id} STOPPED: ".count($violations).' violation(s).');
                    $status = self::FAILURE;
                } else {
                    $this->line("period #{$period->id}: no violation.");
                }

                continue;
            }

            $out = ['period' => $period->id, 'agent' => $period->agent_key, 'status' => $period->status,
                'metrics' => $evidence->metrics($period, $from, $to), 'violations' => $violations];
            $this->line($this->option('json')
                ? json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : "period #{$period->id} {$period->agent_key} {$period->status}: ".$out['metrics']['runs']['total'].' run(s) today, '
                    .count($violations).' violation(s), $'.$out['metrics']['cost_usd'].', '.$out['metrics']['review']['unreviewed'].' unreviewed.');
        }

        return $status;
    }
}
