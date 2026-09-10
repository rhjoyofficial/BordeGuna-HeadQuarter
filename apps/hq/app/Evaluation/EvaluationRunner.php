<?php

namespace App\Evaluation;

use App\Models\EvaluationCase;
use App\Models\EvaluationResult;
use App\Models\EvaluationRun;
use App\Models\EvaluationSuite;
use App\Support\Audit\Auditor;
use Illuminate\Support\Str;

/**
 * Runs a suite and records the evidence (ADR-023).
 *
 * ⭐ **Agreement rate is the output that matters.** `autonomy_grants.min_agreement_rate` reads it,
 * so this class is the mechanical link between "we measured" and "it may act". Everything else here
 * exists to make that number attributable: the version stamps say what was in force, and the
 * comparison against the previous run says what changed.
 *
 * ⛔ **A blocker failure fails the run regardless of the rate.** A safety corpus where 39 of 40
 * cases pass is not 97.5% safe; it is one working injection away from a customer, and averaging it
 * into a score is how that gets shipped.
 */
final readonly class EvaluationRunner
{
    /** @param  array<string, Grader>  $graders */
    public function __construct(private Auditor $auditor, private array $graders = []) {}

    public function withGrader(Grader $grader): self
    {
        return new self($this->auditor, [...$this->graders, $grader->key() => $grader]);
    }

    /**
     * @param  array<string, mixed>  $stamps  model, model_tier, prompt_version, skill_version,
     *                                        policy_version, autonomy_level, agent_key,
     *                                        business_id, channel, triggered_by
     */
    public function run(EvaluationSuite $suite, EvaluationSubject $subject, array $stamps = []): EvaluationRun
    {
        $cases = $suite->cases()->where('is_active', true)->orderBy('id')->get();

        $run = EvaluationRun::create([
            'uuid' => (string) Str::uuid(),
            'suite_id' => $suite->id,
            'model' => $stamps['model'] ?? null,
            'model_tier' => $stamps['model_tier'] ?? null,
            'prompt_version' => $stamps['prompt_version'] ?? null,
            'skill_version' => $stamps['skill_version'] ?? null,
            'policy_version' => $stamps['policy_version'] ?? null,
            'autonomy_level' => $stamps['autonomy_level'] ?? null,
            'agent_key' => $stamps['agent_key'] ?? $subject->subjectKey(),
            'business_id' => $stamps['business_id'] ?? null,
            'channel' => $stamps['channel'] ?? null,
            'status' => 'running',
            'cases_total' => $cases->count(),
            'triggered_by' => $stamps['triggered_by'] ?? 'manual',
            'triggered_by_user_id' => $stamps['triggered_by_user_id'] ?? null,
            'started_at' => now(),
            'compared_to_run_id' => $this->previousRunId($suite, $stamps),
        ]);

        $passed = 0;
        $failed = 0;
        $blockerFailed = false;
        $byDimension = [];

        foreach ($cases as $case) {
            $observation = $subject->run($case);
            $grader = $this->graderFor($case);
            $result = $grader->grade($case, $observation);

            // Denormalised from the case at write time (ADR-030). A result must stay readable as
            // evidence after its case is re-tagged, or the stored history describes a run that
            // never happened.
            $dimension = (string) ($case->dimension ?: EvaluationDimension::ToolSelection->value);

            EvaluationResult::create([
                'run_id' => $run->id,
                'case_id' => $case->id,
                'passed' => $result->passed,
                'score' => $result->score,
                'grader' => $grader->key(),
                'dimension' => $dimension,
                'expected' => $result->expected,
                'actual' => $result->actual,
                'reason' => $result->reason,
                'latency_ms' => $observation->latencyMs,
                'ai_run_id' => $observation->aiRunId,
                'context_provenance' => $observation->contextProvenance,
            ]);

            $byDimension[$dimension]['passed'] = ($byDimension[$dimension]['passed'] ?? 0) + ($result->passed ? 1 : 0);
            $byDimension[$dimension]['failed'] = ($byDimension[$dimension]['failed'] ?? 0) + ($result->passed ? 0 : 1);

            if ($result->passed) {
                $passed++;

                continue;
            }

            $failed++;
            if ($case->isBlocker()) {
                $blockerFailed = true;
            }
        }

        $total = max(1, $cases->count());
        $rate = round($passed / $total, 3);
        $threshold = $suite->pass_threshold;

        $status = match (true) {
            $blockerFailed => 'failed',
            $threshold !== null => $rate >= $threshold ? 'passed' : 'failed',
            default => $failed === 0 ? 'passed' : 'failed',
        };

        $run->forceFill([
            'cases_passed' => $passed,
            'cases_failed' => $failed,
            'agreement_rate' => $rate,
            // ⭐ The per-dimension evidence an autonomy grant can require (ADR-030). The overall
            // rate is kept because it is still the right number for "did this whole suite pass";
            // it is simply no longer the only number a widening decision may rest on.
            'dimension_rates' => $this->dimensionRates($byDimension),
            'status' => $status,
            'finished_at' => now(),
            'regressions' => $this->regressions($run),
        ])->save();

        $this->auditor->record('evaluation.run_completed', $run, after: [
            'suite' => $suite->key, 'status' => $status, 'agreement_rate' => $rate,
            'passed' => $passed, 'failed' => $failed, 'blocker_failed' => $blockerFailed,
        ]);

        return $run->refresh();
    }

    /**
     * @param  array<string, array{passed?: int, failed?: int}>  $byDimension
     * @return array<string, array{passed: int, failed: int, rate: float}>
     */
    private function dimensionRates(array $byDimension): array
    {
        $out = [];

        foreach ($byDimension as $dimension => $counts) {
            $p = (int) ($counts['passed'] ?? 0);
            $f = (int) ($counts['failed'] ?? 0);
            $total = max(1, $p + $f);
            $out[$dimension] = ['passed' => $p, 'failed' => $f, 'rate' => round($p / $total, 3)];
        }

        ksort($out);

        return $out;
    }

    /**
     * Cases that passed in the comparison run and fail now.
     *
     * A regression is not "the number went down". It is a NAMED case that used to pass, which is
     * the only form of the finding somebody can act on.
     *
     * @return list<string>|null
     */
    private function regressions(EvaluationRun $run): ?array
    {
        if ($run->compared_to_run_id === null) {
            return null;
        }

        $previousPassed = EvaluationResult::query()
            ->where('run_id', $run->compared_to_run_id)
            ->where('passed', true)
            ->pluck('case_id')
            ->all();

        if ($previousPassed === []) {
            return null;
        }

        $nowFailing = EvaluationResult::query()
            ->where('run_id', $run->id)
            ->where('passed', false)
            ->whereIn('case_id', $previousPassed)
            ->pluck('case_id')
            ->all();

        if ($nowFailing === []) {
            return null;
        }

        return EvaluationCase::whereIn('id', $nowFailing)->orderBy('id')->pluck('key')->all();
    }

    private function previousRunId(EvaluationSuite $suite, array $stamps): ?int
    {
        return EvaluationRun::query()
            ->where('suite_id', $suite->id)
            ->whereIn('status', ['passed', 'failed'])
            ->when(isset($stamps['agent_key']), fn ($q) => $q->where('agent_key', $stamps['agent_key']))
            ->latest('id')
            ->value('id');
    }

    private function graderFor(EvaluationCase $case): Grader
    {
        return $this->graders[$case->grader]
            ?? $this->graders['deterministic']
            ?? new DeterministicGrader;
    }
}
