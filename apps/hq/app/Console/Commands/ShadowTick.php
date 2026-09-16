<?php

namespace App\Console\Commands;

use App\Agents\AgentRunner;
use App\Agents\AgentRunRequest;
use App\Agents\Shadow\ShadowEvidence;
use App\Agents\Shadow\ShadowPeriods;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\ShadowPeriod;
use App\Models\User;
use App\Support\Features\Features;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * What an open shadow period does twice a day (docs/77 C.2.6).
 *
 * In order, for every open period: close it if its seven days have passed; check the A0 safety
 * conditions and stop it if one broke; otherwise put each of the period's own questions to the agent
 * once for this slot, through the normal runner, as a scheduled operator question; then check again.
 *
 * ⛔ It never switches anything ON. If the runtime is off or the agent paused while the period is
 * open (a person paused it, or a gate's exit trap did), the tick says so and asks nothing. The
 * questions are the ones stored on the period when it opened; nothing here chooses a question, a tool
 * or a scope.
 */
class ShadowTick extends Command
{
    protected $signature = 'hq:shadow:tick
        {--slot= : morning or evening; derived from the Dhaka hour when omitted}';

    protected $description = 'Ask an open shadow period\'s questions for this slot, after the safety check; close it when its days are over.';

    public function handle(ShadowPeriods $periods, ShadowEvidence $evidence, AgentRunner $runner, Features $features): int
    {
        $now = CarbonImmutable::now();
        $local = $now->setTimezone(ShadowEvidence::TIMEZONE);
        $slot = (string) ($this->option('slot') ?: ($local->hour < 13 ? 'morning' : 'evening'));
        $slotStart = $local->startOfDay()->addHours($slot === 'morning' ? 0 : 13)->utc();

        foreach (ShadowPeriod::query()->where('status', ShadowPeriod::STATUS_OPEN)->orderBy('id')->get() as $period) {
            if ($now >= $period->planned_end_at) {
                $periods->close($period, 'The planned seven days have passed. The shadow period is evidence, not a promotion; the next autonomy decision is an explicit one.');
                $evidence->store($period->fresh());
                $this->info("period #{$period->id}: seven days over, closed.");

                continue;
            }

            if ($this->stopOnViolation($period, $periods, $evidence)) {
                continue;
            }

            $features->forget();
            $agent = Agent::query()->find($period->agent_id);
            if (! $features->enabled(ShadowPeriods::RUNTIME_FLAG) || $agent === null || ! $agent->isActive()) {
                $this->warn("period #{$period->id}: open, but the runtime is off or the agent is not active; nothing asked.");

                continue;
            }

            $operator = User::query()->find($period->opened_by_user_id ?? $agent->enabling_user_id);
            foreach ((array) $period->questions as $question) {
                $asked = AgentRun::query()
                    ->where('agent_key', $period->agent_key)->where('trigger', 'schedule')
                    ->where('question', $question)->where('created_at', '>=', $slotStart)
                    ->exists();
                if ($asked) {
                    continue;
                }

                try {
                    $run = $runner->run(new AgentRunRequest(
                        agentKey: $period->agent_key,
                        question: (string) $question,
                        trigger: 'schedule',
                        operator: $operator,
                    ));
                    $this->line("period #{$period->id} {$slot}: run #{$run->id} {$run->status} for \"{$question}\"");
                } catch (Throwable $e) {
                    $this->error("period #{$period->id} {$slot}: \"{$question}\" could not run: {$e->getMessage()}");
                }
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
