<?php

use App\Agents\AgentResponseSchema;
use App\Agents\AgentRunner;
use App\Agents\AgentRunRequest;
use App\Agents\TaskScope;
use App\Agents\TaskScopeResolver;
use App\Ai\ModelTier;
use App\Ai\Providers\ModelResponse;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Business;
use App\Models\ToolCall;
use App\Models\User;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Ai\FixtureProvider;

/*
 | A model omission is not a group-wide task (ADR-043, docs/74 C.2).
 |
 | Gate 8: "What has laladia.com sold since Monday?" with an unnamed store became four shop calls,
 | three of them to shops the question never mentioned. Now the runtime reads the operator's own
 | words for the targets the task is about, and an unscoped call is resolved to those targets, or
 | expanded to every permitted one only when the words are group-wide, or left for the gateway to
 | refuse when the words name nothing. The five shapes ChatGPT listed are the tests below.
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

function scopeReply(array $overrides): ModelResponse
{
    return FixtureProvider::reply((string) json_encode($overrides + [
        'status' => AgentResponseSchema::STATUS_ANSWER,
        'answer' => '', 'refusal_reason' => '', 'facts' => [], 'inferences' => [], 'tool_calls' => [],
        'task' => 'the operator question', 'authority_request' => AgentResponseSchema::AUTHORITY_NONE,
    ]));
}

function askScoped(string $question, string $tool, array $args = [], ?int $businessId = null): AgentRun
{
    FixtureProvider::script('fixture', [
        scopeReply([
            'status' => AgentResponseSchema::STATUS_NEED_EVIDENCE,
            'task' => $question,
            'tool_calls' => [['tool' => $tool, 'args' => $args, 'why' => 'the read']],
        ]),
        scopeReply(['status' => AgentResponseSchema::STATUS_ANSWER, 'task' => $question, 'answer' => 'Done, kept apart.']),
    ]);

    return app(AgentRunner::class)->run(new AgentRunRequest(agentKey: 'executive', question: $question, trigger: 'console', businessId: $businessId));
}

// ── The resolver's own table ───────────────────────────────────────────────────────────────────

it('reads the trusted text for the targets the task is about, versioned and deterministic', function (string $text, string $kind, array $businesses, array $stores) {
    $scope = app(TaskScopeResolver::class)->resolve($text);

    expect($scope->kind)->toBe($kind)
        ->and($scope->businessSlugs)->toBe($businesses)
        ->and($scope->storeSlugs)->toBe($stores)
        ->and($scope->version)->toBe(TaskScopeResolver::VERSION);
})->with([
    'a store by its domain' => ['What has laladia.com sold since Monday, and is anything about it unusual?', TaskScope::SINGLE, ['laladia'], ['laladia-com']],
    'a business by its slug' => ['How is bionic doing this week?', TaskScope::SINGLE, ['bionic'], []],
    'a store by its slug' => ['How many orders did bionic-garden take this week?', TaskScope::SINGLE, ['bionic'], ['bionic-garden']],
    'each business' => ['Read me the ERP overview for each business that has one, and say which have none.', TaskScope::GROUP, [], []],
    'every store' => ['What did every store sell today?', TaskScope::GROUP, [], []],
    'two businesses named' => ['Compare laladia and bionic on revenue this week.', TaskScope::NAMED, ['laladia', 'bionic'], []],
    'nothing named' => ['Read me the ERP overview.', TaskScope::NONE, [], []],
    'a bare continuation' => ['Proceed.', TaskScope::NONE, [], []],
]);

it('is single for the run\'s business before the text is read, when a run is opened for one', function () {
    $laladia = Business::where('slug', 'laladia')->sole();

    $scope = app(TaskScopeResolver::class)->resolve('Read me the ERP overview for each business.', $laladia->id);

    expect($scope->kind)->toBe(TaskScope::SINGLE)->and($scope->businessSlugs)->toBe(['laladia'])->and($scope->matched)->toContain('run opened for');
});

// ── The five shapes ────────────────────────────────────────────────────────────────────────────

it('resolves an unscoped call on a single-business task to that business and never to the group', function () {
    $run = askScoped('How is bionic doing this week? Read me its ERP overview.', 'erp.read_snapshot');

    expect($run->status)->toBe('answered');
    expect($run->task_scope['kind'])->toBe(TaskScope::SINGLE)->and($run->task_scope['businesses'])->toBe(['bionic']);

    $calls = ToolCall::where('tool_key', 'erp.read_snapshot')->get();
    expect($calls)->toHaveCount(1);
    expect($calls[0]->input['business_slug'])->toBe('bionic');

    $evidence = collect($run->evidence)->where('kind', 'evidence')->values();
    expect($evidence)->toHaveCount(1);
    expect($evidence[0]['args']['business_slug'])->toBe('bionic')
        ->and($evidence[0]['expanded_from'])->toBe('unscoped')
        ->and($evidence[0]['resolved_from'])->toBe('task');
});

it('resolves an unscoped store call on a single-store task to that store: one call, the named shop, nothing wider', function () {
    $run = askScoped('What has laladia.com sold since Monday, and is anything about it unusual?', 'commerce.list_orders');

    expect($run->task_scope['kind'])->toBe(TaskScope::SINGLE)->and($run->task_scope['stores'])->toBe(['laladia-com']);

    // One call, to the shop the question named. Its OUTCOME is a separate fact (no token is
    // stored in this test) and is asserted in OutcomeClassTest; here the shape is the point.
    $calls = ToolCall::where('tool_key', 'commerce.list_orders')->get();
    expect($calls)->toHaveCount(1);
    expect($calls[0]->input['store_slug'])->toBe('laladia-com');
});

it('expands an unscoped call on a group-wide task into one call per permitted business, each authorized on its own', function () {
    $active = Business::where('is_active', true)->orderBy('id')->pluck('slug')->all();

    $run = askScoped('Read me the ERP overview for each business that has one.', 'erp.read_snapshot');

    expect($run->task_scope['kind'])->toBe(TaskScope::GROUP)->and($run->task_scope['matched'])->toBe('each business');

    $calls = ToolCall::where('tool_key', 'erp.read_snapshot')->orderBy('id')->get();
    expect($calls)->toHaveCount(count($active));
    expect($calls->pluck('status')->unique()->all())->toBe(['succeeded']);
    // One gateway row per target: each was scoped, permitted and recorded separately.
    expect($calls->pluck('business_id')->filter()->unique()->count())->toBe(count($active));

    $evidence = collect($run->evidence)->where('kind', 'evidence');
    expect($evidence->pluck('expanded_from')->unique()->all())->toBe(['unscoped']);
    expect($evidence->pluck('resolved_from')->filter()->all())->toBe([]);
});

it('resolves an unscoped call on a task that names two businesses to exactly those two', function () {
    $run = askScoped('Compare laladia and bionic: read me the ERP overview of each of the two.', 'erp.read_snapshot');

    expect($run->task_scope['kind'])->toBe(TaskScope::NAMED)->and($run->task_scope['businesses'])->toBe(['laladia', 'bionic']);

    $calls = ToolCall::where('tool_key', 'erp.read_snapshot')->orderBy('id')->get();
    expect($calls->pluck('input.business_slug')->sort()->values()->all())->toBe(['bionic', 'laladia']);
});

it('leaves an unscoped call alone on a task that names nothing, and the gateway refuses it naming the argument', function () {
    $run = askScoped('Read me the ERP overview.', 'erp.read_snapshot');

    expect($run->task_scope['kind'])->toBe(TaskScope::NONE);

    $calls = ToolCall::where('tool_key', 'erp.read_snapshot')->get();
    expect($calls)->toHaveCount(1);
    expect($calls[0]->status)->toBe('denied')
        ->and($calls[0]->outcome_class)->toBe('scope_denied')
        ->and($calls[0]->error)->toContain('neither the call nor its arguments named one');

    $refused = collect($run->proposals)->where('kind', 'refused')->values();
    expect($refused)->toHaveCount(1)->and($refused[0]['outcome_class'])->toBe('scope_denied');
});

it('reads the group reference on a single-business task as the named business, because "all" was the model\'s word and not the operator\'s', function () {
    $run = askScoped('How is bionic doing? Read me its ERP overview.', 'erp.read_snapshot', ['business_slug' => AgentRunner::GROUP_REFERENCE]);

    $evidence = collect($run->evidence)->where('kind', 'evidence')->values();
    expect($evidence)->toHaveCount(1);
    expect($evidence[0]['args']['business_slug'])->toBe('bionic')
        ->and($evidence[0]['expanded_from'])->toBe(AgentRunner::GROUP_REFERENCE)
        ->and($evidence[0]['resolved_from'])->toBe('task');
});

it('records the scope on the run with the resolver version, so a gate can read back why a call was or was not widened', function () {
    $run = askScoped('Read me the ERP overview for each business that has one.', 'erp.read_snapshot');

    expect($run->task_scope)->toMatchArray(['kind' => 'group', 'businesses' => [], 'stores' => [], 'version' => TaskScopeResolver::VERSION]);
});
