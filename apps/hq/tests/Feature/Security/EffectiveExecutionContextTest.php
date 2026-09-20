<?php

use App\Agents\AgentResponseSchema;
use App\Agents\AgentRunner;
use App\Agents\AgentRunRequest;
use App\Agents\Shadow\ShadowProbes;
use App\Ai\ModelTier;
use App\Ask\AnswerAskTurn;
use App\Ask\AskHq;
use App\Execution\Requester;
use App\Execution\RequesterKind;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AskMessage;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Business;
use App\Models\FactsOrder;
use App\Models\FeatureFlag;
use App\Models\ShadowPeriod;
use App\Models\Store;
use App\Models\ToolCall;
use App\Models\User;
use App\Models\UserBusinessScope;
use App\Support\Authorization\BusinessScope;
use App\Support\Features\Features;
use Carbon\CarbonImmutable;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Ai\FixtureProvider;

/*
 | Effective Execution Context (docs/80 points 3, 4, 7, 10, 14; ADR-047).
 |
 | The defect these tests were written against (docs/78 C.3): an agent run acted under the enabling
 | user's reach and did not narrow to the person asking. Authority is an INTERSECTION, resolved once:
 | requester AND enabler AND business AND brand AND tool permission, with policy, consent and
 | readiness decided per call under the same context. Every case here goes through a real entry
 | point and the real gateway; nothing is asserted about a class in isolation.
 */

beforeEach(function () {
    Cache::flush();
    FixtureProvider::reset();
    $this->seed(DomainSeeder::class);
    aiProviders();
    enableAiRuntime();
    aiModel('fixture', 'fixture-model', ModelTier::Medium);

    $this->owner = $this->hqUser('owner');
    $this->seed(AgentSeeder::class);
    Agent::query()->whereIn('key', ['executive', 'analytics'])->update(['status' => 'active', 'enabling_user_id' => $this->owner->id]);

    $this->laladia = Business::where('slug', 'laladia')->sole();
    $this->bionic = Business::where('slug', 'bionic')->sole();
    $this->azmion = Business::where('slug', 'azmion')->sole();

    eecOrder('laladia-com');
    eecOrder('bionic-garden');
    eecOrder('bionic-garden');
});

function eecOrder(string $storeSlug): FactsOrder
{
    $store = Store::where('slug', $storeSlug)->sole();

    return FactsOrder::create([
        'business_id' => $store->business_id, 'store_id' => $store->id,
        'order_ref' => 'EEC-'.Str::random(10), 'status' => 'delivered', 'source' => 'checkout',
        'subtotal' => 1000, 'discount_total' => 0, 'shipping_cost' => 60, 'gross' => 1060,
        'placed_at' => now()->subDay(), 'synced_at' => now(),
        'lines' => [['sku' => 'A', 'name' => 'A', 'qty' => 1, 'unit_price' => 1000, 'total' => 1000]],
    ]);
}

/**
 * A person limited to the named businesses: an operator (every permission) by default, or somebody
 * holding exactly the permissions given.
 *
 * @param  list<string>  $businessSlugs
 * @param  list<string>|null  $permissions
 */
function eecOperator(array $businessSlugs = [], ?array $permissions = null): User
{
    if ($permissions === null) {
        $user = test()->hqUser('operator');
    } else {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
    }
    foreach ($businessSlugs as $slug) {
        UserBusinessScope::create(['user_id' => $user->id, 'business_id' => Business::where('slug', $slug)->value('id'), 'access' => 'read']);
    }
    app(BusinessScope::class)->forget($user);

    return $user;
}

function eecReply(array $overrides): mixed
{
    return FixtureProvider::reply((string) json_encode($overrides + [
        'status' => AgentResponseSchema::STATUS_ANSWER, 'answer' => 'Done.', 'refusal_reason' => '', 'facts' => [],
        'inferences' => [], 'tool_calls' => [], 'task' => 'the operator question', 'authority_request' => 'none',
    ]));
}

/**
 * One run of the executive: a first turn that names the given calls, a second that answers.
 *
 * @param  list<array{tool: string, args: array}>  $calls
 */
function eecRun(?Requester $requester, array $calls = [['tool' => 'facts.query_orders', 'args' => []]], array $options = []): AgentRun
{
    FixtureProvider::script('fixture', [
        eecReply(['status' => AgentResponseSchema::STATUS_NEED_EVIDENCE, 'answer' => '',
            'tool_calls' => array_map(fn ($c) => $c + ['why' => 'to answer'], $calls)]),
        eecReply([]),
    ]);

    return app(AgentRunner::class)->run(new AgentRunRequest(
        agentKey: $options['agent'] ?? 'executive',
        question: $options['question'] ?? 'How did every business do yesterday?',
        trigger: $options['trigger'] ?? 'operator',
        operator: $options['operator'] ?? null,
        businessId: $options['business_id'] ?? null,
        brandId: $options['brand_id'] ?? null,
        requester: $requester,
    ));
}

/** The prompt the model was shown on the first turn. */
function eecPrompt(): string
{
    return (string) FixtureProvider::requestsTo('fixture')[0]->messages[0]['content'];
}

function eecRowsSeenBy(AgentRun $run): ?int
{
    $call = ToolCall::where('correlation_id', $run->correlation_id)->where('tool_key', 'facts.query_orders')->latest('id')->first();

    return $call?->output['coverage']['rows_matched'] ?? null;
}

// ── The defect: the asker was a label ───────────────────────────────────────────────────────────

it('narrows a run to a restricted requester even though the enabling user is the group-wide owner', function () {
    $asker = eecOperator(['laladia']);

    $run = eecRun(Requester::human($asker, 'test'), [
        ['tool' => 'facts.query_orders', 'args' => []],
        ['tool' => 'erp.read_snapshot', 'args' => ['business_slug' => 'bionic']],
    ]);

    // The group-wide read saw the asker's one business, not the owner's five.
    expect(eecRowsSeenBy($run))->toBe(1);

    // A guard nothing can reach is not a guard: the model named a business it was never shown, the
    // call REACHED the gateway, and the gateway refused it on scope and recorded the refusal.
    $refused = ToolCall::where('correlation_id', $run->correlation_id)->where('tool_key', 'erp.read_snapshot')->sole();
    expect($refused->status)->toBe('denied')
        ->and($refused->outcome_class)->toBe('scope_denied')
        ->and($refused->error)->toContain('not scoped to business '.$this->bionic->id);

    // And it was never shown what it may not use.
    expect(eecPrompt())->toContain('- laladia:')->not->toContain('- bionic:')->not->toContain('"bionic"');
});

it('keeps an owner requester inside a restricted enabling user', function () {
    $enabler = eecOperator(['bionic']);
    Agent::where('key', 'executive')->update(['enabling_user_id' => $enabler->id]);

    $run = eecRun(Requester::human($this->owner, 'test'));

    expect(eecRowsSeenBy($run))->toBe(2)
        ->and(eecPrompt())->toContain('- bionic:')->not->toContain('- laladia:');
});

it('gives two restricted people with different scopes only what both of them have', function () {
    $enabler = eecOperator(['laladia', 'bionic']);
    Agent::where('key', 'executive')->update(['enabling_user_id' => $enabler->id]);
    $asker = eecOperator(['bionic', 'azmion'], ['hq.tools.facts.read', 'hq.tools.erp.read']);

    $run = eecRun(Requester::human($asker, 'test'), [
        ['tool' => 'facts.query_orders', 'args' => []],
        ['tool' => 'erp.read_snapshot', 'args' => ['business_slug' => 'laladia']],   // the enabler's, not the asker's
        ['tool' => 'erp.read_snapshot', 'args' => ['business_slug' => 'azmion']],    // the asker's, not the enabler's
    ]);

    expect(eecRowsSeenBy($run))->toBe(2)
        ->and($run->execution_context['scope']['business_ids'])->toBe([$this->bionic->id])
        ->and(ToolCall::where('correlation_id', $run->correlation_id)->where('tool_key', 'erp.read_snapshot')->pluck('outcome_class')->all())
        ->toBe(['scope_denied', 'scope_denied']);
});

it('refuses the run when both hold the same permissions and share no business', function () {
    $enabler = eecOperator(['laladia']);
    Agent::where('key', 'executive')->update(['enabling_user_id' => $enabler->id]);
    $asker = eecOperator(['azmion']);

    expect(fn () => eecRun(Requester::human($asker, 'test')))
        ->toThrow(RuntimeException::class, 'share no business');

    expect(AgentRun::count())->toBe(0)
        ->and(FixtureProvider::callsTo('fixture'))->toBe(0)
        ->and(AuditLog::where('action', 'agent_run.context_refused')->count())->toBe(1);
});

it('holds a run inside the agent\'s brand scope: same business, another brand', function () {
    $own = Brand::where('slug', 'laladia')->sole();
    $other = Brand::create(['code' => 'LL2', 'slug' => 'laladia-second', 'name' => 'LaLaDia Second', 'business_id' => $this->laladia->id, 'is_active' => true]);
    Agent::where('key', 'executive')->update(['brand_scope' => json_encode([$own->id])]);

    expect(fn () => eecRun(Requester::human($this->owner, 'test'), options: ['business_id' => $this->laladia->id, 'brand_id' => $other->id]))
        ->toThrow(RuntimeException::class, 'brand');
    expect(AgentRun::count())->toBe(0);

    $run = eecRun(Requester::human($this->owner, 'test'), options: ['business_id' => $this->laladia->id, 'brand_id' => $own->id]);
    expect($run->execution_context['scope']['brand_ids'])->toBe([$own->id])
        ->and($run->execution_context['scope']['requested_brand_id'])->toBe($own->id);
});

it('never lets the enabling user lend a permission the requester lacks', function () {
    $asker = User::factory()->create();
    $asker->givePermissionTo(['hq.tools.facts.read']);

    $run = eecRun(Requester::human($asker, 'test'), [
        ['tool' => 'facts.query_orders', 'args' => []],
        ['tool' => 'hq.list_jobs', 'args' => []],
    ]);

    // Shown only what both hold...
    expect(eecPrompt())->toContain('"facts.query_orders"')->not->toContain('"hq.list_jobs"');

    // ...and the tool it named anyway reached the gateway and was refused on permission there.
    $jobs = ToolCall::where('correlation_id', $run->correlation_id)->where('tool_key', 'hq.list_jobs')->sole();
    expect($jobs->status)->toBe('denied')
        ->and($jobs->outcome_class)->toBe('permission_denied')
        ->and(eecRowsSeenBy($run))->toBe(3)
        ->and($run->execution_context['permissions'])->toBe(['hq.tools.facts.read']);
});

it('never lets the requester carry a permission the enabling user lacks', function () {
    $enabler = User::factory()->create();
    $enabler->givePermissionTo(['hq.tools.facts.read']);
    Agent::where('key', 'executive')->update(['enabling_user_id' => $enabler->id]);

    $run = eecRun(Requester::human($this->owner, 'test'), [['tool' => 'hq.list_jobs', 'args' => []]]);

    expect(eecPrompt())->not->toContain('"hq.list_jobs"')
        ->and(ToolCall::where('correlation_id', $run->correlation_id)->where('tool_key', 'hq.list_jobs')->sole()->outcome_class)->toBe('permission_denied')
        ->and($run->execution_context['permissions'])->toBe(['hq.tools.facts.read']);
});

it('refuses an asker who shares none of the permissions the agent\'s tools need', function () {
    $asker = User::factory()->create();

    expect(fn () => eecRun(Requester::human($asker, 'test')))->toThrow(RuntimeException::class, 'none of the tools');
    expect(AgentRun::count())->toBe(0)->and(FixtureProvider::callsTo('fixture'))->toBe(0);
});

// ── No identity, no run ─────────────────────────────────────────────────────────────────────────

it('runs nothing for a request that names no requester: there is no default', function () {
    expect(fn () => eecRun(null))->toThrow(RuntimeException::class, 'names no requester');

    expect(AgentRun::count())->toBe(0)->and(FixtureProvider::callsTo('fixture'))->toBe(0);
});

it('runs nothing for an agent with no enabling identity, whoever asks, and audits the refusal', function () {
    Agent::where('key', 'support')->update(['status' => 'active']);

    expect(fn () => eecRun(Requester::human($this->owner, 'test'), options: ['agent' => 'support']))
        ->toThrow(RuntimeException::class, 'names no enabling user');
    expect(fn () => eecRun(Requester::asEnabled(RequesterKind::GateRunner, 'test'), options: ['agent' => 'support']))
        ->toThrow(RuntimeException::class, 'names no enabling user');

    expect(AgentRun::count())->toBe(0)
        ->and(FixtureProvider::callsTo('fixture'))->toBe(0)
        ->and(AuditLog::where('action', 'agent_run.context_refused')->count())->toBe(2);
});

it('runs nothing for a requester or an enabling user who was disabled or deleted while the question waited in the queue', function () {
    FeatureFlag::updateOrCreate(['key' => AskHq::FLAG, 'environment' => '*'], ['enabled' => true, 'rollout' => null]);
    app(Features::class)->forget();
    $asker = eecOperator(['laladia']);

    Queue::fake();
    $pending = app(AskHq::class)->submit($asker, null, 'How is LaLaDia doing?', 'executive', null)['reply'];
    $asker->forceFill(['is_active' => false])->save();
    (new AnswerAskTurn($pending->id))->handle(app(AskHq::class));

    expect($pending->fresh()->status)->toBe(AskMessage::FAILED)
        ->and($pending->fresh()->content)->toContain('disabled')
        ->and(AgentRun::count())->toBe(0)
        ->and(FixtureProvider::callsTo('fixture'))->toBe(0);

    // The other side: the enabling user disabled after the question was accepted.
    $second = app(AskHq::class)->submit($this->owner, null, 'And the group?', 'executive', null)['reply'];
    $enabler = eecOperator();
    Agent::where('key', 'executive')->update(['enabling_user_id' => $enabler->id]);
    $enabler->forceFill(['is_active' => false])->save();
    (new AnswerAskTurn($second->id))->handle(app(AskHq::class));

    expect($second->fresh()->status)->toBe(AskMessage::FAILED)
        ->and($second->fresh()->content)->toContain('disabled')
        ->and(AgentRun::count())->toBe(0);

    // A person recorded on a period and since deleted is not replaced by anybody.
    Agent::where('key', 'executive')->update(['enabling_user_id' => $this->owner->id]);
    expect(fn () => eecRun(Requester::onBehalfOf(987654, 'shadow_periods#1.opened_by_user_id')))
        ->toThrow(RuntimeException::class, 'no longer exists');
});

// ── Ask HQ: real narrowing in place of the group-wide restriction ───────────────────────────────

it('lets a restricted operator use Ask HQ and answers inside the intersection only', function () {
    FeatureFlag::updateOrCreate(['key' => AskHq::FLAG, 'environment' => '*'], ['enabled' => true, 'rollout' => null]);
    app(Features::class)->forget();
    $asker = eecOperator(['laladia']);

    expect(app(AskHq::class)->refusalFor($asker, 'executive'))->toBeNull();

    FixtureProvider::script('fixture', [
        eecReply(['status' => AgentResponseSchema::STATUS_NEED_EVIDENCE, 'answer' => '', 'tool_calls' => [['tool' => 'facts.query_orders', 'args' => [], 'why' => 'count']]]),
        eecReply(['answer' => 'One order.']),
    ]);
    $this->actingAsHq($asker)->post('/ask', ['question' => 'How did we do yesterday?', 'agent_key' => 'executive'])
        ->assertRedirect()->assertSessionHas('status', 'ask-submitted');

    $run = AgentRun::sole();
    expect(AskMessage::where('role', 'assistant')->sole()->content)->toBe('One order.')
        ->and(eecRowsSeenBy($run))->toBe(1)
        ->and($run->execution_context['requester']['user_id'])->toBe($asker->id)
        ->and($run->execution_context['requester']['kind'])->toBe('human')
        ->and($run->execution_context['enabler']['user_id'])->toBe($this->owner->id)
        ->and($run->execution_context['scope']['business_ids'])->toBe([$this->laladia->id]);

    // A business outside the asker's own rows is still refused at the door, as before.
    $this->actingAsHq($asker)->post('/ask', ['question' => 'And Bionic?', 'agent_key' => 'executive', 'business_id' => $this->bionic->id])
        ->assertSessionHasErrors('question');
});

// ── A scheduled or system invocation takes the same path ────────────────────────────────────────

it('runs a Shadow Probe on behalf of the person who opened the period, narrowed to that person', function () {
    $opener = eecOperator(['bionic']);
    $period = ShadowPeriod::create([
        'uuid' => (string) Str::uuid(), 'agent_id' => Agent::where('key', 'executive')->value('id'), 'agent_key' => 'executive',
        'autonomy_level' => 'a0', 'status' => ShadowPeriod::STATUS_OPEN, 'opened_at' => now(), 'planned_end_at' => now()->addDays(7),
        'opened_by_user_id' => $opener->id, 'opened_by_label' => 'test', 'reason' => 'test', 'evidence' => [], 'allowed_tools' => [],
        'questions' => [['key' => 'group_yesterday', 'question' => 'How did the group do yesterday?']], 'probes_required' => true,
    ]);

    FixtureProvider::script('fixture', [
        eecReply(['status' => AgentResponseSchema::STATUS_NEED_EVIDENCE, 'answer' => '', 'tool_calls' => [['tool' => 'facts.query_orders', 'args' => [], 'why' => 'count']]]),
        eecReply([]),
    ]);
    app(ShadowProbes::class)->ask($period, 'morning', CarbonImmutable::now()->startOfDay(), true);

    $run = AgentRun::sole();
    expect($run->trigger)->toBe('shadow_probe')
        ->and(eecRowsSeenBy($run))->toBe(2)
        ->and($run->execution_context['requester']['kind'])->toBe('on_behalf_of')
        ->and($run->execution_context['requester']['user_id'])->toBe($opener->id)
        ->and($run->execution_context['requester']['basis'])->toBe("shadow_periods#{$period->id}.opened_by_user_id")
        ->and($run->execution_context['scope']['business_ids'])->toBe([$this->bionic->id]);
});

// ── The record ──────────────────────────────────────────────────────────────────────────────────

it('records the resolved context on the run and in the audit log, with why it was narrowed and nothing secret', function () {
    $asker = eecOperator(['laladia'], ['hq.tools.facts.read']);

    $run = eecRun(Requester::human($asker, 'ask_conversations#1.user_id'));
    $context = $run->execution_context;

    expect($context['resolver_version'])->toBe('eec.v1')
        ->and($context['requester'])->toMatchArray(['kind' => 'human', 'user_id' => $asker->id, 'as_enabled' => false, 'basis' => 'ask_conversations#1.user_id'])
        ->and($context['enabler']['user_id'])->toBe($this->owner->id)
        ->and($context['acting_identity'])->toBe('agent:executive')
        ->and($context['agent']['key'])->toBe('executive')
        ->and($context['entry_point'])->toBe('operator')
        ->and($context['environment'])->toBe('testing')
        ->and($context['scope']['business_ids'])->toBe([$this->laladia->id])
        ->and($context['scope']['stores'])->toContain('business')
        ->and($context['decided_per_call'])->toContain('policy', 'consent', 'capability_readiness')
        ->and($context['permissions'])->not->toBeEmpty()
        ->and(collect($context['narrowed'])->pluck('term')->all())->toContain('business', 'tool_permission')
        ->and($run->execution_context_digest)->toBe($context['digest'])
        ->and(strlen($context['digest']))->toBe(64);

    $audit = AuditLog::where('action', 'agent_run.context_resolved')->sole();
    expect($audit->after['digest'])->toBe($context['digest'])
        ->and($audit->after['requester']['user_id'])->toBe($asker->id);

    $flat = mb_strtolower((string) json_encode([$context, $audit->after]));
    foreach (['password', 'secret', 'token', 'two_factor', $asker->email, $this->owner->email] as $never) {
        expect($flat)->not->toContain(mb_strtolower($never));
    }
});
