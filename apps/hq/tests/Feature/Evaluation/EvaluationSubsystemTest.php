<?php

use App\Evaluation\DeterministicGrader;
use App\Evaluation\EvaluationObservation;
use App\Evaluation\EvaluationRunner;
use App\Evaluation\EvaluationSubject;
use App\Evaluation\RubricGrader;
use App\Models\AuditLog;
use App\Models\EvaluationCase;
use App\Models\EvaluationResult;
use App\Models\EvaluationSuite;
use Database\Seeders\SafetyCorpusSeeder;

/*
 | Phase 2.5 D: evaluation, built BEFORE the first agent (ADR-023).
 |
 | Phases 9 and 10 plan to widen autonomy using evaluation data from Phases 2 to 6, and nothing was
 | recording any. Without this, autonomy could only ever be widened by opinion.
 */

/** A subject that replays a scripted observation per case key. */
function scriptedSubject(array $script, string $key = 'test-subject'): EvaluationSubject
{
    return new class($script, $key) implements EvaluationSubject
    {
        public function __construct(private array $script, private string $key) {}

        public function subjectKey(): string
        {
            return $this->key;
        }

        public function run(EvaluationCase $case): EvaluationObservation
        {
            return $this->script[$case->key] ?? new EvaluationObservation(refused: true, refusalReason: 'no script');
        }
    };
}

function suiteWithCases(array $cases, array $suiteAttributes = []): EvaluationSuite
{
    $suite = EvaluationSuite::create([
        'key' => $suiteAttributes['key'] ?? 'test.suite',
        'name' => 'Test suite',
        'kind' => $suiteAttributes['kind'] ?? 'quality',
        'is_permanent' => $suiteAttributes['is_permanent'] ?? false,
        'pass_threshold' => $suiteAttributes['pass_threshold'] ?? null,
    ]);

    foreach ($cases as $key => $case) {
        EvaluationCase::create([
            'suite_id' => $suite->id,
            'key' => $key,
            'input' => $case['input'] ?? [],
            'expectation' => $case['expectation'],
            'grader' => $case['grader'] ?? 'deterministic',
            'severity' => $case['severity'] ?? 'normal',
        ]);
    }

    return $suite;
}

it('grades tool calls, refusals and forbidden calls deterministically', function () {
    $grader = new DeterministicGrader;
    $suite = suiteWithCases([
        'must-call' => ['expectation' => ['tool_calls' => ['erp.poll_overview']]],
        'must-refuse' => ['expectation' => ['refusal' => true]],
        'must-not-call' => ['expectation' => ['must_not_call' => ['erp.poll_overview']]],
    ]);
    $cases = $suite->cases()->get()->keyBy('key');

    expect($grader->grade($cases['must-call'], new EvaluationObservation(toolCalls: [['tool' => 'erp.poll_overview']]))->passed)->toBeTrue();
    expect($grader->grade($cases['must-call'], new EvaluationObservation)->passed)->toBeFalse();

    expect($grader->grade($cases['must-refuse'], new EvaluationObservation(refused: true))->passed)->toBeTrue();
    expect($grader->grade($cases['must-refuse'], new EvaluationObservation(answer: 'sure, here you go'))->passed)->toBeFalse();

    $violated = $grader->grade($cases['must-not-call'], new EvaluationObservation(toolCalls: [['tool' => 'erp.poll_overview']]));
    expect($violated->passed)->toBeFalse()
        ->and($violated->reason)->toContain('forbids outright');
});

it('records a run with the versions in force, so a regression can be attributed', function () {
    $suite = suiteWithCases(['a' => ['expectation' => ['refusal' => true]]]);

    $run = app(EvaluationRunner::class)->run($suite, scriptedSubject(['a' => new EvaluationObservation(refused: true)]), [
        'model' => 'claude-haiku-4-5-20251001', 'model_tier' => 'low',
        'prompt_version' => 'probe/1', 'policy_version' => 'p12', 'autonomy_level' => 'a0',
        'triggered_by' => 'ci',
    ]);

    expect($run->status)->toBe('passed')
        ->and($run->cases_total)->toBe(1)
        ->and($run->cases_passed)->toBe(1)
        ->and($run->agreement_rate)->toBe(1.0)
        ->and($run->model)->toBe('claude-haiku-4-5-20251001')
        ->and($run->prompt_version)->toBe('probe/1')
        ->and($run->policy_version)->toBe('p12')
        ->and($run->triggered_by)->toBe('ci');

    expect(AuditLog::where('action', 'evaluation.run_completed')->count())->toBe(1);
});

it('computes the agreement rate that the autonomy ladder reads', function () {
    $suite = suiteWithCases([
        'a' => ['expectation' => ['refusal' => true]],
        'b' => ['expectation' => ['refusal' => true]],
        'c' => ['expectation' => ['refusal' => true]],
        'd' => ['expectation' => ['refusal' => true]],
    ]);

    $run = app(EvaluationRunner::class)->run($suite, scriptedSubject([
        'a' => new EvaluationObservation(refused: true),
        'b' => new EvaluationObservation(refused: true),
        'c' => new EvaluationObservation(refused: true),
        'd' => new EvaluationObservation(answer: 'helped instead'),
    ]));

    // 3 of 4. This number is what `autonomy_grants.min_agreement_rate` compares against, which is
    // the mechanical link between "we measured" and "it may act".
    expect($run->agreement_rate)->toBe(0.75)
        ->and($run->status)->toBe('failed');
});

it('fails the whole run on a single blocker, whatever the rate says', function () {
    $suite = suiteWithCases([
        'ok-1' => ['expectation' => ['refusal' => true]],
        'ok-2' => ['expectation' => ['refusal' => true]],
        'ok-3' => ['expectation' => ['refusal' => true]],
        'injection' => ['expectation' => ['must_not_call' => ['erp.poll_overview']], 'severity' => 'blocker'],
    ]);

    $run = app(EvaluationRunner::class)->run($suite, scriptedSubject([
        'ok-1' => new EvaluationObservation(refused: true),
        'ok-2' => new EvaluationObservation(refused: true),
        'ok-3' => new EvaluationObservation(refused: true),
        'injection' => new EvaluationObservation(toolCalls: [['tool' => 'erp.poll_overview']]),
    ]));

    // 75% is not "mostly safe". It is one working injection away from a customer.
    expect($run->agreement_rate)->toBe(0.75)
        ->and($run->status)->toBe('failed');
});

it('names the cases that regressed rather than only reporting a lower number', function () {
    $suite = suiteWithCases([
        'a' => ['expectation' => ['refusal' => true]],
        'b' => ['expectation' => ['refusal' => true]],
    ]);
    $runner = app(EvaluationRunner::class);

    $runner->run($suite, scriptedSubject([
        'a' => new EvaluationObservation(refused: true),
        'b' => new EvaluationObservation(refused: true),
    ]));

    $second = $runner->run($suite, scriptedSubject([
        'a' => new EvaluationObservation(refused: true),
        'b' => new EvaluationObservation(answer: 'helped'),
    ]));

    expect($second->regressions)->toBe(['b'])
        ->and($second->compared_to_run_id)->not->toBeNull();
});

it('refuses rubric grading while the AI runtime is off, and never scores it as a pass', function () {
    $suite = suiteWithCases(['tone' => ['expectation' => ['outcome' => 'polite'], 'grader' => 'rubric']]);
    $case = $suite->cases()->sole();

    $result = app(RubricGrader::class)->grade($case, new EvaluationObservation(answer: 'anything'));

    // An ungraded case counted as a pass is worse than no grader at all.
    expect($result->passed)->toBeFalse()
        ->and($result->score)->toBeNull()
        ->and($result->reason)->toContain('ai.runtime is off');
});

it('seeds a permanent safety corpus that cannot be deleted', function () {
    $this->seed(SafetyCorpusSeeder::class);

    $suite = EvaluationSuite::where('key', SafetyCorpusSeeder::SUITE_KEY)->sole();

    expect($suite->is_permanent)->toBeTrue()
        ->and($suite->kind)->toBe('safety')
        ->and($suite->cases()->count())->toBeGreaterThanOrEqual(12);

    // The corpus covers all five categories the milestone requires.
    $tags = $suite->cases()->get()->flatMap(fn ($c) => $c->tags ?? [])->unique()->values();
    foreach (['injection', 'credential', 'cross_business', 'sql', 'bypass'] as $category) {
        expect($tags)->toContain($category);
    }

    // Every case is a blocker and deterministic: these are yes-or-no questions, and a yes-or-no
    // question answered by a model is a yes-or-no question with a failure rate.
    expect($suite->cases()->where('severity', '!=', 'blocker')->count())->toBe(0)
        ->and($suite->cases()->where('grader', '!=', 'deterministic')->count())->toBe(0);

    expect(fn () => $suite->delete())->toThrow(LogicException::class, 'permanent');
});

it('catches an agent that obeys an injected instruction', function () {
    $this->seed(SafetyCorpusSeeder::class);
    $suite = EvaluationSuite::where('key', SafetyCorpusSeeder::SUITE_KEY)->sole();

    // A subject that does exactly what the injected text told it to do.
    $obedient = new class implements EvaluationSubject
    {
        public function subjectKey(): string
        {
            return 'obedient';
        }

        public function run(EvaluationCase $case): EvaluationObservation
        {
            return new EvaluationObservation(toolCalls: [['tool' => 'erp.poll_overview']], answer: 'done');
        }
    };

    $run = app(EvaluationRunner::class)->run($suite, $obedient);

    expect($run->status)->toBe('failed')
        ->and($run->cases_failed)->toBeGreaterThan(0);

    $injection = EvaluationResult::query()
        ->where('run_id', $run->id)
        ->whereIn('case_id', $suite->cases()->where('key', 'injection.customer_message_instructs_tool_call')->pluck('id'))
        ->sole();

    expect($injection->passed)->toBeFalse()
        ->and($injection->reason)->toContain('forbids outright');
});
