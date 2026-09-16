<?php

namespace App\Agents\Shadow;

use App\Agents\AgentRunner;
use App\Agents\AgentRunRequest;
use App\Models\AgentRun;
use App\Models\ShadowPeriod;
use App\Models\ShadowProbeRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Scheduled Shadow Probes (docs/77 E.1, ChatGPT's "Shadow probes and Gate 11 continuation").
 *
 * ⭐ **A probe is trusted workload, not authority.** Its trusted task is the exact operator-owned
 * question stored on the period when it opened. It runs through the ordinary runner, gateway and
 * autonomy plane as a run with trigger `shadow_probe`; nothing here chooses a tool, a scope or a
 * business, and nothing here can widen what the agent may do. After the run, the probe row records
 * whether the runtime's canonical task stayed exactly the question (ADR-041 makes it so; this is the
 * evidence that it did).
 *
 * ⭐ **Completeness is counted, not assumed.** A period that requires probes (every production
 * period) is complete only when every slot inside its window has, for every probe, a run that
 * reached a verdict with the task unchanged. A slot that could not be asked is written as skipped,
 * so the gap is on the record.
 */
final class ShadowProbes
{
    public const TRIGGER = 'shadow_probe';

    /** Slot name => the Dhaka hour it fires at. */
    public const SLOTS = ['morning' => 9, 'evening' => 17];

    /** The probes a period asks when its opener names none (docs/77 C.2.6, kept by ChatGPT). */
    public const DEFAULTS = [
        'attention_today' => 'What needs my attention today?',
        'group_yesterday' => 'How did the group do yesterday?',
        'data_freshness' => 'How current is the data you are working from?',
    ];

    public function __construct(private readonly AgentRunner $runner) {}

    /**
     * The probe list a period stores: the defaults, or the opener's own questions keyed in order.
     *
     * @param  list<string>  $questions
     * @return list<array{key: string, question: string}>
     */
    public static function define(array $questions): array
    {
        $questions = array_values(array_filter(array_map('trim', $questions)));
        if ($questions === []) {
            return array_map(fn ($key, $q) => ['key' => $key, 'question' => $q], array_keys(self::DEFAULTS), self::DEFAULTS);
        }

        return array_map(fn ($q, $i) => ['key' => 'custom_'.($i + 1), 'question' => $q], $questions, array_keys($questions));
    }

    /**
     * Ask every probe of the period for one slot, once. Returns the probe rows touched.
     *
     * @return list<ShadowProbeRun>
     */
    public function ask(ShadowPeriod $period, string $slot, CarbonImmutable $slotDate, bool $canRun, string $whyNot = ''): array
    {
        $out = [];
        $operator = User::query()->find($period->opened_by_user_id ?? $period->agent?->enabling_user_id);

        foreach ($period->probes() as $probe) {
            $row = ShadowProbeRun::query()->firstOrNew([
                'shadow_period_id' => $period->id,
                'slot_date' => $slotDate->toDateString(),
                'slot' => $slot,
                'probe_key' => $probe['key'],
            ]);
            if ($row->exists && $row->status === ShadowProbeRun::ASKED) {
                continue; // this slot already has its answer
            }
            $row->question = $probe['question'];

            if (! $canRun) {
                $row->forceFill(['status' => ShadowProbeRun::SKIPPED, 'note' => $whyNot])->save();
                $out[] = $row;

                continue;
            }

            try {
                $run = $this->runner->run(new AgentRunRequest(
                    agentKey: $period->agent_key,
                    question: $probe['question'],
                    trigger: self::TRIGGER,
                    operator: $operator,
                ));
                $row->forceFill([
                    'agent_run_id' => $run->id,
                    'status' => ShadowProbeRun::ASKED,
                    'verdict' => $run->status,
                    'task_matched' => trim((string) $run->canonical_task) === trim($probe['question']),
                    'note' => $run->stop_reason,
                ])->save();
            } catch (Throwable $e) {
                $row->forceFill(['status' => ShadowProbeRun::FAILED, 'note' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 500)])->save();
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Every slot instant inside [from, to), as [local date, slot name].
     *
     * @return list<array{0: CarbonImmutable, 1: string}>
     */
    public static function slotsBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        $day = $from->setTimezone(ShadowEvidence::TIMEZONE)->startOfDay();
        $last = $to->setTimezone(ShadowEvidence::TIMEZONE);

        while ($day <= $last) {
            foreach (self::SLOTS as $slot => $hour) {
                $at = $day->setTime($hour, 0);
                if ($at >= $from && $at < $to) {
                    $out[] = [$day, $slot];
                }
            }
            $day = $day->addDay();
        }

        return $out;
    }

    /**
     * What the period's probes amount to up to `$until`: expected, asked, evidence, and what is missing.
     *
     * @return array{required: bool, expected: int, asked: int, skipped: int, failed: int, evidence: int, task_mismatches: int, missing: list<string>, complete: bool|null}
     */
    public function summary(ShadowPeriod $period, CarbonImmutable $until): array
    {
        $end = min($until, $period->planned_end_at);
        $rows = ShadowProbeRun::query()->where('shadow_period_id', $period->id)->get()
            ->keyBy(fn (ShadowProbeRun $r) => $r->slot_date->toDateString().'/'.$r->slot.'/'.$r->probe_key);

        $expected = 0;
        $evidence = 0;
        $missing = [];
        foreach (self::slotsBetween($period->opened_at, $end) as [$date, $slot]) {
            foreach ($period->probes() as $probe) {
                $expected++;
                $key = $date->toDateString().'/'.$slot.'/'.$probe['key'];
                $row = $rows->get($key);
                if ($row !== null && $row->isEvidence()) {
                    $evidence++;
                } else {
                    $missing[] = $key.' ('.($row === null ? 'never asked' : $row->status.($row->verdict ? ', '.$row->verdict : '').($row->task_matched === false ? ', task changed' : '')).')';
                }
            }
        }

        return [
            'required' => (bool) $period->probes_required,
            'expected' => $expected,
            'asked' => $rows->where('status', ShadowProbeRun::ASKED)->count(),
            'skipped' => $rows->where('status', ShadowProbeRun::SKIPPED)->count(),
            'failed' => $rows->where('status', ShadowProbeRun::FAILED)->count(),
            'evidence' => $evidence,
            'task_mismatches' => $rows->where('task_matched', false)->count(),
            'missing' => array_slice($missing, 0, 50),
            // A period that does not require probes has no probe verdict to give.
            'complete' => $period->probes_required ? ($expected > 0 && $evidence === $expected) : null,
        ];
    }

    /**
     * One day's probe block for the daily evidence.
     *
     * @return array<string, mixed>
     */
    public function dayBlock(ShadowPeriod $period, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $dates = [];
        foreach (self::slotsBetween($from, $to) as [$date, $slot]) {
            $dates[$date->toDateString()][] = $slot;
        }
        $expected = array_sum(array_map('count', $dates)) * count($period->probes());

        $rows = ShadowProbeRun::query()->where('shadow_period_id', $period->id)
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)->get();

        return [
            'expected' => $expected,
            'asked' => $rows->where('status', ShadowProbeRun::ASKED)->count(),
            'skipped' => $rows->where('status', ShadowProbeRun::SKIPPED)->count(),
            'failed' => $rows->where('status', ShadowProbeRun::FAILED)->count(),
            'evidence' => $rows->filter(fn (ShadowProbeRun $r) => $r->isEvidence())->count(),
            'task_mismatches' => $rows->where('task_matched', false)->count(),
            'by_probe' => $rows->groupBy('probe_key')->map(fn ($g) => $g->countBy(fn ($r) => $r->verdict ?? $r->status)->sortKeys()->all())->sortKeys()->all(),
            'runs' => $rows->pluck('agent_run_id')->filter()->values()->all(),
        ];
    }

    /** Whether a run is a probe (for evidence that must tell probes from a person's own questions). */
    public static function isProbe(AgentRun $run): bool
    {
        return $run->trigger === self::TRIGGER;
    }
}
