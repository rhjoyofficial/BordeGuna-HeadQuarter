<?php

use App\Agents\AgentResponseSchema;
use App\Agents\AgentRunner;
use App\Agents\AgentRunRequest;
use App\Agents\AgentToolset;
use App\Ai\ModelTier;
use App\Ai\TaskType;
use App\Conversation\Channels\SimulatedChannelAdapter;
use App\Conversation\ConversationService;
use App\Conversation\SupportContext;
use App\Conversation\WakeUp\SupportWakeUp;
use App\Execution\Requester;
use App\Execution\RequesterKind;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Gateway\Registry\ToolAvailability;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\PlatformAccount;
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
 | docs/101 §11 "Effectful tool governance: KEEP": proposal -> Gateway authorization -> pre-execution checks
 | -> execution -> record/audit. At A0 nothing with an effect runs, and an effectful call is recorded as a
 | proposal ONLY when the Tool Gateway's authorize() permits it (docs/97 §6 D4). These tests hold that for
 | EVERY registered tool whose descriptor has an effect (a write, or risk tier R3 and above), so a tool
 | added later is covered without anyone remembering to add it here.
 */

final class GovernanceTestWriteTool implements Tool
{
    public static int $executions = 0;

    public function key(): string
    {
        return 'test.governed_write';
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
            key: $this->key(), version: '1.0.0', title: 'Governed write (test)',
            purpose: 'An agent-executable write used to prove a proposal needs the gateway.',
            riskTier: RiskTier::R2, permission: 'hq.tools.diagnostics', auditAction: 'tool.test_governed_write',
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
    FixtureProvider::reset();
    GovernanceTestWriteTool::$executions = 0;
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AgentSeeder::class);
    aiProviders();
    enableAiRuntime();
    aiModel('fixture', 'fixture-model', ModelTier::Medium);
});

/** The runner's own rule (AgentRunner::hasEffect), restated: a write, or R3 and above. */
function governanceHasEffect(ToolDescriptor $descriptor): bool
{
    return $descriptor->readWrite === ReadWrite::Write || $descriptor->riskTier->atLeast(RiskTier::R3);
}

/** Run the executive at A0, enabled by $user, proposing one call to $key, and return the run. */
function governanceRun(User $user, string $key, array $args): AgentRun
{
    Agent::where('key', 'executive')->update(['status' => 'active', 'enabling_user_id' => $user->id, 'allowed_tools' => [$key]]);

    FixtureProvider::script('fixture', [
        FixtureProvider::reply((string) json_encode([
            'status' => AgentResponseSchema::STATUS_NEED_EVIDENCE, 'answer' => '', 'refusal_reason' => '', 'facts' => [], 'inferences' => [],
            'tool_calls' => [['tool' => $key, 'args' => $args, 'why' => 'the operator asked for it']],
            'task' => 'the operator question', 'authority_request' => 'none',
        ])),
        FixtureProvider::reply((string) json_encode([
            'status' => AgentResponseSchema::STATUS_ANSWER, 'answer' => 'Done.', 'refusal_reason' => '', 'facts' => [], 'inferences' => [],
            'tool_calls' => [], 'task' => 'the operator question', 'authority_request' => 'none',
        ])),
    ]);

    return app(AgentRunner::class)->run(new AgentRunRequest(
        agentKey: 'executive',
        question: 'How are we doing?',
        trigger: 'console',
        requester: Contexts::asEnabled(),
    ));
}

/** Run the support agent at A0 BOUND to a real thread (as the wake-up does), proposing one call to $key. */
function governanceThreadRun(User $user, string $key): AgentRun
{
    PlatformAccount::updateOrCreate(
        ['platform' => 'facebook_page', 'account_ref' => SimulatedChannelAdapter::DEFAULT_PAGE],
        ['brand_id' => Brand::where('code', 'LLD')->sole()->id, 'name' => 'Simulated', 'approval_status' => 'simulated', 'is_active' => true],
    );
    Agent::where('key', 'support')->update(['status' => 'active', 'enabling_user_id' => $user->id, 'allowed_tools' => [$key]]);

    $message = null;
    foreach ((new SimulatedChannelAdapter)->normalise(['messages' => [['from' => 'psid-'.Str::random(8), 'text' => 'Vaiya hilsa pickle ta ki stock e ase?', 'mid' => 'm-'.Str::uuid()]]]) as $inbound) {
        $message = app(ConversationService::class)->ingest($inbound);
    }
    $conversation = $message->conversation()->sole();

    FixtureProvider::script('fixture', [
        FixtureProvider::reply((string) json_encode([
            'status' => AgentResponseSchema::STATUS_NEED_EVIDENCE, 'answer' => '', 'refusal_reason' => '', 'facts' => [], 'inferences' => [],
            'tool_calls' => [['tool' => $key, 'args' => ['business_slug' => 'laladia', 'conversation_uuid' => 'from-the-header', 'text' => 'Ji vaiya, stock e ase.'], 'why' => 'the reply']],
            'task' => 'answer the customer on this thread', 'authority_request' => 'none',
        ])),
        FixtureProvider::reply((string) json_encode([
            'status' => AgentResponseSchema::STATUS_ANSWER, 'answer' => 'Proposed a reply.', 'refusal_reason' => '', 'facts' => [], 'inferences' => [],
            'tool_calls' => [], 'task' => 'answer the customer on this thread', 'authority_request' => 'none',
        ])),
    ]);

    return app(AgentRunner::class)->run(new AgentRunRequest(
        agentKey: 'support',
        question: SupportWakeUp::QUESTION,
        trigger: SupportWakeUp::TRIGGER,
        businessId: (int) $conversation->business_id,
        brandId: $conversation->brand_id === null ? null : (int) $conversation->brand_id,
        task: TaskType::OperatorQuestion,
        extraContext: app(SupportContext::class)->blocksFor($conversation),
        requester: Requester::asEnabled(RequesterKind::OnBehalfOf, 'governance test: a customer wrote'),
        conversationUuid: (string) $conversation->uuid,
        conversationMessageUuid: (string) $message->uuid,
    ));
}

it('records an effectful A0 call as a proposal only after authorize() permits it, and runs nothing either way', function () {
    app(ToolRegistry::class)->register(GovernanceTestWriteTool::class);
    app(ToolAvailability::class)->forget();

    // Permitted: the gateway authorizes, so it is a proposal, and it has not run.
    $permitted = User::factory()->create();
    $permitted->givePermissionTo(['hq.tools.diagnostics']);
    $run = governanceRun($permitted, 'test.governed_write', ['note' => 'hello']);

    expect($run->shadow)->toBeTrue()
        ->and(collect($run->proposals)->where('kind', 'proposal')->pluck('authorization')->all())->toBe(['authorized'])
        ->and(AuditLog::where('action', 'agent_run.proposal_not_authorized')->count())->toBe(0)
        ->and(GovernanceTestWriteTool::$executions)->toBe(0);

    // The gateway refuses (here at its argument step: `note` is required), so there is no proposal,
    // and the refusal is audited. The same permitted user, the same tool: only the gateway's answer differs.
    FixtureProvider::reset();
    $refused = governanceRun($permitted, 'test.governed_write', ['body' => 'hello']);

    expect(collect($refused->proposals)->where('kind', 'proposal')->count())->toBe(0)
        ->and(collect($refused->proposals)->pluck('kind')->all())->toBe(['refused'])
        ->and(AuditLog::where('action', 'agent_run.proposal_not_authorized')->sole()->after['tool'])->toBe('test.governed_write')
        ->and(GovernanceTestWriteTool::$executions)->toBe(0);
});

it('stores no effectful proposal without the gateway\'s decision, for every registered tool with an effect', function () {
    $registry = app(ToolRegistry::class);
    $effectful = array_values(array_filter($registry->keys(), fn (string $key) => governanceHasEffect($registry->get($key)->descriptor())));

    // The release has effectful tools; an empty list would make this test say nothing.
    expect($effectful)->not->toBeEmpty()->toContain('messaging.send_reply');

    // An owner enables the run, so the execution context admits every tool; the arguments are a probe
    // no tool declares, so whatever the gateway answers, it is the gateway that answers.
    $owner = $this->hqUser('owner');
    $reachedGateway = [];

    foreach ($effectful as $key) {
        FixtureProvider::reset();
        $since = (int) AuditLog::max('id');
        $run = governanceRun($owner, $key, ['governance_probe' => true]);
        $records = collect((array) $run->proposals)->where('tool', $key);

        expect($run->shadow)->toBeTrue($key)
            ->and($records->count())->toBeGreaterThan(0, "[{$key}] left no record at all");

        // Every proposal carries the gateway's own permission (only the authorized branch sets it).
        foreach ($records->where('kind', 'proposal') as $proposal) {
            expect($proposal['authorization'] ?? null)->toBeIn(['authorized', 'awaits_approval'], "[{$key}] was recorded as a proposal without the gateway's decision");
        }

        $audited = AuditLog::where('action', 'agent_run.proposal_not_authorized')->where('id', '>', $since)->get()
            ->contains(fn (AuditLog $a) => ($a->after['tool'] ?? null) === $key);

        // A call the runtime's own guards admit (the agent's catalogue, the descriptor's agent rule,
        // availability) MUST have been put to the gateway: it permitted a proposal, its refusal is audited,
        // or the reply's script check (which runs only AFTER authorize() permitted, D4) refused it. One the
        // guards refuse never reaches the gateway and is refused, never proposed. A tool that names a
        // conversation is put again on a run BOUND to a real thread, as the wake-up runs it, because an
        // unbound run refuses it before the gateway by design.
        $namesThread = array_key_exists('conversation_uuid', (array) ($registry->get($key)->inputSchema()['properties'] ?? []));
        if ($namesThread) {
            FixtureProvider::reset();
            $since = (int) AuditLog::max('id');
            $run = governanceThreadRun($owner, $key);
            $records = collect((array) $run->proposals)->where('tool', $key);
            $audited = AuditLog::where('action', 'agent_run.proposal_not_authorized')->where('id', '>', $since)->get()
                ->contains(fn (AuditLog $a) => ($a->after['tool'] ?? null) === $key);
            foreach ($records->where('kind', 'proposal') as $proposal) {
                expect($proposal['authorization'] ?? null)->toBeIn(['authorized', 'awaits_approval'], "[{$key}] was recorded as a proposal without the gateway's decision");
            }
        }

        $agent = Agent::where('key', $namesThread ? 'support' : 'executive')->sole();
        $decided = $audited
            || $records->where('kind', 'proposal')->isNotEmpty()
            || $records->where('outcome_class', 'content_policy_rejected')->isNotEmpty();

        if (app(AgentToolset::class)->refusalFor($agent, $key) === null) {
            expect($decided)->toBeTrue("[{$key}] passed the runtime's guards but shows no gateway decision");
            $reachedGateway[] = $key;
        } else {
            expect($records->where('kind', 'proposal')->count())->toBe(0, $key)
                ->and($records->pluck('kind')->unique()->all())->toBe(['refused'], $key);
        }
    }

    // Not vacuous: the effectful tool an agent may execute today, the Messenger reply, was put to the gateway.
    // (The others are refused by the runtime's guards before it, today: not cleared for agents, or switched off.)
    expect($reachedGateway)->toContain('messaging.send_reply');
});

it('has exactly two places that record a proposal, both after the gateway decided', function () {
    $runner = file_get_contents(app_path('Agents/AgentRunner.php'));

    // The A0 branch: authorize(), permitsProposal(), the pre-execution checks, then the proposal.
    $authorize = strpos($runner, '$this->gateway->authorize($toolRequest)');
    $permits = strpos($runner, '$authorization->permitsProposal()');
    $checks = strpos($runner, '$this->gateway->preExecutionChecks(');
    $first = strpos($runner, "['kind' => 'proposal'");
    // The executing branch: only when the gateway itself returned AwaitingApproval.
    $awaiting = strpos($runner, 'OutcomeStatus::AwaitingApproval');
    $second = strpos($runner, "['kind' => 'proposal'", $first + 1);

    expect(substr_count($runner, "'kind' => 'proposal'"))->toBe(2)
        ->and($authorize)->toBeInt()->toBeLessThan($permits)
        ->and($permits)->toBeLessThan($checks)
        ->and($checks)->toBeLessThan($first)
        ->and($awaiting)->toBeGreaterThan($first)->toBeLessThan($second);

    // Nowhere else in app/ writes one.
    $elsewhere = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php') && ! str_ends_with(str_replace('\\', '/', $file->getPathname()), 'Agents/AgentRunner.php')
            && str_contains((string) file_get_contents($file->getPathname()), "'kind' => 'proposal'")) {
            $elsewhere[] = $file->getPathname();
        }
    }
    expect($elsewhere)->toBe([]);
});
