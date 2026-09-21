<?php

namespace Tests\Support\Autonomy;

use App\Agents\PromptLibrary;
use App\Autonomy\Evidence\EvidencePolicies;
use App\Models\Agent;
use App\Models\AutonomyEvidencePolicy;
use App\Models\AutonomyGrant;
use App\Models\EvaluationCase;
use App\Models\EvaluationResult;
use App\Models\EvaluationRun;
use App\Models\EvaluationSuite;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Evaluation evidence and autonomy evidence policies for the ADR-048 tests.
 *
 * ⛔ Every number a policy carries here is a TEST VALUE, chosen to exercise a rule. None of them is
 * a proposal for production, and no seeder or migration carries any of them.
 */
final class EvidenceFixtures
{
    public static function agent(string $key = 'executive', string $status = 'paused', ?string $role = null): Agent
    {
        return Agent::create(['key' => $key, 'name' => ucfirst($key), 'status' => $status, 'role_key' => $role ?? $key, 'max_autonomy_level' => 'a0']);
    }

    /** @param  array<string, array{0: string, 1?: string|null}>  $cases  case key => [dimension, applies_to_role] */
    public static function suite(string $key, ?string $role, array $cases): EvaluationSuite
    {
        $suite = EvaluationSuite::create(['key' => $key, 'name' => $key, 'kind' => 'safety', 'role_key' => $role, 'is_active' => true, 'pass_threshold' => 1.0]);

        foreach ($cases as $caseKey => $spec) {
            EvaluationCase::create([
                'suite_id' => $suite->id, 'key' => $caseKey, 'input' => ['prompt' => 'x'], 'expectation' => ['refusal' => false],
                'grader' => 'deterministic', 'severity' => 'blocker', 'dimension' => $spec[0], 'applies_to_role' => $spec[1] ?? null, 'is_active' => true,
            ]);
        }

        return $suite;
    }

    /**
     * A finished run written the way the runner writes one. Each case's outcome is 'pass', 'fail'
     * or 'unmeasured'. Checks default to `must_not_call` passing (and `refusal` failing on a fail);
     * `$checks` overrides them per case.
     *
     * @param  array<string, string>  $outcomes
     * @param  array<string, array<string, string>>  $checks
     */
    public static function run(EvaluationSuite $suite, string $agent, array $outcomes, array $attributes = [], array $checks = []): EvaluationRun
    {
        $counts = array_count_values($outcomes) + ['pass' => 0, 'fail' => 0, 'unmeasured' => 0];
        $status = $counts['fail'] === 0 && $counts['unmeasured'] === 0 ? 'passed' : 'failed';
        $cases = $suite->cases()->get()->keyBy('key');

        $byDimension = [];
        foreach ($outcomes as $caseKey => $outcome) {
            if ($outcome === 'unmeasured') {
                continue;
            }
            $d = $cases[$caseKey]->dimension;
            $byDimension[$d] ??= ['passed' => 0, 'failed' => 0];
            $byDimension[$d][$outcome === 'pass' ? 'passed' : 'failed']++;
        }
        foreach ($byDimension as $d => $c) {
            $byDimension[$d]['rate'] = round($c['passed'] / max(1, $c['passed'] + $c['failed']), 3);
        }

        $run = EvaluationRun::create($attributes + [
            'uuid' => (string) Str::uuid(), 'suite_id' => $suite->id, 'agent_key' => $agent, 'status' => $status,
            'prompt_version' => app(PromptLibrary::class)->version('executive'),
            'cases_total' => count($outcomes), 'cases_passed' => $counts['pass'], 'cases_failed' => $counts['fail'],
            'cases_unmeasured' => $counts['unmeasured'], 'cases_skipped' => 0,
            'agreement_rate' => round($counts['pass'] / max(1, $counts['pass'] + $counts['fail']), 3),
            'dimension_rates' => $byDimension, 'triggered_by' => 'manual', 'started_at' => now(), 'finished_at' => now(),
        ]);

        foreach ($outcomes as $caseKey => $outcome) {
            EvaluationResult::create([
                'run_id' => $run->id, 'case_id' => $cases[$caseKey]->id, 'passed' => $outcome === 'pass', 'unmeasured' => $outcome === 'unmeasured',
                'grader' => 'deterministic', 'dimension' => $cases[$caseKey]->dimension,
                'checks' => $checks[$caseKey] ?? match ($outcome) {
                    'fail' => ['must_not_call' => 'pass', 'refusal' => 'fail'],
                    'unmeasured' => [],
                    default => ['must_not_call' => 'pass'],
                },
                'reason' => $outcome === 'fail' ? 'expected no refusal and got one' : null,
            ]);
        }

        return $run;
    }

    public static function grant(array $attributes = []): AutonomyGrant
    {
        return AutonomyGrant::create($attributes + ['uuid' => (string) Str::uuid(), 'name' => 'test grant', 'max_level' => 'a2', 'status' => 'active', 'agent_key' => 'executive']);
    }

    /**
     * A requirement row in the definition shape, with TEST VALUES by default.
     *
     * @return array<string, mixed>
     */
    public static function requirement(string $level, string $dimension, ?float $rate = 0.9, ?int $samples = 2, ?int $ageDays = 7): array
    {
        return ['level' => $level, 'dimension' => $dimension, 'min_agreement_rate' => $rate, 'min_sample_count' => $samples, 'max_evidence_age_days' => $ageDays, 'note' => 'test value'];
    }

    /** @return array<string, mixed> */
    public static function hardCheck(string $level, string $check, ?int $samples = 1, ?int $ageDays = 7): array
    {
        return ['level' => $level, 'check' => $check, 'min_sample_count' => $samples, 'max_evidence_age_days' => $ageDays, 'note' => 'test value'];
    }

    /**
     * @param  list<array<string, mixed>>  $requirements
     * @param  list<array<string, mixed>>  $hardChecks
     */
    public static function propose(array $requirements, array $hardChecks = [], ?User $by = null, string $name = 'test policy'): AutonomyEvidencePolicy
    {
        return app(EvidencePolicies::class)->propose(['name' => $name, 'requirements' => $requirements, 'hard_checks' => $hardChecks], $by, 'test');
    }

    /**
     * @param  list<array<string, mixed>>  $requirements
     * @param  list<array<string, mixed>>  $hardChecks
     */
    public static function activate(User $by, array $requirements, array $hardChecks = []): AutonomyEvidencePolicy
    {
        return app(EvidencePolicies::class)->activate(self::propose($requirements, $hardChecks, $by), $by, 'test activation');
    }
}
