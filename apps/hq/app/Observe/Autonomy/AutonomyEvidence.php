<?php

namespace App\Observe\Autonomy;

use App\Autonomy\AutonomyRequest;
use App\Autonomy\AutonomyResolver;
use App\Autonomy\Evidence\EvidencePolicies;
use App\Autonomy\Evidence\EvidenceProducer;
use App\Autonomy\Evidence\MeasuredEvidence;
use App\Evaluation\EvaluationDimension;
use App\Evaluation\GradeResult;
use App\Models\Agent;
use App\Models\AutonomyEvidencePolicy;
use App\Models\AutonomyGrant;
use App\Models\EvaluationResult;
use App\Models\EvaluationRun;
use App\Models\EvaluationSuite;
use App\Models\FeatureFlag;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The evidence autonomy would rest on, per agent and per evaluation dimension (docs/80 points 5 and 6).
 *
 * ⛔ **Read-only, and it decides nothing.** It creates no grant, lifts no ceiling, touches no
 * evaluation row and no flag. `AutonomyResolver` alone says what level applies; this class only shows
 * what was measured, what a live grant requires, and the exact reason the two do not meet.
 *
 * ## Why this is one class of queries and arithmetic
 *
 * Every figure is counted here from `evaluation_results` rows and returned WITH the run ids and the
 * result ids it was counted from, so a number on the page can be rebuilt by hand. A stored rate
 * (`evaluation_runs.dimension_rates`) is shown beside the recount and a difference is reported; it is
 * never the source.
 *
 * ## The rules, all of which fail closed
 *
 * - **Applicable suites** are the ones `hq:agent:evaluate` would run: active, and scoped to no role
 *   or to the agent's role.
 * - **The latest-run rule.** Per applicable suite the evidence is the FINISHED run (`passed` or
 *   `failed`) with the highest id for this agent. Older runs are history. A newer run that never
 *   finished is a caveat, never evidence.
 * - **Unmeasured is a third state.** A provider-refused result is in neither half of a rate
 *   (ADR-041), and a case skipped for another role has no result at all (ADR-036).
 * - **No inheritance (ADR-030).** The overall row never supplies a dimension row. A dimension nothing
 *   measured reads "never measured", whatever the overall rate is.
 * - **A requirement exists only on a live grant.** No table or config sets a required rate per level
 *   or per dimension, so with no grant the answer is "no requirement configured" and "not eligible".
 *   No number is invented here.
 * - **Satisfied only when nothing is unknown.** Every reason that applies is listed, not the first.
 */
final class AutonomyEvidence
{
    public const OVERALL = 'overall';

    public const RUNTIME_FLAG = 'ai.runtime';

    /** How many past failures a row lists. History beyond it stays on the run's own record. */
    public const RECENT_FAILURES = 10;

    private const FINISHED = ['passed', 'failed'];

    public function __construct(
        private readonly AutonomyResolver $resolver,
        private readonly EvidenceProducer $producer,
        private readonly EvidencePolicies $policies,
    ) {}

    /** @return array<string, mixed> */
    public function build(?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $environment = (string) app()->environment();
        $grants = $this->liveGrants($now);
        $flag = $this->runtimeFlag($environment);
        $policy = $this->policies->active();

        return [
            'generated_at' => $now->toIso8601String(),
            'environment' => $environment,
            'runtime_flag' => $flag,
            'live_grants_total' => $grants->count(),
            'policy' => $this->policySection($policy),
            'agents' => Agent::query()->orderBy('key')->get()
                ->map(fn (Agent $agent) => $this->agent($agent, $grants, $environment, $flag['enabled'], $policy, $now))
                ->all(),
        ];
    }

    /**
     * The autonomy evidence policy as it stands (ADR-048): the active version, or the plain statement
     * that none is, and every proposed version with what is still undecided in it. Proposed versions
     * are shown as NOT in force.
     *
     * @return array<string, mixed>
     */
    private function policySection(?AutonomyEvidencePolicy $active): array
    {
        $proposed = AutonomyEvidencePolicy::query()
            ->where('status', AutonomyEvidencePolicy::PROPOSED)
            ->with(['requirements', 'hardChecks'])
            ->orderBy('version')
            ->get()
            ->map(fn (AutonomyEvidencePolicy $p) => $this->policies->snapshot($p) + [
                'proposed_at' => $p->proposed_at?->toIso8601String(),
                'in_force' => false,
                'blocking' => $this->policies->structuralRefusals($p),
            ])
            ->all();

        return [
            'active' => $active === null ? null : $this->policies->snapshot($active) + [
                'content_digest' => $active->content_digest,
                'activated_by_user_id' => $active->activated_by === null ? null : (int) $active->activated_by,
                'activated_at' => $active->activated_at?->toIso8601String(),
                'activation_reason' => $active->activation_reason,
                'in_force' => true,
            ],
            'statement' => $active === null
                ? 'No autonomy evidence policy is active, so evidence cannot widen autonomy.'
                : "Autonomy evidence policy version {$active->version} is active. It is a ceiling on what a matching grant can give, never a grant itself.",
            'proposed' => $proposed,
        ];
    }

    /**
     * @param  Collection<int, AutonomyGrant>  $allGrants
     * @return array<string, mixed>
     */
    private function agent(Agent $agent, Collection $allGrants, string $environment, bool $runtimeOn, ?AutonomyEvidencePolicy $policy, CarbonImmutable $now): array
    {
        // ADR-048: the recount is the producer's, the same code the runner's evidence comes from, so
        // the page can never show a figure the runtime would not have used.
        $measured = $this->producer->measure($agent);
        $role = $measured->role;
        $stamp = $measured->stamp;
        $suites = $measured->suites;
        $latest = $measured->latest;
        $grants = $allGrants->filter(fn (AutonomyGrant $g) => $g->agent_key === null || $g->agent_key === $agent->key)->values();

        $suiteRows = [];
        foreach ($suites as $suite) {
            $run = $latest[$suite->id] ?? null;

            $suiteRows[] = [
                'suite_id' => (int) $suite->id,
                'suite_key' => $suite->key,
                'name' => $suite->name,
                'role_scope' => $suite->role_key,
                'pass_threshold' => $suite->pass_threshold,
                'finished_runs' => EvaluationRun::query()->where('suite_id', $suite->id)->where('agent_key', $agent->key)
                    ->whereIn('status', self::FINISHED)->count(),
                'unfinished_newer_run_ids' => EvaluationRun::query()->where('suite_id', $suite->id)->where('agent_key', $agent->key)
                    ->whereNotIn('status', self::FINISHED)->where('id', '>', $run?->id ?? 0)
                    ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all(),
                'latest_run' => $run === null ? null : MeasuredEvidence::runLineage($run, $suite),
            ];
        }

        $rows = [];
        foreach ([self::OVERALL, ...array_map(fn (EvaluationDimension $d) => $d->value, EvaluationDimension::cases())] as $dimension) {
            $rows[] = $this->row($dimension, $agent, $role, $stamp, $measured, $grants);
        }

        return [
            'key' => $agent->key,
            'name' => $agent->name,
            'status' => $agent->status,
            'role' => $role,
            'configured_ceiling' => $agent->max_autonomy_level?->value,
            'current_stamp' => $stamp,
            'ceiling' => $this->ceiling($agent, $grants, $environment, $runtimeOn, $measured, $now),
            // What the active autonomy evidence policy says of this agent's evidence, level by level;
            // null when no policy is active (ADR-048).
            'policy_judgement' => $policy === null ? null : $this->producer->judge($measured, $policy, $now),
            'live_grants' => $grants->map(fn (AutonomyGrant $g) => $this->grantLineage($g))->all(),
            'suites' => $suiteRows,
            'rows' => $rows,
        ];
    }

    /**
     * One row: the overall rate, or one dimension. Never both from the same figures.
     *
     * @param  Collection<int, AutonomyGrant>  $grants
     * @return array<string, mixed>
     */
    private function row(
        string $dimension,
        Agent $agent,
        string $role,
        ?string $stamp,
        MeasuredEvidence $measured,
        Collection $grants,
    ): array {
        $isOverall = $dimension === self::OVERALL;
        $label = $isOverall ? 'overall agreement' : EvaluationDimension::from($dimension)->label();
        $suites = $measured->suites;
        $latest = $measured->latest;
        $sources = $measured->sources($dimension);

        $passed = array_sum(array_column($sources, 'passed'));
        $failed = array_sum(array_column($sources, 'failed'));
        $unmeasured = array_sum(array_column($sources, 'unmeasured'));
        $evidence = $passed + $failed;

        $measurement = match (true) {
            $evidence > 0 => 'measured',
            $unmeasured > 0 => 'unmeasured_only',
            default => 'never_measured',
        };

        // ── The reasons that hold whatever a grant asks for.
        $reasons = [];
        if ($measurement === 'never_measured') {
            $reasons[] = $isOverall
                ? 'never measured: no finished evaluation run exists for this agent'
                : "dimension never measured: no finished run holds a {$label} result for this agent";
        } elseif ($measurement === 'unmeasured_only') {
            $reasons[] = "nothing was measured: all {$unmeasured} result(s) were refused by the model provider, which is not evidence about the agent";
        }

        foreach ($suites as $suite) {
            $cases = $measured->declaredIn($suite, $dimension);
            if ($cases > 0 && ! isset($latest[$suite->id])) {
                $reasons[] = "suite {$suite->key} holds {$cases} ".($isOverall ? '' : "{$label} ").'case(s) for this agent and has never been run against it';
            }
        }

        foreach ($sources as $source) {
            array_push($reasons, ...$this->sourceReasons($source, $stamp, $role));
        }

        // ── What a live grant requires of this row, and whether the figures meet it.
        $requirements = [];
        foreach ($grants as $grant) {
            if ($grant->min_agreement_rate === null) {
                continue;
            }
            if (($grant->min_agreement_dimension ?? self::OVERALL) !== $dimension) {
                continue;
            }
            $requirements[] = $this->requirement($grant, $label, $sources, $passed, $failed);
        }

        if ($requirements === []) {
            array_unshift($reasons, $isOverall
                ? 'no requirement configured: no live autonomy grant names a required overall agreement rate for this agent'
                : "no requirement configured: no live autonomy grant names a required {$label} rate for this agent");
        }

        foreach ($requirements as $requirement) {
            array_push($reasons, ...$requirement['reasons']);
        }

        $reasons = array_values(array_unique($reasons));

        return [
            'dimension' => $dimension,
            'label' => $label,
            'blames' => $isOverall ? null : EvaluationDimension::from($dimension)->blames(),
            'measurement' => $measurement,
            'passed' => $passed,
            'failed' => $failed,
            'unmeasured' => $unmeasured,
            'evidence_count' => $evidence,
            'agreement_rate' => MeasuredEvidence::rate($passed, $failed),
            'required_rate' => $requirements === [] ? null : max(array_column($requirements, 'required_rate')),
            'requirements' => $requirements,
            'eligible' => $reasons === [],
            'state' => $reasons === [] ? 'satisfied' : 'not_eligible',
            'reasons' => $reasons,
            'sources' => $sources,
            'recent_failures' => $this->recentFailures($agent, $suites, $role, $isOverall ? null : $dimension, $latest),
        ];
    }

    /**
     * Why one contributing run cannot be leaned on, if it cannot.
     *
     * @param  array<string, mixed>  $source
     * @return list<string>
     */
    private function sourceReasons(array $source, ?string $stamp, string $role): array
    {
        $reasons = [];
        $run = "run {$source['run_id']} of {$source['suite_key']}";

        if ($source['run_status'] !== 'passed') {
            $reasons[] = "latest gate failed {$source['run_cases_passed']} of {$source['run_cases_total']} ({$run})";
        }

        if ($source['run_cases_unmeasured'] > 0) {
            $reasons[] = "latest run had {$source['run_cases_unmeasured']} unmeasured case(s), refused by the model provider ({$run})";
        }

        if (! $source['stored_matches']) {
            $reasons[] = "the rate stored on {$run} does not match a recount of its results";
        }

        if ($stamp === null) {
            $reasons[] = "role {$role} has no prompt file today, so no run can be tied to what the agent runs with";
        } elseif ($source['prompt_version'] === null) {
            $reasons[] = "{$run} carries no prompt stamp, so it cannot be tied to the prompt the agent runs with today ({$stamp})";
        } elseif ($source['prompt_version'] !== $stamp) {
            $reasons[] = "measured on a different prompt stamp ({$source['prompt_version']}, {$run}) than the one the agent runs with today ({$stamp})";
        }

        return $reasons;
    }

    /**
     * One grant's requirement against one row.
     *
     * Compared in whole thousandths and never on a rounded rate, so 0.9996 does not read as 1.000. A
     * grant naming a suite is checked against that suite's run alone. A grant naming none is checked
     * against the pooled figure AND against every contributing suite, so a strong suite cannot carry
     * a weak one.
     *
     * @param  list<array<string, mixed>>  $sources
     * @return array<string, mixed>
     */
    private function requirement(AutonomyGrant $grant, string $label, array $sources, int $passed, int $failed): array
    {
        $required = (float) $grant->min_agreement_rate;
        $reasons = [];

        if ($grant->evaluation_suite_key !== null) {
            $sources = array_values(array_filter($sources, fn (array $s) => $s['suite_key'] === $grant->evaluation_suite_key));
            $passed = array_sum(array_column($sources, 'passed'));
            $failed = array_sum(array_column($sources, 'failed'));

            if ($sources === []) {
                $reasons[] = "grant {$grant->id} requires evidence from suite {$grant->evaluation_suite_key}, which holds no {$label} result for this agent";
            }
        }

        if ($passed + $failed === 0) {
            $reasons[] = "grant {$grant->id} requires {$this->shown($required)} and nothing was measured";
        } elseif (! MeasuredEvidence::meets($passed, $failed, $required)) {
            $reasons[] = "{$label} measured {$this->shown(MeasuredEvidence::rate($passed, $failed))} ({$passed} of ".($passed + $failed)."), below the {$this->shown($required)} grant {$grant->id} requires";
        }

        foreach ($sources as $source) {
            $measured = $source['passed'] + $source['failed'];
            if ($measured > 0 && ! MeasuredEvidence::meets($source['passed'], $source['failed'], $required)) {
                $reasons[] = "run {$source['run_id']} of {$source['suite_key']} measured {$label} at {$this->shown($source['agreement_rate'])} ({$source['passed']} of {$measured}), below the {$this->shown($required)} grant {$grant->id} requires";
            }
        }

        return $this->grantLineage($grant) + [
            'required_rate' => $required,
            'measured_passed' => $passed,
            'measured_failed' => $failed,
            'measured_rate' => MeasuredEvidence::rate($passed, $failed),
            'met' => $reasons === [],
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    /**
     * The newest failed results for this agent, across its finished runs. An unmeasured result is not
     * a failure and is never listed here.
     *
     * @param  Collection<int, EvaluationSuite>  $suites
     * @param  array<int, EvaluationRun>  $latest
     * @return list<array<string, mixed>>
     */
    private function recentFailures(Agent $agent, Collection $suites, string $role, ?string $dimension, array $latest): array
    {
        $latestIds = array_map(fn (EvaluationRun $r) => (int) $r->id, array_values($latest));

        return EvaluationResult::query()
            ->with(['evaluationCase:id,key,severity', 'run:id,uuid,suite_id,prompt_version,finished_at,status'])
            ->where('passed', false)->where('unmeasured', false)
            ->when($dimension !== null, fn ($q) => $q->where('dimension', $dimension))
            ->whereHas('run', fn ($q) => $q->where('agent_key', $agent->key)->whereIn('status', self::FINISHED)
                ->whereIn('suite_id', $suites->pluck('id')->all()))
            ->whereHas('evaluationCase', fn ($q) => $q->whereNull('applies_to_role')->orWhere('applies_to_role', $role))
            ->orderByDesc('id')
            ->limit(self::RECENT_FAILURES)
            ->get()
            ->map(fn (EvaluationResult $r) => [
                'result_id' => (int) $r->id,
                'case_key' => $r->evaluationCase?->key,
                'severity' => $r->evaluationCase?->severity,
                'dimension' => $r->dimension,
                'run_id' => (int) $r->run_id,
                'run_uuid' => $r->run?->uuid,
                'suite_key' => $suites->firstWhere('id', $r->run?->suite_id)?->key,
                'prompt_version' => $r->run?->prompt_version,
                'finished_at' => $r->run?->finished_at?->toIso8601String(),
                'in_latest_run' => in_array((int) $r->run_id, $latestIds, true),
                'failed_checks' => array_keys(array_filter((array) $r->checks, fn ($outcome) => $outcome === GradeResult::FAIL)),
                'reason' => $r->reason,
            ])
            ->all();
    }

    /**
     * What the resolver says now, asked the way `AgentRunner` asks it: the agent, the environment, the
     * agent's own ceiling and, when a policy is active, the producer's evidence (ADR-048). No
     * business and no context, because the page asks for no particular business or message.
     *
     * @param  Collection<int, AutonomyGrant>  $grants
     * @return array<string, mixed>
     */
    private function ceiling(Agent $agent, Collection $grants, string $environment, bool $runtimeOn, MeasuredEvidence $measured, CarbonImmutable $now): array
    {
        $evidence = $this->producer->forRunner($agent, $now, $measured);
        $decision = $this->resolver->resolve(new AutonomyRequest(
            agentKey: $agent->key,
            environment: $environment,
            agreementRate: $evidence?->agreementRate,
            dimensionRates: $evidence?->dimensionRates ?? [],
            suiteRates: $evidence?->suiteRates ?? [],
            evidence: $evidence,
            agentCeiling: $agent->max_autonomy_level,
        ));

        $why = [];
        if (! $agent->isActive()) {
            $why[] = "The agent is {$agent->status}, so it runs nothing at all.";
        }
        if (! $runtimeOn) {
            $why[] = 'The AI runtime flag ('.self::RUNTIME_FLAG.') is off in this environment.';
        }
        $why[] = $grants->isEmpty()
            ? 'No live autonomy grant names this agent.'
            : $grants->count().' live autonomy grant(s) name this agent or every agent.';
        $why[] = 'The resolver answers '.strtoupper($decision->level->value).': '.$decision->boundBy.'.';

        if ($evidence !== null) {
            $why[] = ucfirst($evidence->detail()).'.';
        }

        $unmatched = $grants->filter(fn (AutonomyGrant $g) => $g->min_agreement_rate !== null && ! in_array((int) $g->id, $decision->grantIds, true));
        foreach ($unmatched as $grant) {
            $why[] = $evidence === null
                ? "Grant {$grant->id} requires measured evidence. The resolver was asked the way the runtime asks it, without measured rates, so that grant does not apply."
                : "Grant {$grant->id} requires measured evidence that the clean evidence does not supply or does not reach, so that grant does not apply.";
        }

        return $decision->toArray() + ['why' => $why];
    }

    /** @return Collection<int, AutonomyGrant> */
    private function liveGrants(CarbonImmutable $now): Collection
    {
        return AutonomyGrant::query()
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->orderBy('id')
            ->get();
    }

    /**
     * Read from the flag rows and not through the cached reader, so that looking writes nothing, not
     * even a cache entry. The rule is the reader's: this environment's row, else the `*` row, else off.
     *
     * @return array{key: string, enabled: bool, flag_id: int|null, environment: string|null}
     */
    private function runtimeFlag(string $environment): array
    {
        $rows = FeatureFlag::query()->where('key', self::RUNTIME_FLAG)->whereIn('environment', [$environment, '*'])->get();
        $row = $rows->firstWhere('environment', $environment) ?? $rows->firstWhere('environment', '*');

        return [
            'key' => self::RUNTIME_FLAG,
            'enabled' => (bool) ($row?->enabled ?? false),
            'flag_id' => $row === null ? null : (int) $row->id,
            'environment' => $row?->environment,
        ];
    }

    /** @return array<string, mixed> */
    private function grantLineage(AutonomyGrant $grant): array
    {
        return [
            'grant_id' => (int) $grant->id,
            'grant_uuid' => $grant->uuid,
            'grant_name' => $grant->name,
            'agent_key' => $grant->agent_key,
            'max_level' => $grant->max_level->value,
            'min_agreement_rate' => $grant->min_agreement_rate,
            'min_agreement_dimension' => $grant->min_agreement_dimension,
            'evaluation_suite_key' => $grant->evaluation_suite_key,
            'expires_at' => $grant->expires_at?->toIso8601String(),
        ];
    }

    private function shown(?float $rate): string
    {
        return $rate === null ? 'nothing' : number_format($rate, 3);
    }
}
