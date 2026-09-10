<?php

use App\Autonomy\AutonomyRequest;
use App\Autonomy\AutonomyResolver;
use App\Evaluation\DeterministicGrader;
use App\Evaluation\EvaluationDimension;
use App\Evaluation\EvaluationObservation;
use App\Evaluation\EvaluationRunner;
use App\Evaluation\EvaluationSubject;
use App\Gateway\Descriptor\AutonomyLevel;
use App\Models\AutonomyGrant;
use App\Models\EvaluationCase;
use App\Models\EvaluationResult;
use App\Models\EvaluationSuite;
use Database\Seeders\DomainSeeder;
use Database\Seeders\SafetyCorpusSeeder;
use Illuminate\Support\Str;

/*
 | Evaluation measures six dimensions, and autonomy consumes them (ADR-030).
 |
 | The point is not more numbers. It is that model reasoning, tool selection, policy, enforcement,
 | execution and postcondition fail for different reasons and are fixed by different people, and
 | averaging them produces a figure that can be high because of the dimension nobody cares about.
 */

beforeEach(fn () => $this->seed(DomainSeeder::class));

/** A subject that answers each case however the test scripted it, keyed by case key. */
function subjectAnswering(array $byKey): EvaluationSubject
{
    return new class($byKey) implements EvaluationSubject
    {
        public function __construct(private array $byKey) {}

        public function subjectKey(): string
        {
            return 'test-subject';
        }

        public function subjectRole(): ?string
        {
            // These doubles have no role, so they are graded only against cases that apply to every role.
            return null;
        }

        public function run(EvaluationCase $case): EvaluationObservation
        {
            return $this->byKey[$case->key] ?? new EvaluationObservation;
        }
    };
}

function suiteWith(array $cases): EvaluationSuite
{
    $suite = EvaluationSuite::create([
        'key' => 'test.'.Str::random(6), 'name' => 'test', 'kind' => 'quality', 'is_active' => true,
    ]);

    foreach ($cases as $key => $spec) {
        EvaluationCase::create([
            'suite_id' => $suite->id,
            'key' => $key,
            'input' => ['prompt' => 'x'],
            'expectation' => $spec['expectation'],
            'grader' => 'deterministic',
            'severity' => 'normal',
            'dimension' => $spec['dimension'],
            'is_active' => true,
        ]);
    }

    return $suite;
}

it('records the dimension on every result, denormalised from the case', function () {
    $suite = suiteWith([
        'a' => ['dimension' => EvaluationDimension::ToolSelection->value, 'expectation' => ['refusal' => false]],
        'b' => ['dimension' => EvaluationDimension::Reasoning->value, 'expectation' => ['refusal' => false]],
    ]);

    $run = app(EvaluationRunner::class)->withGrader(new DeterministicGrader)
        ->run($suite, subjectAnswering([]), ['agent_key' => 'x']);

    $dims = EvaluationResult::where('run_id', $run->id)->pluck('dimension', 'case_id')->values()->all();

    // Stored on the result, not looked up through the case: a result must stay readable as evidence
    // after somebody re-tags the case, or the stored history describes a run that never happened.
    expect($dims)->toContain('tool_selection', 'reasoning');
});

it('reports a rate per dimension, not only one number for the suite', function () {
    $suite = suiteWith([
        'tools-ok' => ['dimension' => EvaluationDimension::ToolSelection->value, 'expectation' => ['refusal' => false]],
        'tools-ok-2' => ['dimension' => EvaluationDimension::ToolSelection->value, 'expectation' => ['refusal' => false]],
        'thinks-bad' => ['dimension' => EvaluationDimension::Reasoning->value, 'expectation' => ['refusal' => true]],
    ]);

    // Refuses nothing: both tool-selection cases pass, the reasoning case fails.
    $run = app(EvaluationRunner::class)->withGrader(new DeterministicGrader)
        ->run($suite, subjectAnswering([]), ['agent_key' => 'x']);

    // ⭐ The overall figure is 0.667 and it is the wrong number to widen anything on: tool selection
    // is perfect and reasoning is broken, and those are fixed by different people.
    expect((float) $run->agreement_rate)->toBe(0.667);
    // ⚠ Cast: a rate of exactly 1 or 0 round-trips through JSON as an int, because JSON has one
    // number type. The value is right; only PHP's idea of its type changed on the way to the column.
    expect((float) $run->dimension_rates['tool_selection']['rate'])->toBe(1.0);
    expect((float) $run->dimension_rates['reasoning']['rate'])->toBe(0.0);
});

it('lets an autonomy grant require evidence in a named dimension', function () {
    AutonomyGrant::create([
        'uuid' => (string) Str::uuid(), 'name' => 'reasoning-gated', 'agent_key' => 'a',
        'max_level' => 'a2', 'status' => 'active',
        'min_agreement_rate' => 0.95,
        'min_agreement_dimension' => EvaluationDimension::Reasoning->value,
    ]);

    $resolver = app(AutonomyResolver::class);

    // Tool selection is excellent and reasoning is not. A grant that reads the OVERALL number would
    // be satisfied by the average; one that names the dimension is not.
    $decision = $resolver->resolve(new AutonomyRequest(
        agentKey: 'a',
        agreementRate: 0.97,
        dimensionRates: ['tool_selection' => 1.0, 'reasoning' => 0.5],
    ));

    expect($decision->level)->toBe(AutonomyLevel::A0);
    expect($decision->boundBy)->toContain('no autonomy grant');
});

it('applies the grant when the named dimension itself is good enough', function () {
    AutonomyGrant::create([
        'uuid' => (string) Str::uuid(), 'name' => 'reasoning-gated', 'agent_key' => 'a',
        'max_level' => 'a2', 'status' => 'active',
        'min_agreement_rate' => 0.95,
        'min_agreement_dimension' => EvaluationDimension::Reasoning->value,
    ]);

    $decision = app(AutonomyResolver::class)->resolve(new AutonomyRequest(
        agentKey: 'a',
        agreementRate: 0.60,           // a poor overall figure...
        dimensionRates: ['reasoning' => 0.99], // ...and excellent evidence where it matters
    ));

    expect($decision->level)->toBe(AutonomyLevel::A2);
});

it('does not fall back to the overall rate for a dimension nothing has measured', function () {
    AutonomyGrant::create([
        'uuid' => (string) Str::uuid(), 'name' => 'outcome-gated', 'agent_key' => 'a',
        'max_level' => 'a2', 'status' => 'active',
        'min_agreement_rate' => 0.5,
        'min_agreement_dimension' => EvaluationDimension::Outcome->value,
    ]);

    // ⛔ Falling back is exactly how a dimension nobody has measured comes to be treated as one that
    // was. No measurement is not a passing measurement.
    $decision = app(AutonomyResolver::class)->resolve(new AutonomyRequest(
        agentKey: 'a',
        agreementRate: 1.0,
        dimensionRates: ['tool_selection' => 1.0],
    ));

    expect($decision->level)->toBe(AutonomyLevel::A0);
});

it('keeps an ungated grant working exactly as before', function () {
    AutonomyGrant::create([
        'uuid' => (string) Str::uuid(), 'name' => 'ungated', 'agent_key' => 'a',
        'max_level' => 'a2', 'status' => 'active',
    ]);

    expect(app(AutonomyResolver::class)->resolve(new AutonomyRequest(agentKey: 'a'))->level)
        ->toBe(AutonomyLevel::A2);
});

it('keeps a proposed tool call distinguishable from one that was allowed', function () {
    // ⭐ The distinction ADR-030 exists to preserve. Collapsed into one list, "the model asked for
    // something dangerous" and "something dangerous happened" read identically - and those are the
    // two facts a safety corpus most needs to tell apart.
    $observation = new EvaluationObservation(
        toolCalls: [['tool' => 'erp.poll_overview'], ['tool' => 'facts.query_orders']],
        executedToolCalls: [['tool' => 'facts.query_orders', 'tool_call_id' => 7]],
        refusedToolCalls: [['tool' => 'erp.poll_overview', 'reason' => 'not cleared for agents']],
    );

    expect($observation->called('erp.poll_overview'))->toBeTrue();     // it asked
    expect($observation->executed('erp.poll_overview'))->toBeFalse();  // it did not happen
    expect($observation->wasRefused('erp.poll_overview'))->toBeTrue(); // and this is why

    expect($observation->executed('facts.query_orders'))->toBeTrue();
    expect($observation->toArray()['executed'])->toBe(['facts.query_orders']);
});

it('classifies safety cases by what each one actually measures', function () {
    $this->seed(SafetyCorpusSeeder::class);

    $core = fn () => EvaluationCase::whereHas('suite', fn ($q) => $q->where('key', 'safety.core'));

    // ⚠ Updated when the corpus gained role-neutral cases. It used to assert the whole suite was
    // `tool_selection`, which was true of the original twelve and stopped being true the moment
    // cases were added that ask a different question.
    //
    // An injection case asks "did it CALL something" - tool selection. A credential or bypass case
    // asks "did it DECIDE correctly" - reasoning. Two dimensions, because a failure in each blames
    // somebody different (ADR-030).
    expect($core()->pluck('dimension')->unique()->sort()->values()->all())
        ->toBe(['reasoning', 'tool_selection']);

    // The original twelve are deliberately left where they were. Re-bucketing a permanent corpus
    // while correcting a failed gate is the kind of tidying that looks like moving a goalpost.
    expect($core()->where('applies_to_role', 'support')->pluck('dimension')->unique()->values()->all())
        ->toBe(['tool_selection']);
});
