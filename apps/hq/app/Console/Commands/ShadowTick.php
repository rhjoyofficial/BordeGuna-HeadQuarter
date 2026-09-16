<?php

namespace App\Console\Commands;

use App\Agents\Shadow\ShadowEvidence;
use App\Agents\Shadow\ShadowPeriods;
use App\Agents\Shadow\ShadowProbes;
use App\Models\Agent;
use App\Models\ShadowPeriod;
use App\Support\Features\Features;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * What an open shadow period does twice a day: its Shadow Probes (docs/77 C.2.6 and E.1).
 *
 * In order, for every open period: close it if its seven days have passed; check the A0 safety
 * conditions and stop it if one broke; otherwise put each of the period's probes to the agent once
 * for this slot, as a `shadow_probe` run through the ordinary runner; then check again.
 *
 * ⛔ It never switches anything ON. If the runtime is off or the agent paused while the period is
 * open, every probe of the slot is recorded as skipped, with the reason, and nothing is asked. The
 * probes are the ones stored on the period when it opened; nothing here chooses a question, a tool
 * or a scope.
 */
class ShadowTick extends Command
{
    protected $signature = 'hq:shadow:tick
        {--slot= : morning or evening; derived from the Dhaka hour when omitted}';

    protected $description = 'Ask an open shadow period\'s Shadow Probes for this slot, after the safety check; close it when its days are over.';

    public function handle(ShadowPeriods $periods, ShadowEvidence $evidence, ShadowProbes $probes, Features $features): int
    {
        $now = CarbonImmutable::now();
        $local = $now->setTimezone(ShadowEvidence::TIMEZONE);
        $slot = (string) ($this->option('slot') ?: ($local->hour < 13 ? 'morning' : 'evening'));
        if (! array_key_exists($slot, ShadowProbes::SLOTS)) {
            $this->error("Unknown slot [{$slot}]; use morning or evening.");

            return self::FAILURE;
        }

        foreach (ShadowPeriod::query()->where('status', ShadowPeriod::STATUS_OPEN)->orderBy('id')->get() as $period) {
            if ($evidence->closeIfOver($period, $periods, $now)) {
                $this->info("period #{$period->id}: seven days over, closed.");

                continue;
            }

            if ($this->stopOnViolation($period, $periods, $evidence)) {
                continue;
            }

            if ($period->probes() === []) {
                $this->line("period #{$period->id}: no Shadow Probes on this period; nothing to ask.");

                continue;
            }

            $features->forget();
            $agent = Agent::query()->find($period->agent_id);
            $canRun = $features->enabled(ShadowPeriods::RUNTIME_FLAG) && $agent !== null && $agent->isActive();
            $rows = $probes->ask($period, $slot, $local->startOfDay(), $canRun,
                $canRun ? '' : 'the runtime is off or the agent is not active; the tick switches nothing on');

            foreach ($rows as $row) {
                $this->line(sprintf('period #%d %s %s: %s%s%s', $period->id, $slot, $row->probe_key, $row->status,
                    $row->agent_run_id ? " run #{$row->agent_run_id} {$row->verdict}" : '',
                    $row->task_matched === false ? ' (TASK CHANGED)' : ''));
            }
            if (! $canRun) {
                $this->warn("period #{$period->id}: open, but the runtime is off or the agent is not active; nothing asked, the slot is recorded as skipped.");
            }

            $this->stopOnViolation($period->fresh(), $periods, $evidence);
        }

        return self::SUCCESS;
    }

    private function stopOnViolation(ShadowPeriod $period, ShadowPeriods $periods, ShadowEvidence $evidence): bool
    {
        if (! $period->isOpen()) {
            return true;
        }
        [$from, $to] = $evidence->today($period);
        $violations = $evidence->violations($period, $from, $to);
        if ($violations === []) {
            return false;
        }

        $periods->stop($period, $violations, 'An A0 safety condition broke: '.implode('; ', array_map(fn ($v) => "{$v['condition']}: {$v['detail']}", $violations)));
        $this->error("period #{$period->id} STOPPED: ".count($violations).' violation(s).');

        return true;
    }
}
