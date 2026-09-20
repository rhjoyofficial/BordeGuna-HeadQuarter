<?php

use App\Agents\AgentResponseSchema;
use App\Agents\AgentRunner;
use App\Agents\AgentRunRequest;
use App\Agents\AgentToolset;
use App\Ai\ModelTier;
use App\Execution\Requester;
use App\Execution\RequesterKind;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Business;
use App\Models\FactsOrder;
use App\Models\Store;
use App\Models\ToolCall;
use App\Tools\ToolRegistry;
use Carbon\CarbonImmutable;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Ai\FixtureProvider;
use Tests\Support\Execution\Contexts;

/*
 | The identity case (docs/80 C.2, ADR-047): requester = enabling user = the owner, group-wide, every
 | permission, the agent's own scopes null. That is production's posture today, and the six scheduled
 | jobs and the gate runner depend on the run being unchanged in it.
 |
 | ⭐ The four digests below were captured from the code BEFORE the Effective Execution Context
 | existed (commit d8779c6, the same scenario, the clock frozen), so this test compares the new code
 | with the old, byte for byte: the system prompt, the first-turn prompt (identity block, catalogue
 | with every enum, directory, question, budget line), and the second-turn message holding every tool
 | result; and the recorded calls, one by one.
 |
 | ⚠ If a later change legitimately alters the executive's catalogue, a tool's schema, the seeded
 | businesses or a prompt file, these digests move for THAT reason. Re-pin them in that change and
 | say so there; do not re-pin them in a change that touches how authority is resolved.
 */

const EEC_IDENTITY_SYSTEM = '18d60e98269a602313561d5654345c3eef3b96901957bce08527d95df34587a1';
const EEC_IDENTITY_TURN_1 = '3616867ab2383bddb82b90706385eb1fcf31da35738aa1b47a178bdb64270ddb';
const EEC_IDENTITY_TURN_2 = '04f059d5d32c556e6ed641e91a5d508da962eac7ca18c297fc6d59173fd915b1';
const EEC_IDENTITY_CALLS = '699216e656672bef5cc4c3db2c30ccab0c9c9fd7ba7f9040be280143efba2d59';

beforeEach(function () {
    Cache::flush();
    FixtureProvider::reset();
    $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00', 'Asia/Dhaka'));
    $this->seed(DomainSeeder::class);
    aiProviders();
    enableAiRuntime();
    aiModel('fixture', 'fixture-model', ModelTier::Medium);

    $this->owner = $this->hqUser('owner');
    $this->seed(AgentSeeder::class);
    Agent::query()->whereIn('key', ['executive', 'analytics'])->update(['status' => 'active', 'enabling_user_id' => $this->owner->id]);

    // Two, then one: the grouped read orders by the metric alone, so a tie would order itself by chance.
    foreach (['ID-1' => 'laladia-com', 'ID-2' => 'laladia-com', 'ID-3' => 'bionic-garden'] as $ref => $slug) {
        $store = Store::where('slug', $slug)->sole();
        FactsOrder::create([
            'business_id' => $store->business_id, 'store_id' => $store->id, 'order_ref' => $ref, 'status' => 'delivered',
            'source' => 'checkout', 'subtotal' => 1000, 'discount_total' => 0, 'shipping_cost' => 60, 'gross' => 1060,
            'placed_at' => now()->subDay(), 'synced_at' => now(),
            'lines' => [['sku' => 'A', 'name' => 'A', 'qty' => 1, 'unit_price' => 1000, 'total' => 1000]],
        ]);
    }
});

/** @return array{system: string, turn_1: string, turn_2: string, calls: string, run: AgentRun} */
function eecIdentityScenario(?Requester $requester): array
{
    $reply = fn (array $o) => FixtureProvider::reply((string) json_encode($o + [
        'status' => AgentResponseSchema::STATUS_ANSWER, 'answer' => 'Done.', 'refusal_reason' => '', 'facts' => [],
        'inferences' => [], 'tool_calls' => [], 'task' => 'how every business did yesterday', 'authority_request' => 'none',
    ]));
    FixtureProvider::script('fixture', [
        $reply(['status' => AgentResponseSchema::STATUS_NEED_EVIDENCE, 'answer' => '', 'tool_calls' => [
            ['tool' => 'facts.query_orders', 'args' => ['group_by' => 'business'], 'why' => 'orders'],
            ['tool' => 'erp.read_snapshot', 'args' => ['business_slug' => 'all'], 'why' => 'erp'],
            ['tool' => 'hq.list_jobs', 'args' => [], 'why' => 'jobs'],
        ]]),
        $reply([]),
    ]);

    // Written so the same file runs on the code before ADR-047, where the request had no requester.
    $arguments = ['agentKey' => 'executive', 'question' => 'How did every business do yesterday?', 'trigger' => 'console'];
    if ($requester !== null) {
        $arguments['requester'] = $requester;
    }
    $run = app(AgentRunner::class)->run(new AgentRunRequest(...$arguments));

    $seen = FixtureProvider::requestsTo('fixture');
    $secondTurn = $seen[1]->messages;
    $normalise = fn (string $text) => preg_replace('/#\d+/', '#N', $text);
    $calls = ToolCall::where('correlation_id', $run->correlation_id)->orderBy('id')->get()->map(fn (ToolCall $c) => [
        $c->tool_key, $c->caller_kind, $c->actor_type, $c->actor_label, $c->agent_key, $c->enabling_user_id === test()->owner->id,
        Business::whereKey($c->business_id)->value('slug'), $c->input, $c->status, $c->policy_result, $c->outcome_class,
        $normalise((string) json_encode($c->output)),
    ])->all();

    return [
        'system' => hash('sha256', $seen[0]->system),
        'turn_1' => hash('sha256', (string) $seen[0]->messages[0]['content']),
        'turn_2' => hash('sha256', (string) $normalise((string) $secondTurn[count($secondTurn) - 1]['content'])),
        'calls' => hash('sha256', (string) json_encode($calls)),
        'run' => $run,
    ];
}

it('leaves the identity case byte for byte what it was before the Effective Execution Context existed', function (?Requester $requester) {
    $seen = eecIdentityScenario($requester);

    if (($path = getenv('EEC_GOLDEN_WRITE')) !== false && $path !== '') {
        file_put_contents($path, json_encode(array_diff_key($seen, ['run' => 1]), JSON_PRETTY_PRINT).PHP_EOL, FILE_APPEND);
        $this->markTestSkipped('golden digests written');
    }

    expect($seen['run']->status)->toBe('answered')
        ->and($seen['run']->tool_calls_count)->toBe(7)
        ->and($seen['system'])->toBe(EEC_IDENTITY_SYSTEM)
        ->and($seen['turn_1'])->toBe(EEC_IDENTITY_TURN_1)
        ->and($seen['turn_2'])->toBe(EEC_IDENTITY_TURN_2)
        ->and($seen['calls'])->toBe(EEC_IDENTITY_CALLS);
})->with([
    'nobody else asked (the gate runner, the console, the probes of a period with no opener)' => [fn () => Requester::asEnabled(RequesterKind::GateRunner, 'test')],
    'the owner asked in person' => [fn () => Requester::human(test()->owner, 'test')],
]);

it('resolves the identity case to a context that narrows nothing, by the rules the catalogue always used', function () {
    $executive = Agent::where('key', 'executive')->sole();
    $context = Contexts::for($executive);
    $registry = app(ToolRegistry::class);

    // The rule before ADR-047, restated here independently: granted, registered, agent-executable.
    $legacy = array_values(array_filter($registry->keys(), fn (string $key) => in_array($key, (array) $executive->allowed_tools, true)
        && $registry->get($key)->descriptor()->agentExecutable));

    expect($context->businessIds)->toBeNull()
        ->and($context->brandIds)->toBeNull()
        ->and($context->narrowed)->toBe([])
        ->and($context->requesterIsEnabler())->toBeTrue()
        ->and($context->toolKeys)->toEqualCanonicalizing((array) $executive->allowed_tools)
        ->and(app(AgentToolset::class)->keysFor($context))->toBe($legacy);
});
