<?php

use App\Agents\AgentResponseSchema;
use App\Agents\AgentRunner;
use App\Agents\AgentRunRequest;
use App\Ai\ModelTier;
use App\Ai\Providers\ModelResponse;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Business;
use App\Models\FactsErpSnapshot;
use App\Models\ToolCall;
use App\Models\User;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Ai\FixtureProvider;

/*
 | A repeated denial ends in a verdict, not in silence (docs/67, ADR-039).
 |
 | Gate 4 ended four runs as `repeated_denial`: `erp.read_snapshot` asked for with no business,
 | refused, asked for again identically, stopped. Correct as containment, useless as an answer. The
 | correction is the same bounded recovery the loop budget already had: after the second identical
 | denial no further tool is honoured and the model gets one closing turn, with the refusal in front
 | of it, to answer from what it holds or to say it cannot. The gateway is not weakened, the refused
 | call never runs, and a closing turn that asks for tools anyway still ends the run as before.
 */

beforeEach(function () {
    Cache::flush();
    FixtureProvider::reset();
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    aiProviders();
    enableAiRuntime();
    aiModel('fixture', 'fixture-model', ModelTier::Medium);

    $this->owner = User::factory()->create();
    $this->owner->givePermissionTo(['hq.tools.facts.read', 'hq.tools.erp.read', 'hq.tools.commerce.read', 'hq.approvals.view', 'hq.jobs.view']);

    $this->seed(AgentSeeder::class);
    Agent::query()->update(['status' => 'active', 'enabling_user_id' => $this->owner->id]);
});

/** A scripted model reply in the agent response schema, under a name no other test file uses. */
function denialReply(array $overrides): ModelResponse
{
    return FixtureProvider::reply((string) json_encode($overrides + [
        'status' => AgentResponseSchema::STATUS_ANSWER,
        'answer' => '', 'refusal_reason' => '', 'facts' => [], 'inferences' => [], 'tool_calls' => [],
        'task' => 'the operator question', 'authority_request' => 'none',
    ]));
}

function askExecutive(string $question = 'How is Bionic Garden doing this week?'): AgentRun
{
    return app(AgentRunner::class)->run(new AgentRunRequest(
        agentKey: 'executive', question: $question, trigger: 'console', businessId: null,
    ));
}

function unscopedSnapshot(): array
{
    return ['tool' => 'erp.read_snapshot', 'args' => [], 'why' => 'the ERP view'];
}

// ── First denial: the model may revise the call ─────────────────────────────────────────────────

it('lets the model correct an unscoped call after the first refusal, which names the argument to supply', function () {
    $bionic = Business::where('slug', 'bionic')->sole();
    FactsErpSnapshot::create([
        'business_id' => $bionic->id, 'kind' => 'overview',
        'payload' => ['sales' => 7], 'as_of' => now()->subMinutes(5), 'polled_at' => now()->subMinutes(5),
    ]);

    FixtureProvider::script('fixture', [
        denialReply(['status' => AgentResponseSchema::STATUS_NEED_EVIDENCE, 'tool_calls' => [unscopedSnapshot()]]),
        // The corrected call: the business named by the brand people use, which HQ resolves.
        denialReply(['status' => AgentResponseSchema::STATUS_NEED_EVIDENCE, 'tool_calls' => [
            ['tool' => 'erp.read_snapshot', 'args' => ['business_slug' => 'Bionic Garden'], 'why' => 'the ERP view, scoped'],
        ]]),
        denialReply(['status' => AgentResponseSchema::STATUS_ANSWER, 'answer' => 'Bionic sold 7 by the ERP overview, five minutes old.']),
    ]);

    $run = askExecutive();

    expect($run->status)->toBe('answered');
    expect($run->stop_reason)->toBeNull();
    expect($run->closed_by_budget)->toBeFalse();
    expect($run->tool_calls_count)->toBe(2);

    // The refusal the model read named the argument and its values.
    $requests = FixtureProvider::requestsTo('fixture');
    $second = $requests[1]->messages;
    expect(end($second)['content'])->toContain('Pass [business_slug] with one of:')->toContain('bionic');

    // One refused row, one succeeded row, and the succeeded one is scoped to the business it resolved.
    expect(ToolCall::where('tool_key', 'erp.read_snapshot')->where('status', 'denied')->count())->toBe(1);
    $ok = ToolCall::where('tool_key', 'erp.read_snapshot')->where('status', 'succeeded')->sole();
    expect($ok->business_id)->toBe($bionic->id);
});

// ── Second identical denial: no more tools, one closing turn ────────────────────────────────────

it('gives a run one tool-free closing turn after the second identical denial, and takes its answer', function () {
    $ask = denialReply(['status' => AgentResponseSchema::STATUS_NEED_EVIDENCE, 'tool_calls' => [
        ['tool' => 'facts.snapshot_freshness', 'args' => [], 'why' => 'freshness'],
        unscopedSnapshot(),
    ]]);
    $again = denialReply(['status' => AgentResponseSchema::STATUS_NEED_EVIDENCE, 'tool_calls' => [unscopedSnapshot()]]);
    $closing = denialReply([
        'status' => AgentResponseSchema::STATUS_ANSWER,
        'answer' => 'From what I hold: the order facts are fresh. I could not read the ERP snapshot because my call named no business.',
        // Named in the closing turn, and must not run: the note said no tool would.
        'tool_calls' => [unscopedSnapshot()],
    ]);

    FixtureProvider::script('fixture', [$ask, $again, $closing]);

    $run = askExecutive();

    expect($run->status)->toBe('answered');
    expect($run->stop_reason)->toBeNull();
    expect($run->closed_by_budget)->toBeTrue();
    expect($run->answer)->toContain('could not read the ERP snapshot');
    expect($run->turns)->toBe(3);

    // Exactly two refusals reached the gateway; the third request never did.
    expect(ToolCall::where('tool_key', 'erp.read_snapshot')->count())->toBe(2);
    expect(ToolCall::where('tool_key', 'erp.read_snapshot')->where('status', 'denied')->count())->toBe(2);
    expect(FixtureProvider::callsTo('fixture'))->toBe(3);

    // The closing note the model read was the denial's, not the budget's, and the refusal was in it.
    $requests = FixtureProvider::requestsTo('fixture');
    $last = end($requests)->messages;
    expect(end($last)['content'])->toContain(AgentRunner::DENIAL_NOTE)->toContain('erp.read_snapshot');
});

it('lets the closing turn state that it cannot answer, as a refusal', function () {
    $again = denialReply(['status' => AgentResponseSchema::STATUS_NEED_EVIDENCE, 'tool_calls' => [unscopedSnapshot()]]);

    FixtureProvider::script('fixture', [$again, $again, denialReply([
        'status' => AgentResponseSchema::STATUS_REFUSE,
        'refusal_reason' => 'I cannot answer this: the only source it rests on is the ERP snapshot, and my calls for it were refused.',
    ])]);

    $run = askExecutive();

    expect($run->status)->toBe('refused');
    expect($run->closed_by_budget)->toBeTrue();
    expect($run->error)->toContain('cannot answer this');
    expect(ToolCall::where('tool_key', 'erp.read_snapshot')->count())->toBe(2);
});

it('ends the run as repeated_denial when the closing turn asks for tools again, and runs none of them', function () {
    $again = denialReply(['status' => AgentResponseSchema::STATUS_NEED_EVIDENCE, 'tool_calls' => [unscopedSnapshot()]]);

    FixtureProvider::script('fixture', [$again, $again, $again]);

    $run = askExecutive();

    // Told twice, asked a third time: the pattern that needs a person, exactly as before.
    expect($run->status)->toBe('stopped');
    expect($run->stop_reason)->toBe(AgentRunner::STOP_REPEATED_DENIAL);
    expect($run->closed_by_budget)->toBeTrue();
    expect($run->error)->toContain('refused twice')->toContain('closing turn asked for more evidence');
    expect(ToolCall::where('tool_key', 'erp.read_snapshot')->count())->toBe(2);
});

it('never retries a denied call on its own: the same refused tool reaches the gateway at most twice in a run', function () {
    $many = array_fill(0, 6, unscopedSnapshot());
    FixtureProvider::script('fixture', [
        denialReply(['status' => AgentResponseSchema::STATUS_NEED_EVIDENCE, 'tool_calls' => $many]),
        denialReply(['status' => AgentResponseSchema::STATUS_ANSWER, 'answer' => 'Nothing I can say about the ERP.']),
    ]);

    $run = askExecutive();

    expect($run->status)->toBe('answered');
    expect($run->closed_by_budget)->toBeTrue();
    // Six asked for in one turn; the second refusal ended the tool loop, four never reached the gateway.
    expect(ToolCall::where('tool_key', 'erp.read_snapshot')->count())->toBe(2);
    expect($run->tool_calls_count)->toBe(2);
});

it('keeps the budget closing turn and its stop reason apart from the denial one', function () {
    FixtureProvider::script('fixture', [denialReply([
        'status' => AgentResponseSchema::STATUS_NEED_EVIDENCE,
        'tool_calls' => [['tool' => 'facts.query_orders', 'args' => [], 'why' => 'again']],
    ])]);

    $run = askExecutive('How are we doing?');

    expect($run->stop_reason)->toBe('loop_bound');
    expect($run->closed_by_budget)->toBeTrue();
    $requests = FixtureProvider::requestsTo('fixture');
    $last = end($requests)->messages;
    expect(end($last)['content'])->toContain(AgentRunner::CLOSING_NOTE)->not->toContain(AgentRunner::DENIAL_NOTE);
});
