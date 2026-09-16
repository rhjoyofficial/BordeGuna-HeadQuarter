<?php

use App\Agents\AgentResponseSchema;
use App\Agents\PromptLibrary;
use App\Agents\Shadow\ShadowEvidence;
use App\Agents\Shadow\ShadowPeriods;
use App\Ai\ModelTier;
use App\Http\Controllers\Webhooks\MetaWebhookController;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\AutonomyGrant;
use App\Models\EvaluationRun;
use App\Models\EvaluationSuite;
use App\Models\FeatureFlag;
use App\Models\ShadowEvidenceDay;
use App\Models\ShadowPeriod;
use App\Models\User;
use App\Support\Features\Features;
use Carbon\CarbonImmutable;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Database\Seeders\ExecutiveBehaviourSuiteSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SafetyCorpusSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Ai\FixtureProvider;

/*
 | docs/77 C.2: the Executive's A0 shadow period, as ChatGPT's points 6 to 8 of 16 September define it.
 |
 | What is proved here: the period opens only on evidence read from the database (every applicable
 | suite passed, nothing unmeasured, on the prompt in force), only for the Executive, only from an
 | inert posture, and it widens nothing; the scheduler asks the period's own questions once per slot
 | and never switches anything on; a broken A0 condition stops the period at once; a close or the
 | seventh day turns everything off again; and each complete day is snapshotted once.
 */

beforeEach(function () {
    Cache::flush();
    FixtureProvider::reset();
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(SafetyCorpusSeeder::class);
    $this->seed(ExecutiveBehaviourSuiteSeeder::class);
    aiProviders();
    aiModel('fixture', 'fixture-model', ModelTier::High);
    aiModel('fixture', 'fixture-model-medium', ModelTier::Medium);

    $this->owner = User::factory()->create(['email' => 'joy@example.test']);
    $this->owner->givePermissionTo(['hq.tools.facts.read', 'hq.tools.erp.read', 'hq.tools.commerce.read', 'hq.approvals.view', 'hq.jobs.view']);
    $this->seed(AgentSeeder::class);

    // The production posture: the runtime row exists and is off, every agent paused.
    FeatureFlag::updateOrCreate(['key' => 'ai.runtime', 'environment' => '*'], ['enabled' => false, 'rollout' => null]);
    app(Features::class)->forget();
});

/** A finished evaluation run for every suite that applies to the executive, on the prompt in force. */
function passingGate(array $overrides = []): void
{
    $version = app(PromptLibrary::class)->version('executive');
    foreach (EvaluationSuite::query()->whereIn('key', ['safety.core', 'behaviour.executive'])->get() as $suite) {
        EvaluationRun::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'suite_id' => $suite->id,
            'agent_key' => 'executive',
            'prompt_version' => $version,
            'status' => 'passed',
            'cases_total' => 10,
            'cases_passed' => 10,
            'cases_unmeasured' => 0,
            'triggered_by' => 'gate-11-attempt-2',
            'started_at' => now(),
            'finished_at' => now(),
        ], $overrides[$suite->key] ?? []));
    }
}

function shadowReply(string $answer = 'Nothing needs you today.'): mixed
{
    return FixtureProvider::reply((string) json_encode([
        'status' => AgentResponseSchema::STATUS_ANSWER,
        'answer' => $answer, 'refusal_reason' => '', 'facts' => [], 'inferences' => [], 'tool_calls' => [],
        'task' => 'the operator question', 'authority_request' => 'none',
    ]));
}

function runtimeOn(): bool
{
    app(Features::class)->forget();

    return app(Features::class)->enabled('ai.runtime');
}

function openShadow(): ShadowPeriod
{
    return app(ShadowPeriods::class)->open('executive', 'Gate 11 passed; Joy and ChatGPT decided to begin the A0 shadow.', [], test()->owner->id);
}

it('refuses to open on no evidence, says why, and changes nothing', function () {
    $this->artisan('hq:shadow:open', ['agent' => 'executive', '--apply' => true, '--reason' => 'x'])
        ->expectsOutputToContain('has never been run against [executive]')
        ->assertFailed();

    expect(runtimeOn())->toBeFalse()
        ->and(Agent::where('key', 'executive')->value('status'))->toBe('paused')
        ->and(ShadowPeriod::count())->toBe(0);
});

it('refuses on a failed, a partly unmeasured, or an older-prompt gate', function (array $override, string $says) {
    passingGate(['safety.core' => $override]);

    expect(implode("\n", app(ShadowPeriods::class)->refusals('executive')))->toContain($says);
})->with([
    'failed' => [['status' => 'failed'], 'is [failed], not passed'],
    'unmeasured' => [['cases_unmeasured' => 68], 'holds 68 unmeasured case(s)'],
    'another prompt' => [['prompt_version' => 'executive.v7+c562b837'], 'but ['],
]);

it('reads the LATEST run of each suite, so an older pass cannot stand in for a newer failure', function () {
    passingGate();
    passingGate(['behaviour.executive' => ['status' => 'failed', 'cases_unmeasured' => 6]]);

    $refusals = app(ShadowPeriods::class)->refusals('executive');

    expect(implode("\n", $refusals))->toContain('behaviour.executive')
        ->and($refusals)->toHaveCount(2);
});

it('refuses from any posture that is not inert', function (Closure $breakPosture, string $says) {
    passingGate();
    $breakPosture();

    expect(implode("\n", app(ShadowPeriods::class)->refusals('executive')))->toContain($says);
})->with([
    'runtime already on' => [fn () => enableAiRuntime(), 'ai.runtime is already on'],
    'another agent on' => [fn () => Agent::where('key', 'support')->update(['status' => 'active']), 'Other agents are not paused: support'],
    'a grant exists' => [fn () => DB::table('autonomy_grants')->insert(['uuid' => (string) Str::uuid(), 'name' => 'x', 'max_level' => 'a0', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]), 'An autonomy grant exists'],
    'meta inbound on' => [function () {
        FeatureFlag::updateOrCreate(['key' => MetaWebhookController::FLAG, 'environment' => '*'], ['enabled' => true]);
        app(Features::class)->forget();
    }, 'Meta inbound flag is on'],
    'ceiling above a0' => [fn () => Agent::where('key', 'executive')->update(['max_autonomy_level' => 'a1']), 'ceiling is a1, not a0'],
    'an effect tool' => [fn () => Agent::where('key', 'executive')->update(['allowed_tools' => ['facts.query_orders', 'messaging.send_reply']]), 'is customer-facing'],
    'no enabling user' => [fn () => Agent::where('key', 'executive')->update(['enabling_user_id' => null]), 'names no enabling user'],
]);

it('shadows only the Executive in this milestone', function () {
    expect(app(ShadowPeriods::class)->refusals('support'))->toBe(['[support] is not an agent this milestone may shadow; only executive may.'])
        ->and(app(ShadowPeriods::class)->refusals('analytics')[0])->toContain('not an agent this milestone may shadow');
});

it('dry-runs by default and opens only with --apply, audited, widening nothing', function () {
    passingGate();

    $this->artisan('hq:shadow:open', ['agent' => 'executive'])->expectsOutputToContain('dry run')->assertSuccessful();
    expect(ShadowPeriod::count())->toBe(0)->and(runtimeOn())->toBeFalse();

    $before = AuditLog::max('id') ?? 0;
    $this->artisan('hq:shadow:open', ['agent' => 'executive', '--apply' => true, '--by' => 'joy@example.test',
        '--reason' => 'Gate 11 passed; Joy and ChatGPT decided to begin the A0 shadow.'])
        ->expectsOutputToContain('OPEN for executive at A0')
        ->assertSuccessful();

    $period = ShadowPeriod::sole();
    $executive = Agent::where('key', 'executive')->sole();
    expect($period->status)->toBe('open')
        ->and($period->autonomy_level)->toBe('a0')
        ->and($period->opened_by_user_id)->toBe($this->owner->id)
        ->and($period->planned_end_at->diffInDays($period->opened_at, true))->toEqual(7)
        ->and($period->questions)->toBe(ShadowPeriods::DEFAULT_QUESTIONS)
        ->and($period->evidence['suites'])->toHaveKeys(['safety.core', 'behaviour.executive'])
        ->and($period->evidence['prompt_version'])->toStartWith('executive.v8+')
        ->and(runtimeOn())->toBeTrue()
        ->and($executive->status)->toBe('active')
        // ⛔ nothing widened
        ->and($executive->max_autonomy_level->value)->toBe('a0')
        ->and(AutonomyGrant::count())->toBe(0)
        ->and(Agent::whereIn('key', ['analytics', 'support'])->pluck('status')->unique()->all())->toBe(['paused']);

    $actions = AuditLog::where('id', '>', $before)->orderBy('id')->pluck('action')->all();
    expect($actions)->toBe(['feature_flag.toggled', 'agent.status_changed', 'shadow_period.opened'])
        ->and(AuditLog::where('id', '>', $before)->pluck('actor_id')->unique()->all())->toBe([$this->owner->id]);
});

it('holds one open period per agent and only A0, in the database itself', function () {
    passingGate();
    $period = openShadow();

    // Each refused statement in its own savepoint, so the test's transaction survives the refusal.
    expect(fn () => DB::transaction(fn () => ShadowPeriod::create(array_merge($period->only(['agent_id', 'agent_key', 'reason', 'evidence', 'allowed_tools', 'questions']), [
        'uuid' => (string) Str::uuid(), 'status' => 'open', 'opened_at' => now(), 'planned_end_at' => now()->addDays(7),
    ]))))->toThrow(QueryException::class);

    expect(fn () => DB::transaction(fn () => DB::table('shadow_periods')->where('id', $period->id)->update(['autonomy_level' => 'a1'])))
        ->toThrow(QueryException::class);

    expect(app(ShadowPeriods::class)->refusals('executive'))->toContain('A shadow period is already open for this agent.');
});

it('asks each of the period\'s questions once per slot, as shadow runs, and never twice', function () {
    passingGate();
    openShadow();
    FixtureProvider::script('fixture', [shadowReply(), shadowReply(), shadowReply()]);

    $this->artisan('hq:shadow:tick', ['--slot' => 'morning'])->assertSuccessful();
    $this->artisan('hq:shadow:tick', ['--slot' => 'morning'])->assertSuccessful();

    $runs = AgentRun::where('agent_key', 'executive')->orderBy('id')->get();
    expect($runs)->toHaveCount(3)
        ->and($runs->pluck('question')->all())->toBe(ShadowPeriods::DEFAULT_QUESTIONS)
        ->and($runs->pluck('trigger')->unique()->all())->toBe(['schedule'])
        ->and($runs->pluck('shadow')->unique()->all())->toBe([true])
        ->and($runs->pluck('autonomy_level')->map->value->unique()->all())->toBe(['a0'])
        ->and($runs->pluck('triggered_by_user_id')->unique()->all())->toBe([$this->owner->id])
        ->and(FixtureProvider::callsTo('fixture'))->toBe(3)
        ->and(ShadowPeriod::sole()->status)->toBe('open');
});

it('never switches anything on: a period whose runtime was turned off asks nothing', function () {
    passingGate();
    openShadow();
    FeatureFlag::where('key', 'ai.runtime')->update(['enabled' => false]);
    app(Features::class)->forget();

    $this->artisan('hq:shadow:tick', ['--slot' => 'evening'])->expectsOutputToContain('nothing asked')->assertSuccessful();

    expect(AgentRun::count())->toBe(0)
        ->and(runtimeOn())->toBeFalse()
        ->and(ShadowPeriod::sole()->status)->toBe('open');
});

it('stops the period at once when the agent executed anything with an effect', function () {
    passingGate();
    $period = openShadow();
    DB::table('tool_calls')->insert(['tool_key' => 'commerce.reconcile_facts', 'status' => 'succeeded', 'risk_tier' => 'r3', 'agent_key' => 'executive', 'created_at' => now()]);

    $this->artisan('hq:shadow:evidence', ['--check' => true])->expectsOutputToContain('STOPPED')->assertFailed();

    $period->refresh();
    expect($period->status)->toBe('stopped')
        ->and($period->violations[0]['condition'])->toBe('no_autonomous_write')
        ->and($period->closed_at)->not->toBeNull()
        ->and(Agent::where('key', 'executive')->value('status'))->toBe('paused')
        ->and(runtimeOn())->toBeFalse()
        ->and(AuditLog::where('action', 'shadow_period.stopped')->count())->toBe(1);
});

it('treats another agent working for real as a violation, and its evaluation runs as measurements', function () {
    passingGate();
    $period = openShadow();
    $evidence = app(ShadowEvidence::class);
    [$from, $to] = $evidence->today($period);

    $run = fn (string $trigger) => AgentRun::create(['uuid' => (string) Str::uuid(), 'agent_key' => 'support', 'correlation_id' => (string) Str::uuid(),
        'trigger' => $trigger, 'question' => 'q', 'autonomy_level' => 'a0', 'status' => 'answered']);

    $run('evaluation');
    expect($evidence->violations($period, $from, now()->toImmutable()->addSecond()))->toBe([]);

    $run('console');
    $violations = $evidence->violations($period, $from, now()->toImmutable()->addSecond());
    expect($violations)->toHaveCount(1)->and($violations[0]['condition'])->toBe('only_this_agent');
});

it('counts an R4 or R5 tool the agent merely named, and a changed tool set, as violations', function () {
    passingGate();
    $period = openShadow();
    $evidence = app(ShadowEvidence::class);

    AgentRun::create(['uuid' => (string) Str::uuid(), 'agent_key' => 'executive', 'correlation_id' => (string) Str::uuid(),
        'trigger' => 'schedule', 'question' => 'q', 'autonomy_level' => 'a0', 'status' => 'refused',
        'proposals' => [['kind' => 'refused', 'tool' => 'messaging.send_reply']]]);
    Agent::where('key', 'executive')->update(['allowed_tools' => ['facts.query_orders']]);

    $conditions = array_column($evidence->violations($period, $period->opened_at->subMinute(), now()->toImmutable()->addSecond()), 'condition');
    expect($conditions)->toContain('tool_set_unchanged');
    // messaging.send_reply is R3: named, not executed, and not above R3, so it is not a no_r4_r5 violation.
    expect($conditions)->not->toContain('no_r4_r5');
});

it('closes by hand: the agent paused, the runtime off, the partial day stored', function () {
    passingGate();
    openShadow();

    $this->artisan('hq:shadow:close', ['agent' => 'executive', '--reason' => 'Joy asked to stop.'])->assertSuccessful();

    $period = ShadowPeriod::sole();
    expect($period->status)->toBe('closed')
        ->and($period->end_reason)->toBe('Joy asked to stop.')
        ->and(Agent::where('key', 'executive')->value('status'))->toBe('paused')
        ->and(runtimeOn())->toBeFalse()
        ->and(ShadowEvidenceDay::where('shadow_period_id', $period->id)->count())->toBe(1);
});

it('closes itself when the seven days are over, and asks nothing more', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 10:00', 'Asia/Dhaka'));
    passingGate();
    openShadow();

    $this->travel(7)->days();
    $this->travel(1)->minutes();
    $this->artisan('hq:shadow:tick', ['--slot' => 'morning'])->expectsOutputToContain('seven days over')->assertSuccessful();

    $period = ShadowPeriod::sole();
    expect($period->status)->toBe('closed')
        ->and($period->end_reason)->toContain('not a promotion')
        ->and(AgentRun::count())->toBe(0)
        ->and(runtimeOn())->toBeFalse()
        ->and(ShadowEvidenceDay::where('shadow_period_id', $period->id)->count())->toBe(8);
});

it('snapshots each complete day once, with ChatGPT\'s point 7 list', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 10:00', 'Asia/Dhaka'));
    passingGate();
    $period = openShadow();
    FixtureProvider::script('fixture', [shadowReply(), shadowReply(), shadowReply()]);
    $this->artisan('hq:shadow:tick', ['--slot' => 'morning']);

    $evidence = app(ShadowEvidence::class);
    expect($evidence->store($period))->toBe([]); // the first day is not over yet

    $this->travelTo(CarbonImmutable::parse('2026-09-18 00:20', 'Asia/Dhaka'));
    $this->artisan('hq:shadow:evidence', ['--store' => true])->expectsOutputToContain('1 day row(s) written (2026-09-17)');
    $this->artisan('hq:shadow:evidence', ['--store' => true])->expectsOutputToContain('0 day row(s) written');

    $day = ShadowEvidenceDay::sole();
    expect($day->window_start->equalTo($period->opened_at))->toBeTrue()
        ->and($day->violations)->toBe([])
        ->and($day->metrics)->toHaveKeys([
            'runs', 'task_grounding', 'canonical_task_empty', 'model_named_no_task', 'proposals', 'tool_calls',
            'outcome_classes', 'policy_results', 'scope_denied', 'task_scope', 'external_failures',
            'unnecessary_tool_calls', 'loop_bound', 'closed_by_budget', 'stop_reasons', 'model_calls',
            'cost_usd', 'review', 'discrepancies',
        ])
        ->and($day->metrics['runs']['total'])->toBe(3)
        ->and($day->metrics['runs']['by_trigger'])->toBe(['schedule' => 3])
        ->and($day->metrics['model_calls']['total'])->toBe(3)
        ->and($day->metrics['review']['unreviewed'])->toBe(3);
});

it('lets the gate runner see an open period and refuse to touch the switches', function () {
    $toggle = base_path('../../deploy/gate/toggle.php');
    $run = function (string $action, string $agent = 'executive') use ($toggle): string {
        putenv("GATE_ACTION={$action}");
        putenv("GATE_AGENT={$agent}");
        putenv('GATE_NAME=test gate');
        ob_start();
        try {
            require $toggle;
        } finally {
            $out = (string) ob_get_clean();
            putenv('GATE_ACTION');
            putenv('GATE_AGENT');
            putenv('GATE_NAME');
        }

        return $out;
    };

    expect($run('shadow'))->toContain('{"open_shadow_periods":[]}');
    // The support agent names no enabling user, so a P3-G2 gate cannot switch it on by itself.
    expect($run('on', 'support'))->toStartWith('REFUSED: [support] names no enabling user');
    expect(runtimeOn())->toBeFalse();

    passingGate();
    openShadow();

    expect($run('shadow'))->toContain('{"open_shadow_periods":["executive"]}')
        ->and($run('off'))->toStartWith('REFUSED: a shadow period is open for executive')
        ->and($run('on'))->toStartWith('REFUSED: a shadow period is open for executive')
        ->and(Agent::where('key', 'executive')->value('status'))->toBe('active')
        ->and(runtimeOn())->toBeTrue();
});
