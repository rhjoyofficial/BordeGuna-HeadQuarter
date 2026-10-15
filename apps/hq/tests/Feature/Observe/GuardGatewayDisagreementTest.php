<?php

use App\Agents\AgentResponseSchema;
use App\Agents\AgentRunner;
use App\Agents\AgentRunRequest;
use App\Ai\ModelTier;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Gateway\Registry\ToolAvailability;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\ToolCall;
use App\Models\User;
use App\Observe\Alerts\AlertEngine;
use App\Observe\Alerts\GuardGatewayDisagreements;
use App\Observe\Alerts\Rules\GuardGatewayDisagreement;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolRegistry;
use App\Tools\ToolResult;
use Carbon\CarbonImmutable;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Ai\FixtureProvider;
use Tests\Support\Execution\Contexts;

/*
 | docs/116, ruling 110 §2(g): when a runtime guard refuses an effectful call that the gateway WOULD have authorized,
 | the refusal stands, the audit `agent_run.effectful_call_refused` (`gateway_would_authorize: true`) is kept, a
 | counter counts it, and an alert fires on a repeated pattern, never on one isolated refusal.
 */

/** An agent-executable write that names a conversation: the bound-thread guard refuses it on a run with no thread. */
final class DisagreementThreadWriteTool implements Tool
{
    public static int $executions = 0;

    public function key(): string
    {
        return 'test.thread_note';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'conversation_uuid' => ['type' => 'string', 'maxLength' => 64],
            'note' => ['type' => 'string', 'maxLength' => 50],
        ], 'required' => ['conversation_uuid', 'note'], 'additionalProperties' => false];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: $this->key(), version: '1.0.0', title: 'Thread note (test)',
            purpose: 'An agent-executable write naming a conversation, so the bound-thread guard and the gateway can disagree.',
            riskTier: RiskTier::R2, permission: 'hq.tools.diagnostics', auditAction: 'tool.test_thread_note',
            scope: ToolScope::Hq, readWrite: ReadWrite::Write, agentExecutable: true,
        );
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        self::$executions++;

        return ToolResult::succeeded([]);
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null;
    }
}

beforeEach(function () {
    Cache::flush();
});

// ---------------------------------------------------------------- the counter

it('counts disagreements per agent per hour, and reads them back over a window', function () {
    $counter = app(GuardGatewayDisagreements::class);
    $now = CarbonImmutable::parse('2026-10-11 10:30:00', 'UTC');

    expect($counter->recent(24, $now))->toBe([]);

    $counter->record('executive', 'test.thread_note', $now->subHours(30)); // outside a 24-hour window
    $counter->record('executive', 'test.thread_note', $now->subHours(5));
    $counter->record('executive', 'erp.poll_overview', $now);
    $counter->record('support', 'messaging.send_reply', $now);

    expect($counter->recent(24, $now))->toBe(['executive' => 2, 'support' => 1])
        ->and($counter->recent(1, $now))->toBe(['executive' => 1, 'support' => 1])
        ->and($counter->recent(48, $now))->toBe(['executive' => 3, 'support' => 1]);
});

it('never breaks the run it counts: a cache failure is swallowed', function () {
    Cache::shouldReceive('add')->andThrow(new RuntimeException('redis is down'));

    app(GuardGatewayDisagreements::class)->record('executive', 'test.thread_note');
})->throwsNoExceptions();

// ---------------------------------------------------------------- the rule

it('stays silent on an isolated disagreement and fires at the threshold, per agent, with the default 3 in 24 hours', function () {
    $counter = app(GuardGatewayDisagreements::class);
    $rule = app(GuardGatewayDisagreement::class);

    expect($rule->key())->toBe('agents.guard_gateway_disagreement')
        ->and($rule->pagedBy())->toBeNull()
        ->and(config('hq.alerts.thresholds.guard_gateway_disagreement'))->toBe(['count' => 3, 'window_hours' => 24]);

    $counter->record('executive', 'test.thread_note');
    $counter->record('executive', 'test.thread_note');
    $counter->record('support', 'messaging.send_reply');
    expect($rule->evaluate(CarbonImmutable::now()))->toBe([]);

    $counter->record('executive', 'test.thread_note');
    $conditions = $rule->evaluate(CarbonImmutable::now());

    expect($conditions)->toHaveCount(1)
        ->and($conditions[0]->subjectKey)->toBe('agent:executive')
        ->and($conditions[0]->severity)->toBe('warning')
        ->and($conditions[0]->context)->toMatchArray(['agent_key' => 'executive', 'count' => 3, 'window_hours' => 24, 'threshold' => 3]);

    // The window passes: the pattern is gone.
    $this->travel(25)->hours();
    expect($rule->evaluate(CarbonImmutable::now()))->toBe([]);
});

it('reads its threshold and window from config, and never below one', function () {
    config(['hq.alerts.thresholds.guard_gateway_disagreement' => ['count' => 1, 'window_hours' => 2]]);
    app(GuardGatewayDisagreements::class)->record('analytics', 'erp.poll_overview');
    expect(app(GuardGatewayDisagreement::class)->evaluate(CarbonImmutable::now()))->toHaveCount(1);

    config(['hq.alerts.thresholds.guard_gateway_disagreement' => ['count' => 0, 'window_hours' => 0]]);
    expect(app(GuardGatewayDisagreement::class)->evaluate(CarbonImmutable::now()))->toHaveCount(1);
});

it('is registered with the alert engine', function () {
    $classes = array_map(fn ($r) => $r::class, app(AlertEngine::class)->rules());

    expect($classes)->toContain(GuardGatewayDisagreement::class);
});

// ---------------------------------------------------------------- the runner records it

function ggdRun(User $owner, array $args, array $allowed = ['test.thread_note']): AgentRun
{
    Agent::where('key', 'executive')->update(['status' => 'active', 'enabling_user_id' => $owner->id, 'allowed_tools' => $allowed]);

    FixtureProvider::script('fixture', [
        FixtureProvider::reply((string) json_encode([
            'status' => AgentResponseSchema::STATUS_NEED_EVIDENCE, 'answer' => '', 'refusal_reason' => '', 'facts' => [], 'inferences' => [],
            'tool_calls' => [['tool' => 'test.thread_note', 'args' => $args, 'why' => 'the operator asked for it']],
            'task' => 'the operator question', 'authority_request' => 'none',
        ])),
        FixtureProvider::reply((string) json_encode([
            'status' => AgentResponseSchema::STATUS_ANSWER, 'answer' => 'Yesterday LaLaDia took 12 orders.', 'refusal_reason' => '', 'facts' => [], 'inferences' => [],
            'tool_calls' => [], 'task' => 'the operator question', 'authority_request' => 'none',
        ])),
    ]);

    return app(AgentRunner::class)->run(new AgentRunRequest(agentKey: 'executive', question: 'How are we doing?', trigger: 'console', requester: Contexts::asEnabled()));
}

it('counts a guard refusal the gateway would have authorized, beside the audit, and nothing runs', function () {
    FixtureProvider::reset();
    DisagreementThreadWriteTool::$executions = 0;
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AgentSeeder::class);
    aiProviders();
    enableAiRuntime();
    aiModel('fixture', 'fixture-model', ModelTier::Medium);
    app(ToolRegistry::class)->register(DisagreementThreadWriteTool::class);
    app(ToolAvailability::class)->forget();
    $owner = User::factory()->create();
    $owner->givePermissionTo(['hq.tools.diagnostics', 'hq.tools.facts.read']);

    // A run bound to no thread names a tool that requires a conversation: the bound-thread guard refuses it. The
    // gateway does not know about thread binding, so for a granted, agent-executable R2 write it would allow it.
    $run = ggdRun($owner, ['conversation_uuid' => '00000000-0000-0000-0000-000000000001', 'note' => 'hello']);
    $record = collect((array) $run->proposals)->firstWhere('tool', 'test.thread_note');
    $audit = AuditLog::where('action', 'agent_run.effectful_call_refused')->sole();

    expect($record['kind'])->toBe('refused')
        ->and($record['gateway']['guard'])->toBe('bound_thread')
        ->and($record['gateway']['decision'])->toBeIn(['would_authorize', 'would_await_approval'])
        ->and($audit->after)->toMatchArray(['tool' => 'test.thread_note', 'guard' => 'bound_thread', 'gateway_would_authorize' => true])
        ->and(app(GuardGatewayDisagreements::class)->recent(1))->toBe(['executive' => 1])
        // The refusal stands: nothing ran, no tool_calls row.
        ->and(DisagreementThreadWriteTool::$executions)->toBe(0)
        ->and(ToolCall::where('tool_key', 'test.thread_note')->count())->toBe(0);
});

it('does not count a guard refusal the gateway refuses too', function () {
    FixtureProvider::reset();
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AgentSeeder::class);
    aiProviders();
    enableAiRuntime();
    aiModel('fixture', 'fixture-model', ModelTier::Medium);
    app(ToolRegistry::class)->register(DisagreementThreadWriteTool::class);
    app(ToolAvailability::class)->forget();
    // The owner does NOT hold the tool's permission: the gateway refuses as well, so the two layers agree.
    $owner = User::factory()->create();
    $owner->givePermissionTo(['hq.tools.facts.read']);

    ggdRun($owner, ['conversation_uuid' => '00000000-0000-0000-0000-000000000001', 'note' => 'hello'], ['test.thread_note', 'facts.query_orders']);

    expect(AuditLog::where('action', 'agent_run.effectful_call_refused')->sole()->after['gateway_would_authorize'])->toBeFalse()
        ->and(app(GuardGatewayDisagreements::class)->recent(24))->toBe([]);
});

it('keeps every count and audit row when the alert is deduplicated, and the alert carries no customer text or credential (docs/120 items 41-42)', function () {
    FixtureProvider::reset();
    DisagreementThreadWriteTool::$executions = 0;
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AgentSeeder::class);
    aiProviders();
    enableAiRuntime();
    aiModel('fixture', 'fixture-model', ModelTier::Medium);
    app(ToolRegistry::class)->register(DisagreementThreadWriteTool::class);
    app(ToolAvailability::class)->forget();
    config(['hq.alerts.telegram_bot_token' => 'secret-bot-token-123', 'hq.alerts.telegram_chat_id' => '']);
    $owner = User::factory()->create();
    $owner->givePermissionTo(['hq.tools.diagnostics', 'hq.tools.facts.read']);
    $engine = app(AlertEngine::class);

    // Two refusals: audited and counted, no alert (threshold 3 in 24 hours).
    foreach ([1, 2] as $i) {
        FixtureProvider::reset();
        ggdRun($owner, ['conversation_uuid' => '00000000-0000-0000-0000-00000000000'.$i, 'note' => "customer-text-{$i}"]);
    }
    $engine->check(null, ['agents.guard_gateway_disagreement']);
    expect(Alert::where('rule', 'agents.guard_gateway_disagreement')->count())->toBe(0);

    // The third opens one alert; the fourth and fifth are deduplicated into it, and still counted and audited.
    foreach ([3, 4, 5] as $i) {
        FixtureProvider::reset();
        ggdRun($owner, ['conversation_uuid' => '00000000-0000-0000-0000-00000000000'.$i, 'note' => "customer-text-{$i}"]);
        $engine->check(null, ['agents.guard_gateway_disagreement']);
    }

    $alert = Alert::where('rule', 'agents.guard_gateway_disagreement')->sole();
    expect(app(GuardGatewayDisagreements::class)->recent(24))->toBe(['executive' => 5])
        ->and(AuditLog::where('action', 'agent_run.effectful_call_refused')->count())->toBe(5)
        ->and(AuditLog::where('action', 'alert.opened')->where('after->rule', 'agents.guard_gateway_disagreement')->count())->toBe(1)
        ->and($alert->context)->toMatchArray(['agent_key' => 'executive', 'count' => 5, 'threshold' => 3, 'window_hours' => 24])
        ->and(DisagreementThreadWriteTool::$executions)->toBe(0);

    // Nothing from the customer or the call's arguments, and no credential, reaches the alert.
    $text = $alert->subject.' '.$alert->body.' '.json_encode($alert->context);
    expect($text)->not->toContain('customer-text')
        ->not->toContain('00000000-0000-0000-0000')
        ->not->toContain('How are we doing?')
        ->not->toContain('secret-bot-token-123')
        ->and(array_keys($alert->context))->toEqualCanonicalizing(['agent_key', 'count', 'window_hours', 'threshold']);
});
