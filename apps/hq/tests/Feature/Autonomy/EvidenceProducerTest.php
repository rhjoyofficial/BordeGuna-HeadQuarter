<?php

use App\Agents\PromptLibrary;
use App\Autonomy\Evidence\EvidenceProducer;
use App\Autonomy\Evidence\MeasuredEvidence;
use App\Models\Agent;
use App\Models\AutonomyEvidencePolicy;
use App\Models\EvaluationResult;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\Autonomy\EvidenceFixtures as F;

/*
 | ADR-048, docs/81 C.4: the evidence producer's rules, one by one, each failing closed. Every number
 | in a policy below is a TEST VALUE chosen to exercise a rule.
 */

function aeprProducer(): EvidenceProducer
{
    return app(EvidenceProducer::class);
}

/** @return array<string, mixed> */
function aeprJudge(AutonomyEvidencePolicy $policy, string $agent = 'executive'): array
{
    return aeprProducer()->judge(aeprProducer()->measure(Agent::where('key', $agent)->sole()), $policy);
}

/** @return array<string, mixed> */
function aeprLevel(array $judgement, string $level): array
{
    return collect($judgement['levels'])->firstWhere('level', $level);
}

/** @return array<string, mixed> */
function aeprRequirement(array $judgement, string $level, string $dimension): array
{
    return collect(aeprLevel($judgement, $level)['requirements'])->firstWhere('dimension', $dimension);
}

beforeEach(function () {
    $this->owner = $this->hqUser('owner');
    F::agent();
});

it('never lets the overall rate stand in for a required dimension nothing measured', function () {
    $suite = F::suite('safety.core', null, ['a' => ['tool_selection'], 'b' => ['tool_selection']]);
    F::run($suite, 'executive', ['a' => 'pass', 'b' => 'pass']);
    $policy = F::propose([F::requirement('a1', 'reasoning', 0.5, 1, 7)], by: $this->owner);

    $judgement = aeprJudge($policy);
    $row = aeprRequirement($judgement, 'a1', 'reasoning');

    // The overall figure is a perfect 1.000 on two results, and it is not what was asked for.
    expect(aeprProducer()->measure(Agent::where('key', 'executive')->sole())->sources('overall')[0]['agreement_rate'])->toBe(1.0)
        ->and($row)->toMatchArray(['met' => false, 'evidence_count' => 0, 'agreement_rate' => null])
        ->and($row['reasons'])->toBe(['never measured: no latest finished run holds a model reasoning result for this agent'])
        ->and($judgement['ceiling'])->toBe('a0');
});

it('does not count an unmeasured result as a pass, and a run holding one is never evidence', function () {
    $suite = F::suite('safety.core', null, ['a' => ['tool_selection'], 'b' => ['tool_selection'], 'c' => ['tool_selection'], 'r' => ['reasoning']]);
    $run = F::run($suite, 'executive', ['a' => 'pass', 'b' => 'pass', 'c' => 'unmeasured', 'r' => 'unmeasured']);
    $policy = F::propose([F::requirement('a1', 'tool_selection', 0.9, 2, 7), F::requirement('a1', 'reasoning', 0.9, 1, 7)], by: $this->owner);

    $judgement = aeprJudge($policy);

    expect(aeprRequirement($judgement, 'a1', 'tool_selection'))->toMatchArray(['passed' => 2, 'failed' => 0, 'unmeasured' => 1, 'evidence_count' => 2, 'met' => false])
        ->and(aeprRequirement($judgement, 'a1', 'tool_selection')['reasons'])->toBe([
            "run {$run->id} of safety.core failed its own gate (2 of 4)",
            "run {$run->id} of safety.core had 2 unmeasured case(s), refused by the model provider",
        ])
        ->and(aeprRequirement($judgement, 'a1', 'reasoning')['reasons'])->toContain('nothing was measured: all 1 model reasoning result(s) were refused by the model provider')
        ->and($judgement['ceiling'])->toBe('a0');
});

it('needs every requirement of a level: one satisfied dimension is not enough', function () {
    $suite = F::suite('safety.core', null, ['a' => ['tool_selection'], 'b' => ['tool_selection']]);
    F::run($suite, 'executive', ['a' => 'pass', 'b' => 'pass']);
    $policy = F::propose([F::requirement('a1', 'tool_selection'), F::requirement('a1', 'enforcement')], by: $this->owner);

    $judgement = aeprJudge($policy);

    expect(aeprRequirement($judgement, 'a1', 'tool_selection')['met'])->toBeTrue()
        ->and(aeprRequirement($judgement, 'a1', 'enforcement')['met'])->toBeFalse()
        ->and(aeprLevel($judgement, 'a1'))->toMatchArray(['eligible' => false, 'own_met' => false])
        ->and($judgement['ceiling'])->toBe('a0')
        ->and($judgement['ceiling_reasons'])->toBe(['A1 permission and consent enforcement: never measured: no latest finished run holds a permission and consent enforcement result for this agent']);
});

it('refuses evidence on an old prompt stamp, evidence that is too old, and too few results', function () {
    $suite = F::suite('safety.core', null, ['a' => ['tool_selection'], 'b' => ['tool_selection'], 'c' => ['tool_selection']]);
    $run = F::run($suite, 'executive', ['a' => 'pass', 'b' => 'pass', 'c' => 'pass']);
    $policy = F::propose([F::requirement('a1', 'tool_selection', 0.9, 3, 7)], by: $this->owner);

    expect(aeprJudge($policy)['ceiling'])->toBe('a1');

    $today = app(PromptLibrary::class)->version('executive');
    $run->update(['prompt_version' => 'executive.v1+00000000']);
    expect(aeprRequirement(aeprJudge($policy), 'a1', 'tool_selection')['reasons'])->toBe([
        "run {$run->id} of safety.core was measured on prompt stamp executive.v1+00000000 and the agent runs with {$today} today",
    ]);

    $run->update(['prompt_version' => $today, 'finished_at' => now()->subDays(8)]);
    $reasons = aeprRequirement(aeprJudge($policy), 'a1', 'tool_selection')['reasons'];
    expect($reasons)->toHaveCount(1)->and($reasons[0])->toContain('older than the 7 day(s) allowed');

    $run->update(['finished_at' => now()]);
    $stricter = F::propose([F::requirement('a1', 'tool_selection', 0.9, 4, 7)], by: $this->owner);
    expect(aeprRequirement(aeprJudge($stricter), 'a1', 'tool_selection')['reasons'])->toBe(['3 measured result(s), fewer than the 4 required']);
});

it('does not let a strong suite carry a weak one, and names a declaring suite that was never run', function () {
    $strong = F::suite('safety.core', null, array_fill_keys(array_map(fn ($i) => "s{$i}", range(1, 9)), ['tool_selection']));
    $weak = F::suite('behaviour.executive', 'executive', ['w1' => ['tool_selection'], 'w2' => ['tool_selection']]);
    F::run($strong, 'executive', array_fill_keys(array_map(fn ($i) => "s{$i}", range(1, 9)), 'pass'));
    $weakRun = F::run($weak, 'executive', ['w1' => 'pass', 'w2' => 'fail'], ['status' => 'passed']);
    $policy = F::propose([F::requirement('a1', 'tool_selection', 0.9, 2, 7)], by: $this->owner);

    // Pooled 10 of 11 = 0.909 meets 0.900; the weak suite alone is 0.500.
    $row = aeprRequirement(aeprJudge($policy), 'a1', 'tool_selection');
    expect($row['agreement_rate'])->toBe(0.909)
        ->and($row['reasons'])->toBe(["run {$weakRun->id} of behaviour.executive measured 0.500 (1 of 2), below the required 0.900"]);

    F::suite('behaviour.never-run', null, ['n' => ['tool_selection']]);
    expect(aeprRequirement(aeprJudge($policy), 'a1', 'tool_selection')['reasons'])
        ->toContain('suite behaviour.never-run holds 1 tool selection case(s) for this agent and has never been run against it');
});

it('holds a hard check at 100 percent: one failure caps the level below it whatever the rates say', function () {
    $suite = F::suite('safety.core', null, ['a' => ['tool_selection'], 'b' => ['tool_selection'], 'c' => ['tool_selection']]);
    $run = F::run($suite, 'executive', ['a' => 'pass', 'b' => 'pass', 'c' => 'pass'], checks: [
        'a' => ['must_not_call' => 'pass'], 'b' => ['must_not_call' => 'pass'], 'c' => ['must_not_call' => 'fail'],
    ]);
    $policy = F::propose([F::requirement('a1', 'tool_selection', 0.9, 3, 7)], [F::hardCheck('a1', 'must_not_call', 1, 7)], by: $this->owner);

    $judgement = aeprJudge($policy);
    $failed = EvaluationResult::where('run_id', $run->id)->where('case_id', $suite->cases()->where('key', 'c')->value('id'))->value('id');
    $check = aeprLevel($judgement, 'a1')['hard_checks'][0];

    expect(aeprRequirement($judgement, 'a1', 'tool_selection')['met'])->toBeTrue()
        ->and($check)->toMatchArray(['check' => 'must_not_call', 'passed' => 2, 'failed' => 1, 'met' => false, 'failed_result_ids' => [$failed]])
        ->and($judgement['ceiling'])->toBe('a0');

    EvaluationResult::whereKey($failed)->update(['checks' => ['must_not_call' => 'pass']]);
    expect(aeprJudge($policy)['ceiling'])->toBe('a1');

    // A check no measured result ever exercised has not passed.
    $never = F::propose([F::requirement('a1', 'tool_selection', 0.9, 3, 7)], [F::hardCheck('a1', 'citations', 1, 7)], by: $this->owner);
    expect(aeprLevel(aeprJudge($never), 'a1')['hard_checks'][0]['reasons'])->toBe(['never exercised: no latest finished run holds a measured result where this check applied']);
});

it('is cumulative: a level is reached only through every level below it', function () {
    $suite = F::suite('safety.core', null, ['a' => ['tool_selection'], 'b' => ['tool_selection']]);
    F::run($suite, 'executive', ['a' => 'pass', 'b' => 'pass']);

    // A2's own requirement is met, A1's is not: A2 is not reached.
    $policy = F::propose([F::requirement('a1', 'reasoning'), F::requirement('a2', 'tool_selection')], by: $this->owner);
    $judgement = aeprJudge($policy);
    expect(aeprLevel($judgement, 'a2'))->toMatchArray(['own_met' => true, 'eligible' => false])
        ->and(aeprLevel($judgement, 'a2')['reasons'])->toBe(['levels are cumulative, and a lower level is not reached'])
        ->and($judgement['ceiling'])->toBe('a0');

    // A1 met and nothing said about A2: the ceiling is A1, and the reason is the policy's silence.
    $policy = F::propose([F::requirement('a1', 'tool_selection')], by: $this->owner);
    $judgement = aeprJudge($policy);
    expect($judgement['ceiling'])->toBe('a1')
        ->and($judgement['ceiling_reasons'])->toBe(['the policy names no requirement for A2, so evidence cannot reach it'])
        ->and(aeprLevel($judgement, 'a4')['eligible'])->toBeFalse();
});

it('never meets an undecided (NULL) field, however good the evidence', function () {
    $suite = F::suite('safety.core', null, ['a' => ['tool_selection'], 'b' => ['tool_selection']]);
    F::run($suite, 'executive', ['a' => 'pass', 'b' => 'pass']);
    $policy = F::propose([F::requirement('a1', 'tool_selection', null, 2, null)], by: $this->owner);

    $row = aeprRequirement(aeprJudge($policy), 'a1', 'tool_selection');

    expect($row['met'])->toBeFalse()
        ->and($row['undecided'])->toBe(['min_agreement_rate', 'max_evidence_age_days'])
        ->and($row['reasons'])->toBe([
            'min_agreement_rate is not decided (NULL), and an undecided requirement is never met',
            'max_evidence_age_days is not decided (NULL), and an undecided requirement is never met',
        ]);
});

it('fails closed when a measurement holds more rows than it will read', function () {
    $suite = F::suite('safety.core', null, ['a' => ['tool_selection']]);
    F::run($suite, 'executive', ['a' => 'pass']);
    $policy = F::propose([F::requirement('a1', 'tool_selection', 0.5, 1, 7)], [F::hardCheck('a1', 'must_not_call', 1, 7)], by: $this->owner);
    $measured = aeprProducer()->measure(Agent::where('key', 'executive')->sole());
    $over = new MeasuredEvidence($measured->agentKey, $measured->role, $measured->stamp, $measured->suites, $measured->latest, collect(), $measured->declared, overCeiling: true);

    $judgement = aeprProducer()->judge($over, $policy);

    expect($judgement['ceiling'])->toBe('a0')
        ->and(aeprRequirement($judgement, 'a1', 'tool_selection')['reasons'])->toContain('the evidence holds more than '.EvidenceProducer::MAX_RESULT_ROWS.' result rows, so nothing was counted');
});

it('reads nothing beyond the policy lookup when no policy is active, and no evidence when no grant names the agent', function () {
    $suite = F::suite('safety.core', null, ['a' => ['tool_selection']]);
    F::run($suite, 'executive', ['a' => 'pass']);
    $agent = Agent::where('key', 'executive')->sole();

    $queries = [];
    DB::listen(function (QueryExecuted $q) use (&$queries) {
        $queries[] = $q->sql;
    });

    expect(aeprProducer()->forRunner($agent))->toBeNull()
        ->and($queries)->toHaveCount(1)
        ->and($queries[0])->toContain('autonomy_evidence_policies');

    F::activate($this->owner, [F::requirement('a1', 'tool_selection')]);
    $queries = [];
    $evidence = aeprProducer()->forRunner($agent);

    expect($evidence->ceiling->value)->toBe('a0')
        ->and($evidence->record['evidence_counted'])->toBeFalse()
        ->and($evidence->agreementRate)->toBeNull()
        ->and($evidence->dimensionRates)->toBe([])
        ->and(collect($queries)->filter(fn ($sql) => str_contains($sql, 'evaluation_'))->all())->toBe([]);
});

it('hands the resolver clean rates only, floored to thousandths, per dimension and per suite', function () {
    $a = F::suite('safety.core', null, ['t1' => ['tool_selection'], 't2' => ['tool_selection'], 't3' => ['tool_selection']]);
    $b = F::suite('behaviour.executive', 'executive', ['r1' => ['reasoning'], 'r2' => ['reasoning']]);
    F::run($a, 'executive', ['t1' => 'pass', 't2' => 'pass', 't3' => 'fail'], ['status' => 'passed']);
    F::run($b, 'executive', ['r1' => 'pass', 'r2' => 'unmeasured']);
    F::grant();
    F::activate($this->owner, [F::requirement('a1', 'tool_selection', 0.5, 1, 7)]);

    $evidence = aeprProducer()->forRunner(Agent::where('key', 'executive')->sole());

    // 2 of 3 is 0.666 floored, never 0.667 rounded up. The reasoning evidence sits in a run with an
    // unmeasured case, so it is not clean and is absent; the pooled overall includes that run, so it
    // is absent too; the clean suite keeps its own figures.
    expect($evidence->dimensionRates)->toBe(['tool_selection' => 0.666])
        ->and($evidence->agreementRate)->toBeNull()
        ->and($evidence->suiteRates)->toBe(['safety.core' => ['overall' => 0.666, 'dimensions' => ['tool_selection' => 0.666]]])
        ->and($evidence->ceiling->value)->toBe('a1')
        ->and($evidence->record['evidence_counted'])->toBeTrue();
});

it('digests exactly what was counted: one changed result gives another digest', function () {
    $suite = F::suite('safety.core', null, ['a' => ['tool_selection'], 'b' => ['tool_selection']]);
    $run = F::run($suite, 'executive', ['a' => 'pass', 'b' => 'pass']);
    $agent = Agent::where('key', 'executive')->sole();

    $before = aeprProducer()->measure($agent)->digest();
    expect(aeprProducer()->measure($agent)->digest())->toBe($before)->toHaveLength(64);

    EvaluationResult::where('run_id', $run->id)->first()->update(['checks' => ['must_not_call' => 'pass', 'citations' => 'pass']]);
    expect(aeprProducer()->measure($agent)->digest())->not->toBe($before);
});
