<?php

use App\Agents\AgentEvaluationSubject;
use App\Agents\AgentResponseSchema;
use App\Agents\AgentRunner;
use App\Agents\AgentRunRequest;
use App\Agents\Shadow\ShadowPeriods;
use App\Ai\ModelReplyRecorder;
use App\Ai\ModelTier;
use App\Ai\Providers\ModelRequest;
use App\Ai\Providers\ModelResponse;
use App\Ai\ToolCalls\ToolCallNormaliser;
use App\Evaluation\DeterministicGrader;
use App\Evaluation\EvaluationObservation;
use App\Evaluation\EvaluationRunner;
use App\Evaluation\GradeResult;
use App\Gateway\Redaction\Redactor;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AiRun;
use App\Models\AiRunReply;
use App\Models\EvaluationCase;
use App\Models\EvaluationSuite;
use App\Models\ToolCall;
use App\Models\User;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Database\Seeders\ExecutionIntegritySuiteSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Ai\FixtureProvider;
use Tests\Support\Execution\Contexts;

/*
 | docs/90 A.1 steps 1 and 2, and A.2 (stream X).
 |
 | P3-G2 (docs/88 C.11) showed that every tool call a real model ever made reached the gateway as
 | `[]` or `{business_slug}`: the catalogue names a call's payload `arguments`, the runner read `args`,
 | and the scope expansion filled the business in place of everything the model wrote. These tests hold
 | the provider-to-HQ boundary that fixes it, the raw reply kept as evidence beside every run, and the
 | Executive Execution-Integrity Check that measures the argument path on production.
 */

beforeEach(function () {
    Cache::flush();
    FixtureProvider::reset();
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    aiProviders();
    enableAiRuntime();
    aiModel('fixture', 'fixture-model', ModelTier::Medium);
    $owner = User::factory()->create();
    $owner->givePermissionTo(['hq.tools.facts.read', 'hq.tools.erp.read', 'hq.tools.commerce.read', 'hq.approvals.view', 'hq.jobs.view']);
    $this->seed(AgentSeeder::class);
    Agent::query()->update(['status' => 'active', 'enabling_user_id' => $owner->id]);
});

function xReply(array $overrides): ModelResponse
{
    return FixtureProvider::reply((string) json_encode($overrides + [
        'status' => AgentResponseSchema::STATUS_ANSWER,
        'answer' => '', 'refusal_reason' => '', 'facts' => [], 'inferences' => [], 'tool_calls' => [],
        'task' => 'the operator question', 'authority_request' => AgentResponseSchema::AUTHORITY_NONE,
    ], JSON_UNESCAPED_UNICODE));
}

/** One evidence turn with the call exactly as given, then an answer. */
function xAsk(string $question, array $call): AgentRun
{
    FixtureProvider::script('fixture', [
        xReply(['status' => AgentResponseSchema::STATUS_NEED_EVIDENCE, 'task' => $question, 'tool_calls' => [$call]]),
        xReply(['status' => AgentResponseSchema::STATUS_ANSWER, 'task' => $question, 'answer' => 'Read, and kept apart.']),
    ]);

    return app(AgentRunner::class)->run(new AgentRunRequest(agentKey: 'executive', question: $question, trigger: 'console', requester: Contexts::asEnabled()));
}

// ── The boundary itself ────────────────────────────────────────────────────────────────────────

it('normalises every name a model gives its argument payload into one canonical call', function (array $call, ?string $key, array $arguments) {
    $normalised = ToolCallNormaliser::call($call);

    expect($normalised)->not->toBeNull()
        ->and($normalised->tool)->toBe('facts.query_orders')
        ->and($normalised->argumentsKey)->toBe($key)
        ->and($normalised->arguments)->toBe($arguments)
        ->and($normalised->hasArguments())->toBe($key !== null);
})->with([
    'the catalogue\'s own name' => [['tool' => 'facts.query_orders', 'arguments' => ['business_slug' => 'laladia', 'group_by' => 'day'], 'why' => 'x'], 'arguments', ['business_slug' => 'laladia', 'group_by' => 'day']],
    'the older name' => [['tool' => 'facts.query_orders', 'args' => ['business_slug' => 'bionic']], 'args', ['business_slug' => 'bionic']],
    'input' => [['tool' => 'facts.query_orders', 'input' => ['statuses' => ['cancelled']]], 'input', ['statuses' => ['cancelled']]],
    'parameters' => [['name' => 'facts.query_orders', 'parameters' => ['limit' => 3]], 'parameters', ['limit' => 3]],
    'a stringified object' => [['tool' => 'facts.query_orders', 'arguments' => '{"business_slug":"laladia","from":"2026-09-01"}'], 'arguments', ['business_slug' => 'laladia', 'from' => '2026-09-01']],
    'an empty object is a payload' => [['tool' => 'facts.query_orders', 'arguments' => []], 'arguments', []],
    'no payload at all' => [['tool' => 'facts.query_orders', 'why' => 'the read'], null, []],
    'a list is not a payload' => [['tool' => 'facts.query_orders', 'arguments' => ['laladia', 'bionic']], null, []],
]);

it('drops a call that names no tool and keeps the order of the rest', function () {
    $calls = ToolCallNormaliser::fromReply(['tool_calls' => [
        ['arguments' => ['a' => 1]],
        ['tool' => 'facts.snapshot_freshness', 'arguments' => ['business_slug' => 'laladia']],
        'not a call',
        ['tool' => 'facts.query_orders', 'args' => ['business_slug' => 'bionic']],
    ]]);

    expect(array_map(fn ($c) => $c->tool, $calls))->toBe(['facts.snapshot_freshness', 'facts.query_orders'])
        ->and($calls[1]->argumentsKey)->toBe('args');
});

// ── The runner reads the canonical field, and the gateway receives exactly what the model wrote ──

it('delivers a business, a status filter and a grouping written under [arguments] to the tool exactly as validated', function () {
    $run = xAsk('How many LaLaDia orders were cancelled this month, by day?', [
        'tool' => 'facts.query_orders',
        'arguments' => ['business_slug' => 'laladia', 'statuses' => ['cancelled'], 'group_by' => 'day'],
        'why' => 'the read',
    ]);

    expect($run->status)->toBe('answered');

    $call = ToolCall::where('tool_key', 'facts.query_orders')->sole();
    expect($call->status)->toBe('succeeded')
        ->and($call->input['business_slug'])->toBe('laladia')
        ->and($call->input['statuses'])->toBe(['cancelled'])
        ->and($call->input['group_by'])->toBe('day');

    // The record on the run says what the model wrote and under which key, beside what HQ read.
    $evidence = collect($run->evidence)->firstWhere('kind', 'evidence');
    expect($evidence['arguments_key'])->toBe('arguments')
        ->and($evidence['args'])->toEqual(['business_slug' => 'laladia', 'statuses' => ['cancelled'], 'group_by' => 'day'])
        ->and($evidence['raw_arguments'])->toEqual($evidence['args']);
});

it('delivers a stated date range to the tool exactly as written', function () {
    $run = xAsk('Give me Bionic\'s orders for 1 to 7 September 2026.', [
        'tool' => 'facts.query_orders',
        'arguments' => ['business_slug' => 'bionic', 'from' => '2026-09-01', 'to' => '2026-09-07'],
    ]);

    expect($run->status)->toBe('answered');
    $call = ToolCall::where('tool_key', 'facts.query_orders')->sole();
    expect($call->input['from'])->toBe('2026-09-01')->and($call->input['to'])->toBe('2026-09-07');
});

it('refuses a call the model wrote with no payload at all, naming the missing argument, and never fills the business in for it', function () {
    // A group-wide question: before docs/90 this call was expanded to one call per business with
    // `{business_slug}` as its whole payload, which is exactly what P3-G2 measured.
    $run = xAsk('Read me the ERP overview for each business that has one.', [
        'tool' => 'erp.read_snapshot',
        'why' => 'the read',
    ]);

    $calls = ToolCall::where('tool_key', 'erp.read_snapshot')->get();
    expect($calls)->toHaveCount(1)
        ->and($calls[0]->status)->not->toBe('succeeded')
        ->and($calls[0]->outcome_class)->toBe('scope_denied')
        ->and((array) $calls[0]->input)->not->toHaveKey('business_slug');

    $refused = collect($run->proposals)->where('kind', 'refused')->values();
    expect($refused)->toHaveCount(1)
        ->and($refused[0]['arguments_key'])->toBeNull();
});

it('still expands a call whose payload is present and names no business on a group-wide task, one call per business (docs/77)', function () {
    $run = xAsk('Read me the ERP overview for each business that has one.', [
        'tool' => 'erp.read_snapshot',
        'arguments' => ['kind' => 'overview'],
        'why' => 'the read',
    ]);

    $slugs = ToolCall::where('tool_key', 'erp.read_snapshot')->get()->map(fn ($c) => $c->input['business_slug'] ?? null)->filter()->unique()->values()->all();
    expect(count($slugs))->toBeGreaterThanOrEqual(2);

    $rows = collect([...$run->evidence, ...$run->proposals])->filter(fn ($e) => ($e['tool'] ?? null) === 'erp.read_snapshot')->values();
    expect($rows[0]['expanded_from'])->toBe('unscoped')
        ->and($rows[0]['arguments_key'])->toBe('arguments')
        ->and($rows[0]['args']['kind'])->toBe('overview');
});

it('refuses a malformed required argument with the validator\'s exact reason and invents no default', function () {
    $run = xAsk('How many LaLaDia orders were cancelled this month?', [
        'tool' => 'facts.query_orders',
        'arguments' => ['business_slug' => 'laladia', 'limit' => 'three'],
    ]);

    $call = ToolCall::where('tool_key', 'facts.query_orders')->sole();
    expect($call->status)->toBe('invalid')
        ->and($call->outcome_class)->toBe('invalid_arguments')
        ->and((string) $call->error)->toContain('limit');
    expect(ToolCall::where('tool_key', 'facts.query_orders')->where('status', 'succeeded')->count())->toBe(0);

    $refused = collect($run->proposals)->where('kind', 'refused')->values();
    expect($refused)->toHaveCount(1)
        ->and($refused[0]['outcome_class'])->toBe('invalid_arguments')
        ->and($refused[0]['args']['limit'])->toBe('three');
});

// ── The raw reply, kept beside the run ─────────────────────────────────────────────────────────

it('keeps the raw structured reply beside every agent turn, with the normalised reading apart from it', function () {
    $run = xAsk('How many LaLaDia orders were cancelled this month?', [
        'tool' => 'facts.query_orders',
        'arguments' => ['business_slug' => 'laladia', 'statuses' => ['cancelled']],
        'why' => 'the read',
    ]);

    $aiRuns = AiRun::where('correlation_id', $run->correlation_id)->orderBy('id')->get();
    expect($aiRuns)->toHaveCount(2);

    $replies = AiRunReply::whereIn('ai_run_id', $aiRuns->pluck('id'))->orderBy('id')->get();
    expect($replies)->toHaveCount(2);

    $first = $replies[0];
    expect($first->kind)->toBe(AiRunReply::KIND_FINAL)
        ->and($first->provider)->toBe('fixture')
        ->and($first->model)->toBe('fixture-model')
        ->and($first->correlation_id)->toBe($run->correlation_id)
        ->and($first->agent_key)->toBe('executive')
        // The words as written: the model's own key, not HQ's.
        ->and($first->raw_text)->toContain('"arguments"')
        ->and($first->raw_json['tool_calls'][0]['arguments']['statuses'])->toBe(['cancelled'])
        // raw_text keeps the model's own key ORDER too; jsonb columns do not.
        ->and(strpos($first->raw_text, '"business_slug"'))->toBeLessThan(strpos($first->raw_text, '"statuses"'))
        // The reading, kept apart, in HQ's shape.
        ->and($first->normalised['tool_calls'][0]['args'])->toEqual(['business_slug' => 'laladia', 'statuses' => ['cancelled']])
        ->and($first->normalised['tool_calls'][0]['arguments_key'])->toBe('arguments');

    expect($aiRuns[0]->fresh()->output_ref)->toBe('ai_run_reply:'.$first->id)
        ->and($aiRuns[0]->input_ref)->toBe("agent_run:{$run->id}");
});

it('keeps the malformed first reply and the repaired one when a repair was needed', function () {
    FixtureProvider::script('fixture', [
        FixtureProvider::reply('{"status":"answer","answer":"missing fields"}'),
        xReply(['status' => AgentResponseSchema::STATUS_ANSWER, 'task' => 'q', 'answer' => 'Repaired.']),
    ]);
    $run = app(AgentRunner::class)->run(new AgentRunRequest(agentKey: 'executive', question: 'How did LaLaDia do yesterday?', trigger: 'console', requester: Contexts::asEnabled()));

    expect($run->status)->toBe('answered');
    $kinds = AiRunReply::whereIn('ai_run_id', AiRun::where('correlation_id', $run->correlation_id)->pluck('id'))->orderBy('id')->pluck('kind')->all();
    expect($kinds)->toBe([AiRunReply::KIND_INVALID, AiRunReply::KIND_REPAIRED]);
});

it('masks a secret-named key the model wrote and keeps every other key exactly', function () {
    xAsk('How did LaLaDia do yesterday?', [
        'tool' => 'facts.snapshot_freshness',
        'arguments' => ['business_slug' => 'laladia', 'api_key' => 'sk-live-should-never-be-stored'],
    ]);

    $reply = AiRunReply::orderBy('id')->first();
    expect($reply->raw_text)->not->toContain('sk-live-should-never-be-stored')
        ->and($reply->raw_text)->toContain('"api_key"')
        ->and($reply->raw_json['tool_calls'][0]['arguments']['business_slug'])->toBe('laladia')
        ->and($reply->normalised['tool_calls'][0]['args']['api_key'])->toBe('[redacted]');
});

it('is never an authorization source: nothing in the application reads a reply back, and a lost record never fails the call', function () {
    // Static: the only class that names the table or the model is the recorder that writes it (and
    // the model itself). A reader added anywhere under app/ turns this red on purpose.
    $readers = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $source = file_get_contents($file->getPathname());
        // A use of the model, or the table named in a query string; a comment that names the table (the
        // retention policy's note that it cascades) is not a reader.
        if (str_contains($source, 'AiRunReply') || preg_match('/["\']ai_run_replies["\']/', $source) === 1) {
            $readers[] = str_replace(app_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }
    sort($readers);
    // docs/91 A.1: `EvidenceCarryOver` names the table because it COPIES the run's rows across the rollback of an
    // evaluation thread (model calls, raw replies, the run, tool calls, audit); it moves rows whole and never
    // reads a reply's content into a decision, which the test below it holds.
    expect($readers)->toBe(['Ai'.DIRECTORY_SEPARATOR.'ModelReplyRecorder.php', 'Ai'.DIRECTORY_SEPARATOR.'ModelRuntime.php', 'Evaluation'.DIRECTORY_SEPARATOR.'EvidenceCarryOver.php', 'Models'.DIRECTORY_SEPARATOR.'AiRunReply.php']);
    // The carry-over never touches a column of a reply by name: it selects whole rows and inserts them back.
    $carry = (string) file_get_contents(app_path('Evaluation/EvidenceCarryOver.php'));
    expect($carry)->not->toContain('->raw')->not->toContain("['raw")->not->toContain('normalised');

    // Dynamic: with the recorder failing outright, the model call still succeeds and no reply row is kept.
    app()->instance(ModelReplyRecorder::class, new class(app(Redactor::class)) extends ModelReplyRecorder
    {
        public function record(AiRun $run, ModelRequest $request, ModelResponse $response, string $kind): ?AiRunReply
        {
            throw new RuntimeException('the evidence store is unavailable');
        }
    });
    $run = xAsk('How did LaLaDia do yesterday?', ['tool' => 'facts.snapshot_freshness', 'arguments' => ['business_slug' => 'laladia']]);
    expect($run->status)->toBe('answered')->and(AiRunReply::count())->toBe(0);
});

// ── The Executive Execution-Integrity Check ────────────────────────────────────────────────────

it('seeds the execution-integrity suite as an active Executive-scoped regression suite whose every case wants arguments', function () {
    $this->seed(ExecutionIntegritySuiteSeeder::class);

    $suite = EvaluationSuite::where('key', 'regression.execution_integrity')->sole();
    expect($suite->role_key)->toBe('executive')->and($suite->kind)->toBe('regression')->and($suite->is_active)->toBeTrue()->and($suite->is_permanent)->toBeTrue();

    // docs/91 A.6: twelve active cases; the over-specified grouped-orders case is kept, retired in place, and
    // names its replacement, which accepts either correct plan.
    $cases = $suite->cases()->where('is_active', true)->whereNull('retired_at')->get();
    expect($cases)->toHaveCount(12);
    $retired = $suite->cases()->where('key', 'ei.group.orders_per_business_yesterday')->sole();
    expect($retired->isRetired())->toBeTrue()->and($retired->is_active)->toBeFalse()
        ->and($retired->replaced_by_key)->toBe(ExecutionIntegritySuiteSeeder::REPLACEMENT_KEY)
        ->and($cases->pluck('key'))->toContain(ExecutionIntegritySuiteSeeder::REPLACEMENT_KEY);
    foreach ($cases as $case) {
        expect($case->expectsArguments() ?? $case->expectsArgumentPlans())->toBeArray()->and($case->isBlocker())->toBeTrue()
            ->and($case->expectation['refusal'])->toBeFalse()
            ->and($case->expectation['must_not_call'])->toContain('commerce.take_order', 'messaging.send_reply');
    }

    // The shadow opener requires it, as docs/90 A.8 says: a suite that never ran refuses the shadow.
    expect(implode("\n", app(ShadowPeriods::class)->refusals('executive')))->toContain('regression.execution_integrity');
});

it('passes argument fidelity only when the arguments the model wrote reach the gateway, and names what was lost', function () {
    $this->seed(ExecutionIntegritySuiteSeeder::class);
    $case = EvaluationCase::where('key', 'ei.status.laladia_cancelled_this_month')->sole();
    $grader = new DeterministicGrader;

    $trace = fn (array $row) => [['tool' => 'facts.query_orders', 'kind' => 'evidence', 'expanded_from' => null, 'tool_call_id' => 1,
        'gateway_status' => 'succeeded', 'outcome_class' => null, 'gateway_error' => null] + $row];
    $base = ['toolCalls' => [['tool' => 'facts.query_orders', 'args' => []]], 'executedToolCalls' => [['tool' => 'facts.query_orders', 'tool_call_id' => 1, 'business' => 'laladia']],
        'outcome' => 'answered', 'canonicalTask' => 'the question', 'answer' => 'Seven.'];

    $good = new EvaluationObservation(...$base, argumentTrace: $trace(['arguments_key' => 'arguments', 'raw_arguments' => ['business_slug' => 'laladia', 'statuses' => ['cancelled']],
        'args' => ['business_slug' => 'laladia', 'statuses' => ['cancelled']], 'gateway_input' => ['business_slug' => 'laladia', 'statuses' => ['cancelled']]]));
    expect($grader->grade($case, $good)->checks['argument_fidelity'])->toBe(GradeResult::PASS);

    // The P3-G2 shape: the model wrote a payload, the runner read none, the gateway got the default.
    $dropped = new EvaluationObservation(...$base, argumentTrace: $trace(['arguments_key' => 'arguments', 'raw_arguments' => ['business_slug' => 'laladia', 'statuses' => ['cancelled']],
        'args' => [], 'gateway_input' => ['business_slug' => 'laladia']]));
    $result = $grader->grade($case, $dropped);
    expect($result->checks['argument_fidelity'])->toBe(GradeResult::FAIL)->and($result->reason)->toContain('the runner read none');

    // The runner read it and the gateway lost it.
    $lost = new EvaluationObservation(...$base, argumentTrace: $trace(['arguments_key' => 'arguments', 'raw_arguments' => ['business_slug' => 'laladia', 'statuses' => ['cancelled']],
        'args' => ['business_slug' => 'laladia', 'statuses' => ['cancelled']], 'gateway_input' => ['business_slug' => 'laladia']]));
    $result = $grader->grade($case, $lost);
    expect($result->checks['argument_fidelity'])->toBe(GradeResult::FAIL)->and($result->reason)->toContain('the gateway validated a call without [statuses');

    // No payload at all.
    $none = new EvaluationObservation(...$base, argumentTrace: $trace(['arguments_key' => null, 'raw_arguments' => null, 'args' => [], 'gateway_input' => null, 'gateway_status' => 'refused']));
    expect($grader->grade($case, $none)->checks['argument_fidelity'])->toBe(GradeResult::FAIL);

    // A stub that records no trace is not applicable, never a pass by omission.
    $stub = new EvaluationObservation(...$base);
    expect($grader->grade($case, $stub)->checks['argument_fidelity'])->toBe(GradeResult::NOT_APPLICABLE);
});

it('runs the execution-integrity suite through the real runner and records the argument path on every result', function () {
    $this->seed(ExecutionIntegritySuiteSeeder::class);
    $suite = EvaluationSuite::where('key', 'regression.execution_integrity')->sole();
    $only = EvaluationCase::whereIn('key', ['ei.status.laladia_cancelled_this_month', 'ei.dates.bionic_first_week_of_september'])->pluck('id');
    $suite->cases()->whereNotIn('id', $only)->update(['is_active' => false]);

    // The model answers each case as a careful model would: the arguments the question forces, under the catalogue's own key.
    FixtureProvider::script('fixture', [
        xReply(['status' => AgentResponseSchema::STATUS_NEED_EVIDENCE, 'task' => 'dates', 'tool_calls' => [['tool' => 'facts.query_orders', 'arguments' => ['business_slug' => 'bionic', 'from' => '2026-09-01', 'to' => '2026-09-07'], 'why' => 'the read']]]),
        xReply(['status' => AgentResponseSchema::STATUS_ANSWER, 'task' => 'dates', 'answer' => 'Bionic: read for 1 to 7 September.']),
        xReply(['status' => AgentResponseSchema::STATUS_NEED_EVIDENCE, 'task' => 'status', 'tool_calls' => [['tool' => 'facts.query_orders', 'arguments' => ['business_slug' => 'laladia', 'statuses' => ['cancelled']], 'why' => 'the read']]]),
        xReply(['status' => AgentResponseSchema::STATUS_ANSWER, 'task' => 'status', 'answer' => 'LaLaDia cancelled this month: read.']),
    ]);

    $run = app(EvaluationRunner::class)->withGrader(new DeterministicGrader)
        ->run($suite, new AgentEvaluationSubject(app(AgentRunner::class), 'executive'), ['agent_key' => 'executive', 'triggered_by' => 'test']);

    expect($run->status)->toBe('passed')->and($run->cases_passed)->toBe(2)->and($run->check_rates['argument_fidelity']['passed'])->toBe(2);

    $result = $run->results()->where('case_id', EvaluationCase::where('key', 'ei.status.laladia_cancelled_this_month')->value('id'))->sole();
    $row = $result->actual['argument_trace'][0];
    expect($row['arguments_key'])->toBe('arguments')
        ->and($row['raw_arguments']['statuses'])->toBe(['cancelled'])
        ->and($row['args']['statuses'])->toBe(['cancelled'])
        ->and($row['gateway_input']['statuses'])->toBe(['cancelled'])
        ->and($row['gateway_status'])->toBe('succeeded');
});

it('is seeded by the gate runner on its own line and can be run alone by suite name', function () {
    $sh = file_get_contents(base_path('../../deploy/gate/run.sh'));
    expect($sh)->toContain("db:seed --class='Database\\Seeders\\ExecutionIntegritySuiteSeeder' --force")
        ->and($sh)->toContain('--suite="$GATE_SUITE"');
    expect(file_get_contents(base_path('../../deploy/gate/toggle.php')))->toContain("'regression.execution_integrity'");
});
