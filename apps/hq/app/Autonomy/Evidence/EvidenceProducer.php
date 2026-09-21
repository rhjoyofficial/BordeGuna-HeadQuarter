<?php

namespace App\Autonomy\Evidence;

use App\Agents\PromptLibrary;
use App\Evaluation\EvaluationDimension;
use App\Gateway\Descriptor\AutonomyLevel;
use App\Models\Agent;
use App\Models\AutonomyEvidenceHardCheck;
use App\Models\AutonomyEvidencePolicy;
use App\Models\AutonomyEvidenceRequirement;
use App\Models\AutonomyGrant;
use App\Models\EvaluationCase;
use App\Models\EvaluationResult;
use App\Models\EvaluationRun;
use App\Models\EvaluationSuite;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The autonomy evidence producer (ADR-048, docs/81 C.4): the missing link between "we measured"
 * and "it may act".
 *
 * Until now `AgentRunner` asked the resolver with no measured rate at all, so a grant requiring
 * evidence could never match and ADR-023's link was unreachable. This class recounts the evidence
 * from `evaluation_results`, judges it against the ACTIVE autonomy evidence policy, and hands the
 * runner what the policy allows plus the rates a grant may compare with.
 *
 * ⛔ **It writes nothing.** No row, no cache entry, no audit line: it is queries and arithmetic, and
 * the autonomy evidence view (which is write-free and tested so) is built on the same code.
 *
 * ⛔ **It never decides alone.** What it produces is one more ceiling the resolver combines by
 * MINIMUM. With no matching grant the answer is still A0, and nothing here creates, edits or
 * promotes a grant (docs/81 point 13).
 *
 * ## The rules, every one of which fails closed
 *
 * 1. No active policy: `forRunner()` returns null and the resolver decides exactly as before.
 * 2. Levels are cumulative. A level is reached only when every level from A1 up to it names at
 *    least one requirement and ALL of its requirements and hard checks are met.
 * 3. A requirement is met only when the dimension ITSELF has measured results (never the overall
 *    figure, never an unmeasured result), enough of them, the pooled rate and every contributing
 *    suite's rate reach the required rate in whole thousandths, and every contributing run is recent
 *    enough, on the agent's CURRENT prompt stamp, passed its own gate, held zero unmeasured cases and
 *    recounts to what it stored; and every applicable suite declaring cases in the dimension has
 *    been run. A NULL field is "not decided" and is never met.
 * 4. A hard check is 100 percent or it has failed: zero `fail`, at least the required number of
 *    `pass`, on runs that are recent, on the current stamp and fully measured.
 * 5. Rates are handed to the grant comparison only for clean evidence, floored to thousandths.
 *
 * ## Cost, and why there is no cache
 *
 * One query for the active policy and its rows, one for a live grant, one for the suites, one
 * indexed latest-run lookup per applicable suite, one results query restricted to those run ids,
 * one grouped count of declared cases. History is never read. More result rows than
 * `MAX_RESULT_ROWS` is refused rather than read: nothing is counted and nothing is met. No cache, so
 * there is no stale read to reason about: a finished run or an activation is seen by the next run.
 */
final class EvidenceProducer
{
    public const FINISHED = ['passed', 'failed'];

    /** The most result rows one measurement will read. Above it nothing is counted (fail closed). */
    public const MAX_RESULT_ROWS = 20000;

    public function __construct(private readonly PromptLibrary $prompts) {}

    public function measure(Agent $agent): MeasuredEvidence
    {
        $role = $agent->role_key ?? $agent->key;

        $suites = EvaluationSuite::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('role_key')->orWhere('role_key', $role))
            ->orderBy('key')
            ->get();

        $latest = [];
        foreach ($suites as $suite) {
            $run = EvaluationRun::query()
                ->where('suite_id', $suite->id)->where('agent_key', $agent->key)
                ->whereIn('status', self::FINISHED)
                ->orderByDesc('id')->first();

            if ($run !== null) {
                $latest[$suite->id] = $run;
            }
        }

        [$results, $overCeiling] = $this->resultsOf($latest, $role);

        return new MeasuredEvidence(
            agentKey: $agent->key,
            role: $role,
            stamp: $this->currentStamp($role),
            suites: $suites,
            latest: $latest,
            results: $results,
            declared: $this->declaredCases($suites, $role),
            overCeiling: $overCeiling,
        );
    }

    /**
     * The evidence against one policy version, level by level: every requirement and hard check
     * beside what was measured, met or the exact reasons, and the evidence ceiling.
     *
     * @return array<string, mixed>
     */
    public function judge(MeasuredEvidence $evidence, AutonomyEvidencePolicy $policy, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $policy->loadMissing(['requirements', 'hardChecks']);

        $levels = [];
        $reachable = true;
        $ceiling = AutonomyLevel::A0;
        $ceilingReasons = [];

        foreach (EvidencePolicies::LEVELS as $level) {
            $shown = strtoupper($level);
            $requirements = $policy->requirements->where('autonomy_level', $level)->values()
                ->map(fn (AutonomyEvidenceRequirement $r) => $this->requirement($evidence, $r, $now))->all();
            $checks = $policy->hardChecks->where('autonomy_level', $level)->values()
                ->map(fn (AutonomyEvidenceHardCheck $c) => $this->hardCheck($evidence, $c, $now))->all();

            $own = [];
            if ($requirements === []) {
                $own[] = "the policy names no requirement for {$shown}, so evidence cannot reach it";
            }
            foreach ($requirements as $row) {
                foreach ($row['met'] ? [] : $row['reasons'] as $reason) {
                    $own[] = "{$shown} {$row['label']}: {$reason}";
                }
            }
            foreach ($checks as $row) {
                foreach ($row['met'] ? [] : $row['reasons'] as $reason) {
                    $own[] = "{$shown} hard check {$row['check']}: {$reason}";
                }
            }

            $eligible = $reachable && $own === [];
            if ($eligible) {
                $ceiling = AutonomyLevel::from($level);
            } elseif ($reachable) {
                // The first level not reached is the one that bounds the answer.
                $ceilingReasons = $own;
            }

            $levels[] = [
                'level' => $level,
                'has_requirements' => $requirements !== [],
                'requirements' => $requirements,
                'hard_checks' => $checks,
                'own_met' => $own === [],
                'eligible' => $eligible,
                'reasons' => $reachable ? $own : ['levels are cumulative, and a lower level is not reached', ...$own],
            ];
            $reachable = $eligible;
        }

        return [
            'policy' => $this->policyRef($policy),
            'judged_at' => $now->toIso8601String(),
            'prompt_version' => $evidence->stamp,
            'run_ids' => $evidence->runIds(),
            'evidence_digest' => $evidence->digest(),
            'over_ceiling' => $evidence->overCeiling,
            'levels' => $levels,
            'ceiling' => $ceiling->value,
            'ceiling_reasons' => $ceilingReasons,
        ];
    }

    /**
     * What `AgentRunner` hands the resolver, or null when no policy is active.
     *
     * With an active policy and no live grant that could name this agent, the policy version is
     * recorded and no evidence is counted: without a grant the answer is A0 whatever the evidence
     * says, so reading it would only cost queries.
     */
    public function forRunner(Agent $agent, ?CarbonImmutable $now = null, ?MeasuredEvidence $measured = null): ?RunnerEvidence
    {
        $policy = AutonomyEvidencePolicy::query()->where('status', AutonomyEvidencePolicy::ACTIVE)->first();
        if ($policy === null) {
            return null;
        }

        $now = ($now ?? CarbonImmutable::now())->utc();
        $policy->load(['requirements', 'hardChecks']);

        $hasGrant = AutonomyGrant::query()
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->where(fn ($q) => $q->whereNull('agent_key')->orWhere('agent_key', $agent->key))
            ->exists();

        if (! $hasGrant) {
            return new RunnerEvidence(AutonomyLevel::A0, [
                'policy' => $this->policyRef($policy),
                'evidence_counted' => false,
                'reason' => 'no live autonomy grant names this agent, so no evidence was counted: without a grant the answer is A0 whatever the evidence says',
                'ceiling' => AutonomyLevel::A0->value,
                'judged_at' => $now->toIso8601String(),
            ]);
        }

        $evidence = $measured ?? $this->measure($agent);
        $verdict = $this->judge($evidence, $policy, $now);
        [$overall, $dimensions, $suites] = $this->cleanRates($evidence);

        return new RunnerEvidence(
            ceiling: AutonomyLevel::from($verdict['ceiling']),
            record: [
                'policy' => $verdict['policy'],
                'evidence_counted' => true,
                'evidence_digest' => $verdict['evidence_digest'],
                'run_ids' => $verdict['run_ids'],
                'prompt_version' => $evidence->stamp,
                'ceiling' => $verdict['ceiling'],
                'ceiling_reasons' => $verdict['ceiling_reasons'],
                'rates' => ['overall' => $overall, 'dimensions' => $dimensions, 'suites' => $suites],
                'judged_at' => $verdict['judged_at'],
            ],
            agreementRate: $overall,
            dimensionRates: $dimensions,
            suiteRates: $suites,
        );
    }

    /** @return array<string, mixed> */
    private function requirement(MeasuredEvidence $evidence, AutonomyEvidenceRequirement $requirement, CarbonImmutable $now): array
    {
        $dimension = $requirement->dimension;
        $label = EvaluationDimension::tryFrom($dimension)?->label() ?? $dimension;
        $required = $requirement->min_agreement_rate;
        $reasons = [];

        foreach ($requirement->undecidedFields() as $field) {
            $reasons[] = "{$field} is not decided (NULL), and an undecided requirement is never met";
        }
        if ($requirement->required_measured_state !== AutonomyEvidenceRequirement::FULLY_MEASURED) {
            $reasons[] = "required_measured_state {$requirement->required_measured_state} is not a known state";
        }
        if ($evidence->overCeiling) {
            $reasons[] = 'the evidence holds more than '.self::MAX_RESULT_ROWS.' result rows, so nothing was counted';
        }

        // ⛔ The dimension's own rows. The overall figure is never read here (ADR-030).
        $sources = $evidence->sources($dimension);
        $passed = array_sum(array_column($sources, 'passed'));
        $failed = array_sum(array_column($sources, 'failed'));
        $unmeasured = array_sum(array_column($sources, 'unmeasured'));
        $measured = $passed + $failed;

        if ($measured === 0) {
            $reasons[] = $unmeasured > 0
                ? "nothing was measured: all {$unmeasured} {$label} result(s) were refused by the model provider"
                : "never measured: no latest finished run holds a {$label} result for this agent";
        }

        foreach ($evidence->suites as $suite) {
            $cases = $evidence->declaredIn($suite, $dimension);
            if ($cases > 0 && ! isset($evidence->latest[$suite->id])) {
                $reasons[] = "suite {$suite->key} holds {$cases} {$label} case(s) for this agent and has never been run against it";
            }
        }

        if ($requirement->min_sample_count !== null && $measured > 0 && $measured < $requirement->min_sample_count) {
            $reasons[] = "{$measured} measured result(s), fewer than the {$requirement->min_sample_count} required";
        }

        if ($required !== null && $measured > 0) {
            if (! MeasuredEvidence::meets($passed, $failed, $required)) {
                $reasons[] = 'measured '.$this->shown(MeasuredEvidence::rate($passed, $failed))." ({$passed} of {$measured}), below the required ".$this->shown($required);
            }
            foreach ($sources as $source) {
                $n = $source['passed'] + $source['failed'];
                if ($n > 0 && ! MeasuredEvidence::meets($source['passed'], $source['failed'], $required)) {
                    $reasons[] = "run {$source['run_id']} of {$source['suite_key']} measured ".$this->shown($source['agreement_rate'])." ({$source['passed']} of {$n}), below the required ".$this->shown($required);
                }
            }
        }

        foreach ($sources as $source) {
            array_push($reasons, ...$this->runReasons($source, $evidence, $requirement->max_evidence_age_days, $now, withGateAndRecount: true));
        }

        $reasons = array_values(array_unique($reasons));

        return [
            'level' => $requirement->autonomy_level,
            'dimension' => $dimension,
            'label' => $label,
            'min_agreement_rate' => $required,
            'min_sample_count' => $requirement->min_sample_count,
            'max_evidence_age_days' => $requirement->max_evidence_age_days,
            'required_measured_state' => $requirement->required_measured_state,
            'undecided' => $requirement->undecidedFields(),
            'passed' => $passed,
            'failed' => $failed,
            'unmeasured' => $unmeasured,
            'evidence_count' => $measured,
            'agreement_rate' => MeasuredEvidence::rate($passed, $failed),
            'run_ids' => array_column($sources, 'run_id'),
            'met' => $reasons === [],
            'reasons' => $reasons,
        ];
    }

    /** @return array<string, mixed> */
    private function hardCheck(MeasuredEvidence $evidence, AutonomyEvidenceHardCheck $check, CarbonImmutable $now): array
    {
        $reasons = [];
        foreach ($check->undecidedFields() as $field) {
            $reasons[] = "{$field} is not decided (NULL), and an undecided hard check is never met";
        }
        if ($evidence->overCeiling) {
            $reasons[] = 'the evidence holds more than '.self::MAX_RESULT_ROWS.' result rows, so nothing was counted';
        }

        $sources = $evidence->checkSources($check->check_key);
        $passed = array_sum(array_column($sources, 'passed'));
        $failed = array_sum(array_column($sources, 'failed'));
        $failedIds = [];
        foreach ($sources as $source) {
            array_push($failedIds, ...$source['result_ids']['failed']);
        }

        if ($failed > 0) {
            $reasons[] = "failed in {$failed} measured result(s) (result ids ".implode(', ', $failedIds).'), and a hard check is 100 percent or it has failed';
        }
        if ($passed + $failed === 0) {
            $reasons[] = 'never exercised: no latest finished run holds a measured result where this check applied';
        } elseif ($check->min_sample_count !== null && $passed < $check->min_sample_count) {
            $reasons[] = "{$passed} passing result(s), fewer than the {$check->min_sample_count} required";
        }

        foreach ($sources as $source) {
            array_push($reasons, ...$this->runReasons($source, $evidence, $check->max_evidence_age_days, $now, withGateAndRecount: false));
        }

        $reasons = array_values(array_unique($reasons));

        return [
            'level' => $check->autonomy_level,
            'check' => $check->check_key,
            'min_sample_count' => $check->min_sample_count,
            'max_evidence_age_days' => $check->max_evidence_age_days,
            'undecided' => $check->undecidedFields(),
            'passed' => $passed,
            'failed' => $failed,
            'run_ids' => array_column($sources, 'run_id'),
            'failed_result_ids' => $failedIds,
            'met' => $reasons === [],
            'reasons' => $reasons,
        ];
    }

    /**
     * Why one contributing run cannot be leaned on, if it cannot.
     *
     * @param  array<string, mixed>  $source
     * @return list<string>
     */
    private function runReasons(array $source, MeasuredEvidence $evidence, ?int $maxAgeDays, CarbonImmutable $now, bool $withGateAndRecount): array
    {
        $reasons = [];
        $run = "run {$source['run_id']} of {$source['suite_key']}";

        if ($withGateAndRecount && $source['run_status'] !== 'passed') {
            $reasons[] = "{$run} failed its own gate ({$source['run_cases_passed']} of {$source['run_cases_total']})";
        }
        if ($source['run_cases_unmeasured'] > 0) {
            $reasons[] = "{$run} had {$source['run_cases_unmeasured']} unmeasured case(s), refused by the model provider";
        }
        if ($withGateAndRecount && ! ($source['stored_matches'] ?? false)) {
            $reasons[] = "the rate stored on {$run} does not match a recount of its results";
        }

        if ($evidence->stamp === null) {
            $reasons[] = "role {$evidence->role} has no prompt file today, so no run can be tied to what the agent runs with";
        } elseif ($source['prompt_version'] !== $evidence->stamp) {
            $reasons[] = "{$run} was measured on prompt stamp ".($source['prompt_version'] ?? 'none')." and the agent runs with {$evidence->stamp} today";
        }

        if ($maxAgeDays !== null) {
            $finished = $source['finished_at'] === null ? null : CarbonImmutable::parse($source['finished_at'])->utc();
            if ($finished === null) {
                $reasons[] = "{$run} has no finish time, so its age cannot be known";
            } elseif ($finished->lt($now->subDays($maxAgeDays))) {
                $reasons[] = "{$run} finished {$finished->toIso8601String()}, older than the {$maxAgeDays} day(s) allowed";
            }
        }

        return $reasons;
    }

    /**
     * The rates a grant may be compared with: only for clean evidence, floored to thousandths.
     *
     * @return array{0: float|null, 1: array<string, float>, 2: array<string, array{overall: float|null, dimensions: array<string, float>}>}
     */
    private function cleanRates(MeasuredEvidence $evidence): array
    {
        if ($evidence->overCeiling || $evidence->stamp === null) {
            return [null, [], []];
        }

        $clean = fn (array $source) => $source['run_status'] === 'passed'
            && $source['run_cases_unmeasured'] === 0
            && $source['stored_matches'] === true
            && $source['prompt_version'] === $evidence->stamp;

        $allRun = function (string $row) use ($evidence): bool {
            foreach ($evidence->suites as $suite) {
                if ($evidence->declaredIn($suite, $row) > 0 && ! isset($evidence->latest[$suite->id])) {
                    return false;
                }
            }

            return true;
        };

        $pooled = function (string $row) use ($evidence, $clean, $allRun): ?float {
            $sources = $evidence->sources($row);
            $passed = array_sum(array_column($sources, 'passed'));
            $failed = array_sum(array_column($sources, 'failed'));
            if ($sources === [] || $passed + $failed === 0 || ! $allRun($row) || in_array(false, array_map($clean, $sources), true)) {
                return null;
            }

            return MeasuredEvidence::flooredRate($passed, $failed);
        };

        $overall = $pooled(MeasuredEvidence::OVERALL);

        $dimensions = [];
        foreach (EvaluationDimension::cases() as $case) {
            $rate = $pooled($case->value);
            if ($rate !== null) {
                $dimensions[$case->value] = $rate;
            }
        }

        $suites = [];
        foreach ($evidence->suites as $suite) {
            if (! isset($evidence->latest[$suite->id])) {
                continue;
            }
            $runId = (int) $evidence->latest[$suite->id]->id;
            $pick = fn (string $row) => collect($evidence->sources($row))->firstWhere('run_id', $runId);

            $whole = $pick(MeasuredEvidence::OVERALL);
            if ($whole === null || ! $clean($whole) || $whole['passed'] + $whole['failed'] === 0) {
                continue;
            }

            $perDimension = [];
            foreach (EvaluationDimension::cases() as $case) {
                $source = $pick($case->value);
                if ($source !== null && $clean($source) && $source['passed'] + $source['failed'] > 0) {
                    $perDimension[$case->value] = MeasuredEvidence::flooredRate($source['passed'], $source['failed']);
                }
            }

            $suites[$suite->key] = [
                'overall' => MeasuredEvidence::flooredRate($whole['passed'], $whole['failed']),
                'dimensions' => $perDimension,
            ];
        }

        return [$overall, $dimensions, $suites];
    }

    /** @return array<string, mixed> */
    private function policyRef(AutonomyEvidencePolicy $policy): array
    {
        return [
            'policy_id' => (int) $policy->id,
            'uuid' => $policy->uuid,
            'version' => (int) $policy->version,
            'name' => $policy->name,
            'status' => $policy->status,
            'digest' => $policy->content_digest,
        ];
    }

    /**
     * Every result of the latest runs that may be counted for this role, and whether there were
     * more rows than one measurement will read (then none is returned: fail closed).
     *
     * @param  array<int, EvaluationRun>  $latest
     * @return array{0: Collection<int, EvaluationResult>, 1: bool}
     */
    private function resultsOf(array $latest, string $role): array
    {
        if ($latest === []) {
            return [collect(), false];
        }

        $rows = EvaluationResult::query()
            ->whereIn('run_id', array_map(fn (EvaluationRun $r) => $r->id, array_values($latest)))
            // ⛔ A result whose case belongs to another role is not evidence about this one (ADR-036).
            ->whereHas('evaluationCase', fn ($q) => $q->whereNull('applies_to_role')->orWhere('applies_to_role', $role))
            ->orderBy('id')
            ->limit(self::MAX_RESULT_ROWS + 1)
            ->get(['id', 'run_id', 'case_id', 'passed', 'unmeasured', 'dimension', 'checks']);

        return $rows->count() > self::MAX_RESULT_ROWS ? [collect(), true] : [$rows, false];
    }

    /**
     * How many active cases each suite holds for this role, per dimension.
     *
     * @param  Collection<int, EvaluationSuite>  $suites
     * @return array<int, array<string, int>>
     */
    private function declaredCases(Collection $suites, string $role): array
    {
        $out = [];

        $rows = EvaluationCase::query()
            ->whereIn('suite_id', $suites->pluck('id')->all())
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('applies_to_role')->orWhere('applies_to_role', $role))
            ->selectRaw('suite_id, dimension, count(*) as n')
            ->groupBy('suite_id', 'dimension')
            ->get();

        foreach ($rows as $row) {
            $out[(int) $row->suite_id][(string) ($row->dimension ?: EvaluationDimension::ToolSelection->value)] = (int) $row->n;
        }

        return $out;
    }

    private function currentStamp(string $role): ?string
    {
        try {
            return $this->prompts->has($role) ? $this->prompts->version($role) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function shown(?float $rate): string
    {
        return $rate === null ? 'nothing' : number_format($rate, 3);
    }
}
