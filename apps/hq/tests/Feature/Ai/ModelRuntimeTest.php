<?php

use App\Ai\Budget\AiBudget;
use App\Ai\CircuitBreaker;
use App\Ai\CostCalculator;
use App\Ai\Exceptions\BudgetExceeded;
use App\Ai\Exceptions\NoModelAvailable;
use App\Ai\Exceptions\ProviderRefused;
use App\Ai\Exceptions\StructuredOutputFailed;
use App\Ai\ModelRuntime;
use App\Ai\ModelTier;
use App\Ai\RoutingContext;
use App\Ai\TaskType;
use App\Models\AiBudgetRow;
use App\Models\AiRun;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\FeatureFlag;
use App\Support\Features\Features;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Support\Ai\FixtureProvider;

beforeEach(function () {
    Cache::flush();
    FixtureProvider::reset();
    aiProviders();
    enableAiRuntime();
});

it('refuses every call while the runtime flag is off, which is the shipped default', function () {
    aiModel('fixture', 'a', ModelTier::Medium);
    FeatureFlag::query()->delete();
    app(Features::class)->forget();

    expect(fn () => app(ModelRuntime::class)->run(TaskType::OperatorQuestion, 'sys', [['role' => 'user', 'content' => 'hi']]))
        ->toThrow(NoModelAvailable::class, 'switched off');

    expect(AiRun::count())->toBe(0);
});

it('records a complete costed run for a successful call', function () {
    aiModel('fixture', 'a', ModelTier::Medium, in: '3.0000', out: '15.0000');
    FixtureProvider::script('fixture', [FixtureProvider::reply('the answer', in: 1000, out: 500)]);

    $outcome = app(ModelRuntime::class)->run(
        task: TaskType::OperatorQuestion,
        system: 'sys',
        messages: [['role' => 'user', 'content' => 'hi']],
        context: new RoutingContext(businessId: null, correlationId: (string) Str::uuid(), inputRef: 'test:1'),
        promptVersion: 'v1',
    );

    $run = $outcome->run;

    expect($outcome->text)->toBe('the answer')
        ->and($run->status)->toBe('succeeded')
        ->and($run->provider)->toBe('fixture')
        ->and($run->model)->toBe('a')
        ->and($run->model_tier)->toBe('medium')
        ->and($run->input_tokens)->toBe(1000)
        ->and($run->output_tokens)->toBe(500)
        ->and($run->task)->toBe('operator_question')
        ->and($run->prompt_version)->toBe('v1')
        ->and($run->input_ref)->toBe('test:1')
        ->and($run->attempt)->toBe(1)
        ->and($run->routing_reason)->not->toBeNull()
        // 1000 in at $3/M = 0.003; 500 out at $15/M = 0.0075; total 0.0105
        ->and((string) $run->estimated_cost_usd)->toBe('0.010500')
        // ⚠ The estimate is never copied into the reported column, or the two could never disagree
        // and the reconciliation they exist for would be impossible.
        ->and($run->actual_cost_usd)->toBeNull()
        ->and($run->cost_source)->toBeNull();
});

it('bills a cached input token at the cache price, not the input price', function () {
    $model = aiModel('fixture', 'a', ModelTier::Medium, in: '10.0000', out: '10.0000');
    $costs = app(CostCalculator::class);

    // 1000 input of which 800 were cache reads: 200 fresh at $10/M plus 800 at $0.10/M.
    $withCache = $costs->estimate($model, 1000, 0, 800);
    $withoutCache = $costs->estimate($model, 1000, 0, 0);

    expect($withoutCache)->toBe('0.010000')
        ->and($withCache)->toBe('0.002080')
        ->and((float) $withCache)->toBeLessThan((float) $withoutCache);
});

it('falls back to a different provider on an outage and records both attempts', function () {
    aiModel('fixture', 'first', ModelTier::Medium, priority: 1);
    aiModel('fixture2', 'second', ModelTier::Medium, priority: 50);

    FixtureProvider::script('fixture', [FixtureProvider::outage('provider is down')]);
    FixtureProvider::script('fixture2', [FixtureProvider::reply('served by the fallback')]);

    $outcome = app(ModelRuntime::class)->run(TaskType::OperatorQuestion, 'sys', [['role' => 'user', 'content' => 'hi']]);

    expect($outcome->text)->toBe('served by the fallback')
        ->and($outcome->run->provider)->toBe('fixture2');

    // Both attempts are on the record. A fallback that leaves no trace looks like a clean first try.
    $runs = AiRun::orderBy('id')->get();
    expect($runs)->toHaveCount(2)
        ->and($runs[0]->status)->toBe('failed')
        ->and($runs[0]->provider)->toBe('fixture')
        ->and($runs[0]->attempt)->toBe(1)
        ->and($runs[0]->error)->toContain('provider_unavailable')
        ->and($runs[1]->status)->toBe('succeeded')
        ->and($runs[1]->attempt)->toBe(2);

    expect(app(CircuitBreaker::class)->failures('fixture'))->toBe(1);
});

it('does not try a second provider when the first REFUSED the request, and does not blame it', function () {
    aiModel('fixture', 'first', ModelTier::Medium, priority: 1);
    aiModel('fixture2', 'second', ModelTier::Medium, priority: 50);

    FixtureProvider::script('fixture', [FixtureProvider::refusal('malformed request')]);
    FixtureProvider::script('fixture2', [FixtureProvider::reply('should never be reached')]);

    expect(fn () => app(ModelRuntime::class)->run(TaskType::OperatorQuestion, 'sys', [['role' => 'user', 'content' => 'hi']]))
        ->toThrow(ProviderRefused::class);

    // A broken request would be refused by every provider, so trying another only wastes a call.
    expect(FixtureProvider::callsTo('fixture2'))->toBe(0)
        // ⚠ And it must NOT trip the breaker: the provider is healthy, HQ sent something bad.
        ->and(app(CircuitBreaker::class)->failures('fixture'))->toBe(0);
});

it('repairs a malformed structured reply once, charges for both calls, then succeeds', function () {
    aiModel('fixture', 'a', ModelTier::Low, in: '1.0000', out: '1.0000');

    $schema = [
        'type' => 'object',
        'properties' => ['label' => ['type' => 'string']],
        'required' => ['label'],
        'additionalProperties' => false,
    ];

    FixtureProvider::script('fixture', [
        FixtureProvider::reply('not json at all', in: 100, out: 10),
        FixtureProvider::reply('{"label":"repaired"}', in: 200, out: 20),
    ]);

    $outcome = app(ModelRuntime::class)->run(
        task: TaskType::Classification,
        system: 'sys',
        messages: [['role' => 'user', 'content' => 'classify']],
        jsonSchema: $schema,
    );

    expect($outcome->data)->toBe(['label' => 'repaired'])
        ->and(FixtureProvider::callsTo('fixture'))->toBe(2)
        // Both calls were billed, so both are counted: 300 in + 30 out.
        ->and($outcome->run->input_tokens)->toBe(300)
        ->and($outcome->run->output_tokens)->toBe(30);

    // The repair turn hands the model the actual validation error rather than just asking again.
    $repairPrompt = FixtureProvider::requestsTo('fixture')[1];
    expect(end($repairPrompt->messages)['content'])->toContain('did not match the required schema');
});

it('fails rather than inventing an object when the repair also fails', function () {
    aiModel('fixture', 'a', ModelTier::Low);

    FixtureProvider::script('fixture', [
        FixtureProvider::reply('still not json'),
        FixtureProvider::reply('{"wrong":"shape"}'),
    ]);

    expect(fn () => app(ModelRuntime::class)->run(
        task: TaskType::Classification,
        system: 'sys',
        messages: [['role' => 'user', 'content' => 'go']],
        jsonSchema: ['type' => 'object', 'properties' => ['label' => ['type' => 'string']], 'required' => ['label'], 'additionalProperties' => false],
    ))->toThrow(StructuredOutputFailed::class);

    // ⚠ Exactly two attempts. A third would be a loop, and patching the object would be a fabrication.
    expect(FixtureProvider::callsTo('fixture'))->toBe(2);

    $run = AiRun::latest('id')->first();
    expect($run->status)->toBe('failed')
        ->and($run->error)->toContain('structured_output_failed');
});

it('unwraps a fenced JSON block rather than spending a repair call on a code fence', function () {
    aiModel('fixture', 'a', ModelTier::Low);
    FixtureProvider::script('fixture', [FixtureProvider::reply("```json\n{\"label\":\"fine\"}\n```")]);

    $outcome = app(ModelRuntime::class)->run(
        task: TaskType::Classification,
        system: 'sys',
        messages: [['role' => 'user', 'content' => 'go']],
        jsonSchema: ['type' => 'object', 'properties' => ['label' => ['type' => 'string']], 'required' => ['label'], 'additionalProperties' => false],
    );

    expect($outcome->data)->toBe(['label' => 'fine'])
        ->and(FixtureProvider::callsTo('fixture'))->toBe(1);
});

it('degrades the tier when a soft budget cap is reached, and says so on the run', function () {
    aiModel('fixture', 'top', ModelTier::High, in: '10.0000');
    aiModel('fixture', 'cheap', ModelTier::Medium, in: '1.0000');

    AiBudgetRow::create(['scope_type' => 'global', 'scope_ref' => null, 'period' => 'monthly', 'limit_usd' => '1.0000', 'enforcement' => 'soft']);
    AiRun::create(['task' => 'seed', 'status' => 'succeeded', 'estimated_cost_usd' => '2.000000']);

    FixtureProvider::script('fixture', [FixtureProvider::reply('degraded answer')]);

    $outcome = app(ModelRuntime::class)->run(TaskType::Reasoning, 'sys', [['role' => 'user', 'content' => 'hi']]);

    expect($outcome->degraded)->toBeTrue()
        ->and($outcome->run->model)->toBe('cheap')
        ->and($outcome->run->degraded_from_tier)->toBe('high')
        ->and($outcome->run->routing_reason)->toContain('budget')
        // The provenance line has to say it out loud, or a weaker answer looks like a worse system.
        ->and($outcome->provenance())->toContain('lower tier');
});

it('refuses and announces when a HARD cap is reached, and never silently', function () {
    aiModel('fixture', 'a', ModelTier::Medium);

    AiBudgetRow::create(['scope_type' => 'global', 'scope_ref' => null, 'period' => 'monthly', 'limit_usd' => '1.0000', 'enforcement' => 'hard']);
    AiRun::create(['task' => 'seed', 'status' => 'succeeded', 'estimated_cost_usd' => '5.000000']);

    expect(fn () => app(ModelRuntime::class)->run(TaskType::OperatorQuestion, 'sys', [['role' => 'user', 'content' => 'hi']]))
        ->toThrow(BudgetExceeded::class);

    expect(FixtureProvider::callsTo('fixture'))->toBe(0);
    // The refusal is on the record as a notification, not just an exception nobody sees.
    expect(AuditLog::where('action', 'notification.raised')->count())->toBeGreaterThan(0);
});

it('scopes spend per business, so one business cannot exhaust another', function () {
    // `ai_runs.business_id` is a real foreign key, so these rows need real businesses. Hard-coding
    // 1 and 2 only looked fine because nothing had inserted a costed run against a business yet.
    $one = Business::create(['name' => 'One', 'slug' => 'one-biz']);
    $two = Business::create(['name' => 'Two', 'slug' => 'two-biz']);

    AiRun::create(['task' => 't', 'status' => 'succeeded', 'business_id' => $one->id, 'estimated_cost_usd' => '3.000000']);
    AiRun::create(['task' => 't', 'status' => 'succeeded', 'business_id' => $two->id, 'estimated_cost_usd' => '7.000000']);

    $budget = app(AiBudget::class);

    expect($budget->spend('business', (string) $one->id, 'monthly'))->toBe(3.0)
        ->and($budget->spend('business', (string) $two->id, 'monthly'))->toBe(7.0)
        ->and($budget->spend('global', null, 'monthly'))->toBe(10.0);
});

it('counts a reported cost in preference to an estimate, never both', function () {
    AiRun::create(['task' => 't', 'status' => 'succeeded', 'estimated_cost_usd' => '1.000000', 'actual_cost_usd' => '2.500000', 'cost_source' => 'provider_reported']);
    AiRun::create(['task' => 't', 'status' => 'succeeded', 'estimated_cost_usd' => '0.500000']);

    expect(app(AiBudget::class)->spend('global', null, 'monthly'))->toBe(3.0);
});

it('aggregates cost along every dimension a run already carries', function () {
    aiModel('fixture', 'a', ModelTier::Medium);
    FixtureProvider::script('fixture', [FixtureProvider::reply('x', in: 100, out: 10)]);

    $business = Business::create(['name' => 'Reported', 'slug' => 'reported-biz']);

    app(ModelRuntime::class)->run(TaskType::OperatorQuestion, 'sys', [['role' => 'user', 'content' => 'hi']],
        new RoutingContext(businessId: $business->id, agentKey: 'executive'));

    $report = app(AiBudget::class)->report('monthly');

    expect($report['runs'])->toBe(1)
        ->and(collect($report['by_model'])->pluck('key'))->toContain('a')
        ->and(collect($report['by_task'])->pluck('key'))->toContain('operator_question')
        ->and(collect($report['by_agent'])->pluck('key'))->toContain('executive')
        ->and(collect($report['by_tier'])->pluck('key'))->toContain('medium')
        ->and($report['total_usd'])->toBeGreaterThan(0);
});
