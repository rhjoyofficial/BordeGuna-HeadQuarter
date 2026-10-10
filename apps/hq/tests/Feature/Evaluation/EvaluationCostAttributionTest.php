<?php

use App\Agents\AgentEvaluationSubject;
use App\Agents\AgentResponseSchema;
use App\Agents\AgentRunner;
use App\Ai\ModelTier;
use App\Evaluation\DeterministicGrader;
use App\Evaluation\EvaluationCostAttributor;
use App\Evaluation\EvaluationObservation;
use App\Evaluation\EvaluationRunner;
use App\Evaluation\EvaluationSubject;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AiRun;
use App\Models\EvaluationCase;
use App\Models\EvaluationResult;
use App\Models\EvaluationRun;
use App\Models\EvaluationSuite;
use App\Models\User;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SupportBehaviourSuiteSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Support\Ai\FixtureProvider;

/*
 | docs/106, ruling docs/103 §2: `evaluation_runs.cost_usd` is attributed from the `ai_runs` evidence.
 |
 | P3-G2 attempt 5 recorded 0.000000 on both runs while its 96 model calls cost ~$0.89 estimated: the
 | column was never written. The ruling's requirements, each a test below: every model call and retry of
 | a case, aggregated idempotently with no double count; estimated and provider-reported kept apart;
 | success, failure, multiple calls, retries and missing costs; nothing invented; no gate outcome moved.
 */

function costSubject(array $script): EvaluationSubject
{
    return new class($script) implements EvaluationSubject
    {
        public function __construct(private array $script) {}

        public function subjectKey(): string
        {
            return 'cost-subject';
        }

        public function subjectRole(): ?string
        {
            return null;
        }

        public function run(EvaluationCase $case): EvaluationObservation
        {
            $entry = $this->script[$case->key];

            return $entry instanceof Closure ? $entry() : $entry;
        }
    };
}

function costSuite(array $keys, string $suiteKey = 'cost.suite'): EvaluationSuite
{
    $suite = EvaluationSuite::create(['key' => $suiteKey, 'name' => 'Cost suite', 'kind' => 'quality']);
    foreach ($keys as $key => $expectation) {
        EvaluationCase::create(['suite_id' => $suite->id, 'key' => $key, 'input' => [], 'expectation' => $expectation]);
    }

    return $suite;
}

/** One model call as ModelRuntime records it. */
function modelCall(string $correlation, string $estimate, array $overrides = []): AiRun
{
    return AiRun::create($overrides + [
        'correlation_id' => $correlation,
        'agent_key' => 'cost-subject',
        'task' => 'operator_question',
        'provider' => 'fixture',
        'model' => 'fixture-model',
        'status' => 'succeeded',
        'attempt' => 1,
        'input_tokens' => 100,
        'output_tokens' => 20,
        'estimated_cost_usd' => $estimate,
    ]);
}

function runCostSuite(EvaluationSuite $suite, EvaluationSubject $subject): EvaluationRun
{
    return app(EvaluationRunner::class)->withGrader(new DeterministicGrader)->run($suite, $subject, ['triggered_by' => 'test']);
}

it('attributes EVERY model call of a case to the run, not the one call the result links to', function () {
    $c = (string) Str::uuid();
    $first = modelCall($c, '0.010000');
    modelCall($c, '0.020000');
    modelCall($c, '0.030000');

    $suite = costSuite(['multi' => ['refusal' => false]]);
    $run = runCostSuite($suite, costSubject([
        'multi' => new EvaluationObservation(answer: 'ok', aiRunId: $first->id, correlationId: $c, outcome: 'answered', modelTurns: 3),
    ]));

    expect((string) $run->cost_usd)->toBe('0.060000')
        ->and((string) $run->cost_estimated_usd)->toBe('0.060000')
        ->and($run->cost_attribution['ai_runs'])->toBe(3)
        ->and($run->cost_attribution['complete'])->toBeTrue();
    expect(EvaluationResult::sole()->correlation_id)->toBe($c);
});

it('counts every retry and fallback attempt once, failed attempts included, and nothing outside the case', function () {
    $c = (string) Str::uuid();
    // Attempt 1: provider down before any usage. Attempt 2: billed, then failed. Attempt 3: served.
    modelCall($c, '0.000000', ['status' => 'failed', 'attempt' => 1, 'input_tokens' => 0, 'output_tokens' => 0, 'error' => 'provider_unavailable: down']);
    modelCall($c, '0.004000', ['status' => 'failed', 'attempt' => 2, 'provider' => 'fixture2', 'error' => 'structured_output_failed: bad']);
    $served = modelCall($c, '0.010000', ['attempt' => 3, 'provider' => 'fixture2']);
    // A model call of some other run, in the same minute: not evidence about this case.
    modelCall((string) Str::uuid(), '9.000000');
    modelCall((string) Str::uuid(), '5.000000', ['correlation_id' => null]);

    $suite = costSuite(['retry' => ['refusal' => false]]);
    $run = runCostSuite($suite, costSubject([
        'retry' => new EvaluationObservation(answer: 'ok', aiRunId: $served->id, correlationId: $c, outcome: 'answered', modelTurns: 1),
    ]));

    expect((string) $run->cost_usd)->toBe('0.014000')
        ->and($run->cost_attribution['ai_runs'])->toBe(3)
        ->and($run->cost_attribution['retry_attempts'])->toBe(2)
        ->and($run->cost_attribution['failed_attempts'])->toBe(2);
});

it('attributes the cost of a FAILED case exactly as a passed one: money spent is money spent', function () {
    $pass = (string) Str::uuid();
    $fail = (string) Str::uuid();
    modelCall($pass, '0.001000');
    modelCall($fail, '0.002000');
    modelCall($fail, '0.003000', ['status' => 'failed', 'attempt' => 2]);

    $suite = costSuite([
        'passes' => ['refusal' => true],
        'fails' => ['refusal' => true],
    ]);
    $run = runCostSuite($suite, costSubject([
        'passes' => new EvaluationObservation(refused: true, refusalReason: 'no', correlationId: $pass, outcome: 'refused', modelTurns: 1),
        'fails' => new EvaluationObservation(answer: 'did it', correlationId: $fail, outcome: 'failed', stopReason: 'model_error', modelTurns: 1),
    ]));

    expect($run->status)->toBe('failed')
        ->and($run->cases_failed)->toBe(1)
        ->and((string) $run->cost_usd)->toBe('0.006000')
        ->and($run->cost_attribution['results_linked'])->toBe(2);
});

it('is idempotent: attributing again gives the same number, and a correlation shared by two results counts once', function () {
    $c = (string) Str::uuid();
    $a = modelCall($c, '0.005000');
    modelCall($c, '0.005000');

    $suite = costSuite(['one' => ['refusal' => false], 'two' => ['refusal' => false]]);
    $run = runCostSuite($suite, costSubject([
        'one' => new EvaluationObservation(answer: 'ok', aiRunId: $a->id, correlationId: $c, outcome: 'answered', modelTurns: 2),
        'two' => new EvaluationObservation(answer: 'ok', aiRunId: $a->id, correlationId: $c, outcome: 'answered', modelTurns: 2),
    ]));

    expect((string) $run->cost_usd)->toBe('0.010000');

    $attributor = app(EvaluationCostAttributor::class);
    $attributor->attribute($run);
    $attributor->attribute($run->refresh());

    expect((string) $run->refresh()->cost_usd)->toBe('0.010000')
        ->and($run->cost_attribution['ai_runs'])->toBe(2);
});

it('keeps the provider-reported cost apart from the estimate, and leaves it NULL when no provider figure exists', function () {
    $c = (string) Str::uuid();
    modelCall($c, '0.010000', ['actual_cost_usd' => '0.012000', 'cost_source' => 'provider_invoice']);
    modelCall($c, '0.020000');

    $suite = costSuite(['reported' => ['refusal' => false]]);
    $run = runCostSuite($suite, costSubject([
        'reported' => new EvaluationObservation(answer: 'ok', correlationId: $c, outcome: 'answered', modelTurns: 2),
    ]));

    // Estimate untouched by the provider figure; the provider figure only over the rows that have one;
    // the headline is the budget ledger's convention, COALESCE(actual, estimate) per call.
    expect((string) $run->cost_estimated_usd)->toBe('0.030000')
        ->and((string) $run->cost_provider_usd)->toBe('0.012000')
        ->and((string) $run->cost_usd)->toBe('0.032000')
        ->and($run->cost_attribution['ai_runs_provider_reported'])->toBe(1);

    $c2 = (string) Str::uuid();
    modelCall($c2, '0.010000');
    $suite2 = costSuite(['estimate-only' => ['refusal' => false]], 'cost.suite.2');
    $run2 = runCostSuite($suite2, costSubject([
        'estimate-only' => new EvaluationObservation(answer: 'ok', correlationId: $c2, outcome: 'answered', modelTurns: 1),
    ]));

    expect($run2->cost_provider_usd)->toBeNull()
        ->and((string) $run2->cost_estimated_usd)->toBe('0.010000')
        ->and($run2->cost_attribution['ai_runs_provider_reported'])->toBe(0);
});

it('records a case that made no model call as zero and accounted, and a case with no evidence as unaccounted, never guessed', function () {
    $suite = costSuite(['not-woken' => ['refusal' => true]]);
    $run = runCostSuite($suite, costSubject([
        'not-woken' => new EvaluationObservation(refused: true, outcome: 'not_woken', agentWoken: false, modelTurns: 0),
    ]));

    expect((string) $run->cost_usd)->toBe('0.000000')
        ->and($run->cost_attribution['results_without_model_call'])->toBe(1)
        ->and($run->cost_attribution['complete'])->toBeTrue();

    // A run that threw before the subject could read its correlation: model calls may have happened,
    // and nothing links them. Recorded as unaccounted; no window or average fills the hole.
    modelCall((string) Str::uuid(), '1.000000');
    $suite2 = costSuite(['threw' => ['refusal' => true]], 'cost.suite.2');
    $run2 = runCostSuite($suite2, costSubject([
        'threw' => new EvaluationObservation(refused: true, refusalReason: 'the run could not be completed', outcome: 'failed', stopReason: 'exception'),
    ]));

    expect((string) $run2->cost_usd)->toBe('0.000000')
        ->and($run2->cost_attribution['results_unaccounted'])->toBe(1)
        ->and($run2->cost_attribution['complete'])->toBeFalse();
});

it('never changes the gate outcome: an attribution failure is recorded on the run and the verdict stands', function () {
    app()->instance(EvaluationCostAttributor::class, new class extends EvaluationCostAttributor
    {
        public function compute(EvaluationRun $run): array
        {
            throw new RuntimeException('ledger unreadable');
        }
    });

    $suite = costSuite(['ok' => ['refusal' => true]]);
    $run = runCostSuite($suite, costSubject([
        'ok' => new EvaluationObservation(refused: true, refusalReason: 'no', outcome: 'refused', modelTurns: 1, correlationId: (string) Str::uuid()),
    ]));

    expect($run->status)->toBe('passed')
        ->and($run->cases_passed)->toBe(1)
        ->and((float) $run->agreement_rate)->toBe(1.0)
        ->and($run->cost_estimated_usd)->toBeNull()
        ->and($run->cost_attribution['error'])->toContain('ledger unreadable');
});

it('attributes a real agent run end to end: every ai_runs row of the run\'s correlation, the fallback attempt included', function () {
    Cache::flush();
    FixtureProvider::reset();
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    aiProviders();
    enableAiRuntime();
    aiModel('fixture', 'fixture-model', ModelTier::Medium, priority: 10);
    aiModel('fixture2', 'second', ModelTier::Medium, priority: 50);
    $owner = User::factory()->create();
    $owner->givePermissionTo(['hq.tools.facts.read', 'hq.tools.erp.read', 'hq.tools.commerce.read', 'hq.approvals.view', 'hq.jobs.view']);
    $this->seed(AgentSeeder::class);
    Agent::query()->update(['status' => 'active', 'enabling_user_id' => $owner->id]);

    $refusal = FixtureProvider::reply((string) json_encode([
        'status' => AgentResponseSchema::STATUS_REFUSE, 'answer' => '', 'refusal_reason' => 'I will not do that.',
        'facts' => [], 'inferences' => [], 'tool_calls' => [], 'task' => 'the operator question', 'authority_request' => 'none',
    ]));
    // The first provider is down; the fallback serves. Two ai_runs rows, one correlation.
    FixtureProvider::script('fixture', [FixtureProvider::outage()]);
    FixtureProvider::script('fixture2', [$refusal]);

    $suite = costSuite(['e2e' => ['refusal' => true]]);
    EvaluationCase::where('key', 'e2e')->update(['input' => ['prompt' => 'What were yesterday\'s sales across the group?']]);

    $run = runCostSuite($suite, new AgentEvaluationSubject(app(AgentRunner::class), 'executive'));

    $agentRun = AgentRun::latest('id')->firstOrFail();
    $evidence = AiRun::where('correlation_id', $agentRun->correlation_id)->get();

    expect($evidence->count())->toBeGreaterThanOrEqual(2)
        ->and($evidence->pluck('attempt')->max())->toBeGreaterThanOrEqual(2);
    expect(EvaluationResult::sole()->correlation_id)->toBe($agentRun->correlation_id)
        ->and(EvaluationResult::sole()->ai_run_id)->toBe($evidence->min('id'));
    expect((float) $run->cost_usd)->toBeGreaterThan(0.0)
        ->and((string) $run->cost_usd)->toBe(number_format((float) $evidence->sum('estimated_cost_usd'), 6, '.', ''))
        ->and($run->cost_attribution['ai_runs'])->toBe($evidence->count())
        ->and($run->cost_provider_usd)->toBeNull();
});

it('attributes a Support run on a rolled-back thread: the model calls carried across the rollback are the evidence', function () {
    Cache::flush();
    FixtureProvider::reset();
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    aiProviders();
    enableAiRuntime();
    aiModel('fixture', 'fixture-model', ModelTier::Medium);
    $owner = User::factory()->create();
    $owner->givePermissionTo(['hq.tools.facts.read', 'hq.tools.erp.read', 'hq.tools.commerce.read', 'hq.tools.diagnostics',
        'hq.approvals.view', 'hq.jobs.view', 'hq.inbox.view', 'hq.inbox.manage']);
    $this->seed(AgentSeeder::class);
    Agent::query()->update(['status' => 'active', 'enabling_user_id' => $owner->id]);
    $this->seed(SupportBehaviourSuiteSeeder::class);

    $reply = fn (string $status, array $o = []) => FixtureProvider::reply((string) json_encode($o + [
        'status' => $status, 'answer' => '', 'refusal_reason' => '', 'facts' => [], 'inferences' => [], 'tool_calls' => [],
        'task' => 'answer the customer on this thread', 'authority_request' => AgentResponseSchema::AUTHORITY_NONE,
    ]));
    // Three model turns: read the thread, propose a reply, answer.
    FixtureProvider::script('fixture', [
        $reply(AgentResponseSchema::STATUS_NEED_EVIDENCE, ['tool_calls' => [
            ['tool' => 'conversation.read_thread', 'arguments' => ['business_slug' => 'laladia', 'conversation_uuid' => 'x'], 'why' => 'read'],
        ]]),
        $reply(AgentResponseSchema::STATUS_NEED_EVIDENCE, ['tool_calls' => [
            ['tool' => 'messaging.send_reply', 'arguments' => ['business_slug' => 'laladia', 'conversation_uuid' => 'x', 'text' => 'Your order is on its way.'], 'why' => 'reply'],
        ]]),
        $reply(AgentResponseSchema::STATUS_ANSWER, ['answer' => 'Proposed one reply for a person to send.']),
    ]);

    // One live case of the behaviour suite, alone in a suite of its own, so the script maps to it.
    $source = EvaluationCase::where('key', 'support.v2.order_status_in_english')->whereNull('retired_at')->sole();
    $suite = EvaluationSuite::create(['key' => 'cost.thread', 'name' => 'Cost thread', 'kind' => 'quality']);
    EvaluationCase::create(['suite_id' => $suite->id, 'key' => 'thread', 'input' => $source->input, 'expectation' => ['refusal' => false]]);

    $run = runCostSuite($suite, new AgentEvaluationSubject(app(AgentRunner::class), 'support'));

    $agentRun = AgentRun::sole();
    $evidence = AiRun::where('correlation_id', $agentRun->correlation_id)->get();

    expect($evidence)->toHaveCount(3)
        ->and(EvaluationResult::sole()->correlation_id)->toBe($agentRun->correlation_id)
        ->and($run->cost_attribution['ai_runs'])->toBe(3)
        ->and($run->cost_attribution['complete'])->toBeTrue()
        ->and((float) $run->cost_usd)->toBeGreaterThan(0.0)
        ->and((string) $run->cost_usd)->toBe(number_format((float) $evidence->sum('estimated_cost_usd'), 6, '.', ''));
});

it('backfills only from existing evidence: a dry run by default, and --apply writes complete runs only', function () {
    $suite = costSuite(['historic' => ['refusal' => false]]);
    $case = EvaluationCase::where('key', 'historic')->sole();

    // A run as attempt 5 left it: finished, cost 0, results linked by ai_run_id alone (no correlation column).
    $c = (string) Str::uuid();
    $linked = modelCall($c, '0.400000');
    modelCall($c, '0.090000', ['attempt' => 2]);
    $old = EvaluationRun::create(['uuid' => (string) Str::uuid(), 'suite_id' => $suite->id, 'status' => 'passed', 'cases_total' => 1, 'cases_passed' => 1, 'agreement_rate' => 1.0, 'finished_at' => now()]);
    EvaluationResult::create(['run_id' => $old->id, 'case_id' => $case->id, 'passed' => true, 'grader' => 'deterministic', 'ai_run_id' => $linked->id, 'actual' => ['model_turns' => 1]]);

    // A run whose result has no evidence and no turn count: cannot be attributed honestly.
    $gap = EvaluationRun::create(['uuid' => (string) Str::uuid(), 'suite_id' => $suite->id, 'status' => 'failed', 'cases_total' => 1, 'cases_failed' => 1, 'agreement_rate' => 0.0, 'finished_at' => now()]);
    EvaluationResult::create(['run_id' => $gap->id, 'case_id' => $case->id, 'passed' => false, 'grader' => 'deterministic', 'actual' => []]);

    $this->artisan('hq:evaluation:attribute-cost')
        ->expectsOutputToContain('DRY RUN')
        ->assertSuccessful();

    expect((string) $old->refresh()->cost_usd)->toBe('0.000000')
        ->and($old->cost_attribution)->toBeNull();

    $this->artisan('hq:evaluation:attribute-cost', ['--apply' => true])->assertSuccessful();

    expect((string) $old->refresh()->cost_usd)->toBe('0.490000')
        ->and($old->cost_attribution['complete'])->toBeTrue()
        ->and($old->status)->toBe('passed');
    expect((string) $gap->refresh()->cost_usd)->toBe('0.000000')
        ->and($gap->cost_attribution)->toBeNull()
        ->and($gap->status)->toBe('failed');
});
