<?php

use App\Agents\AgentResponseSchema;
use App\Agents\AgentRunner;
use App\Agents\AgentRunRequest;
use App\Agents\RoleBoundary;
use App\Ai\ModelTier;
use App\Gateway\Descriptor\AutonomyLevel;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Gateway\Registry\ToolAvailability;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\AutonomyGrant;
use App\Models\ToolCall;
use App\Models\User;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolRegistry;
use App\Tools\ToolResult;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Support\Ai\FixtureProvider;
use Tests\Support\Execution\Contexts;

/*
 | docs/101 §12 Q5, docs/103 §3 (S1), docs/105: an effectful call an agent names never stops at the runtime alone.
 |
 | Before S1, the runtime's guards (the catalogue: not cleared for agents, not granted, switched off; the refused part
 | of a request; the bound thread) refused an effectful call BEFORE the gateway, so the gateway never decided it. Now
 | the gateway is asked too, read-only (`authorize()`), and its verdict is on the run's record and in the audit. The
 | guard's refusal stands whatever the gateway says, at every autonomy level: a guard can only remove, and asking
 | the gateway can only add a record. Nothing here executes; no tool_calls row is written by the question.
 */

final class GuardRefusalWriteTool implements Tool
{
    public static int $executions = 0;

    public function key(): string
    {
        return 'test.guarded_write';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['note' => ['type' => 'string', 'maxLength' => 50]], 'required' => ['note'], 'additionalProperties' => false];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: $this->key(), version: '1.0.0', title: 'Guarded write (test)',
            purpose: 'An agent-executable write that publishes, used to prove a guard refusal still asks the gateway.',
            riskTier: RiskTier::R2, permission: 'hq.tools.diagnostics', auditAction: 'tool.test_guarded_write',
            scope: ToolScope::Hq, readWrite: ReadWrite::Write, agentExecutable: true, roleAction: 'publication',
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
    FixtureProvider::reset();
    GuardRefusalWriteTool::$executions = 0;
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AgentSeeder::class);
    aiProviders();
    enableAiRuntime();
    aiModel('fixture', 'fixture-model', ModelTier::Medium);
    app(ToolRegistry::class)->register(GuardRefusalWriteTool::class);
    app(ToolAvailability::class)->forget();

    $this->owner = User::factory()->create();
    $this->owner->givePermissionTo(['hq.tools.diagnostics', 'hq.tools.facts.read']);
});

/** The executive, enabled by the owner and holding $allowed, proposes one call to $key, then answers. */
function grRun(User $owner, string $key, array $args, array $allowed, string $question = 'How are we doing?'): AgentRun
{
    Agent::where('key', 'executive')->update(['status' => 'active', 'enabling_user_id' => $owner->id, 'allowed_tools' => $allowed]);

    FixtureProvider::script('fixture', [
        FixtureProvider::reply((string) json_encode([
            'status' => AgentResponseSchema::STATUS_NEED_EVIDENCE, 'answer' => '', 'refusal_reason' => '', 'facts' => [], 'inferences' => [],
            'tool_calls' => [['tool' => $key, 'args' => $args, 'why' => 'the operator asked for it']],
            'task' => 'the operator question', 'authority_request' => 'none',
        ])),
        FixtureProvider::reply((string) json_encode([
            'status' => AgentResponseSchema::STATUS_ANSWER, 'answer' => 'Yesterday LaLaDia took 12 orders.', 'refusal_reason' => '', 'facts' => [], 'inferences' => [],
            'tool_calls' => [], 'task' => 'the operator question', 'authority_request' => 'none',
        ])),
    ]);

    return app(AgentRunner::class)->run(new AgentRunRequest(agentKey: 'executive', question: $question, trigger: 'console', requester: Contexts::asEnabled()));
}

function grRecord(AgentRun $run, string $key): array
{
    return collect((array) $run->proposals)->firstWhere('tool', $key) ?? [];
}

function grAudit(string $key): ?AuditLog
{
    return AuditLog::where('action', 'agent_run.effectful_call_refused')->get()->first(fn (AuditLog $a) => ($a->after['tool'] ?? null) === $key);
}

it('asks the gateway about an effectful tool the agent was NOT GRANTED, keeps the refusal, and executes nothing', function () {
    $run = grRun($this->owner, 'test.guarded_write', ['note' => 'hello'], allowed: ['facts.query_orders']);
    $record = grRecord($run, 'test.guarded_write');

    expect($record['kind'])->toBe('refused')
        // The model reads the guard's refusal, word for word as before.
        ->and($record['reason'])->toContain('is not granted to this agent')
        ->and($record['gateway'])->toMatchArray(['asked' => 'authorize', 'guard' => 'catalogue', 'decision' => 'refused'])
        // The gateway refuses it on its own grounds (here first the role: the Executive never publishes).
        ->and($record['gateway']['outcome_class'])->toBeIn(['permission_denied', 'policy_denied'])
        ->and(grAudit('test.guarded_write')->after)->toMatchArray(['guard' => 'catalogue', 'gateway_decision' => 'refused', 'gateway_would_authorize' => false])
        // authorize() writes nothing and runs nothing.
        ->and(ToolCall::where('tool_key', 'test.guarded_write')->count())->toBe(0)
        ->and(GuardRefusalWriteTool::$executions)->toBe(0);
});

it('asks the gateway about a real effectful tool NOT CLEARED FOR AGENTS, which refuses it as human-only', function () {
    $run = grRun($this->owner, 'demo.request_approval', ['note' => 'probe', 'amount' => 1], allowed: ['demo.request_approval']);
    $record = grRecord($run, 'demo.request_approval');

    expect($record['kind'])->toBe('refused')
        ->and($record['reason'])->toContain('not yet cleared for agents')
        ->and($record['gateway']['decision'])->toBe('refused')
        ->and($record['gateway']['guard'])->toBe('catalogue')
        ->and((string) $record['gateway']['reason'])->toContain('action_class.human_only')
        ->and(ToolCall::where('tool_key', 'demo.request_approval')->count())->toBe(0)
        ->and(AuditLog::where('action', 'demo.approved_action_executed')->count())->toBe(0);
});

it('asks the gateway about an effectful call for the REFUSED PART of a request, which refuses it too', function () {
    $run = grRun($this->owner, 'test.guarded_write', ['note' => 'Yesterday was good'], allowed: ['test.guarded_write'],
        question: 'Give me yesterday\'s sales summary and publish it to LaLaDia.');
    $record = grRecord($run, 'test.guarded_write');

    expect($run->role_boundary['forbidden'])->toBe([RoleBoundary::PUBLICATION])
        ->and($record['kind'])->toBe('refused')
        ->and($record['reason'])->toContain('refused before the agent was asked')
        ->and($record['gateway'])->toMatchArray(['asked' => 'authorize', 'guard' => 'refused_role_action', 'decision' => 'refused'])
        ->and(GuardRefusalWriteTool::$executions)->toBe(0)
        ->and(ToolCall::where('tool_key', 'test.guarded_write')->count())->toBe(0);
});

it('never executes a guard-refused effectful call even at an EXECUTING level (A2): the gateway is asked, never run', function () {
    AutonomyGrant::create(['uuid' => (string) Str::uuid(), 'name' => 's1 guard test', 'agent_key' => 'executive', 'max_level' => 'a2', 'status' => 'active']);
    Agent::where('key', 'executive')->update(['max_autonomy_level' => 'a2']);

    $run = grRun($this->owner, 'test.guarded_write', ['note' => 'hello'], allowed: ['facts.query_orders']);

    expect($run->autonomy_level)->toBe(AutonomyLevel::A2)
        ->and(grRecord($run, 'test.guarded_write')['gateway']['decision'])->toBe('refused')
        ->and(ToolCall::where('tool_key', 'test.guarded_write')->count())->toBe(0)
        ->and(GuardRefusalWriteTool::$executions)->toBe(0);
});

it('asks nothing of the gateway for a key that names no tool, or for a read the guards refuse', function () {
    $unknown = grRun($this->owner, 'no.such_tool', [], allowed: ['facts.query_orders']);
    expect(grRecord($unknown, 'no.such_tool'))->not->toHaveKey('gateway');

    FixtureProvider::reset();
    $read = grRun($this->owner, 'hq.list_jobs', [], allowed: ['facts.query_orders']);
    expect(grRecord($read, 'hq.list_jobs'))->not->toHaveKey('gateway')
        ->and(AuditLog::where('action', 'agent_run.effectful_call_refused')->count())->toBe(0);
});
