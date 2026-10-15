<?php

use App\Agents\AgentEvaluationSubject;
use App\Agents\AgentResponseSchema;
use App\Agents\AgentRunner;
use App\Ai\ModelTier;
use App\Context\Provenance;
use App\Conversation\Channels\SimulatedChannelAdapter;
use App\Conversation\ConversationService;
use App\Conversation\ConversationState;
use App\Conversation\HandoffStateMachine;
use App\Conversation\Simulation\ConversationSimulator;
use App\Conversation\Simulation\ScenarioLibrary;
use App\Conversation\SupportContext;
use App\Identity\IdentityResolver;
use App\Memory\MemoryStore;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\EvaluationCase;
use App\Models\FactsOrder;
use App\Models\Memory;
use App\Models\PlatformAccount;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SafetyCorpusSeeder;
use Database\Seeders\SupportBehaviourSuiteSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Support\Ai\FixtureProvider;

/*
 | docs/78 B.1: what the support agent is given for a thread, and the one way memory is written.
 |
 | Proved here: the bundle is HQ's header, the customer's own facts with their recent orders, what a
 | colleague remembered (each block under its own provenance, introduced as memory that is not truth),
 | then the customer's words, untrusted; nothing generated is written back; the simulation captures the
 | same blocks and the P3-G2 subject hands them to the agent; a person remembers a fact only on a
 | settled thread with a known customer, a structured fact supersedes its key, an episode is never
 | edited, a closed fact stays on the record, every act is audited, and the thread page shows it all.
 */

beforeEach(function () {
    Cache::flush();
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    PlatformAccount::updateOrCreate(
        ['platform' => 'facebook_page', 'account_ref' => SimulatedChannelAdapter::DEFAULT_PAGE],
        ['brand_id' => Brand::where('code', 'LLD')->sole()->id, 'name' => 'Simulated page', 'approval_status' => 'unknown', 'is_active' => true],
    );
});

/** A thread from one customer message on the simulated page, the customer linked by identity when given. */
function threadFrom(string $text, string $psid = 'psid-mem-1', ?Customer $customer = null): Conversation
{
    if ($customer !== null) {
        CustomerIdentity::create(['customer_id' => $customer->id, 'business_id' => $customer->business_id, 'channel' => 'messenger_psid', 'external_id' => $psid, 'confidence' => 1.0]);
    }
    $adapter = new SimulatedChannelAdapter;
    $service = app(ConversationService::class);
    $message = null;
    foreach ($adapter->normalise(['page' => SimulatedChannelAdapter::DEFAULT_PAGE, 'messages' => [['from' => $psid, 'text' => $text, 'mid' => 'm-'.Str::uuid()]]]) as $inbound) {
        $message = $service->ingest($inbound);
    }

    return $message->conversation()->first();
}

function knownCustomer(): Customer
{
    return Customer::create([
        'business_id' => Brand::where('code', 'LLD')->sole()->business_id,
        'name' => 'Rina Akter', 'primary_phone_canonical' => '01711000009', 'orders_count' => 2, 'lifetime_value' => 2490,
        'first_seen_at' => now()->subMonths(3), 'last_seen_at' => now()->subDays(2), 'attributes' => [],
    ]);
}

function resolvedBy(Conversation $conversation, User $user): Conversation
{
    $states = app(HandoffStateMachine::class);
    $conversation = $states->transition($conversation->fresh(), ConversationState::HumanActive, $user->id, 'test: took over');

    return $states->transition($conversation, ConversationState::HumanResolved, $user->id, 'test: resolved');
}

it('gives the support agent the header, the customer facts with recent orders, labelled memory, then the untrusted messages, in that order', function () {
    $customer = knownCustomer();
    $store = Store::where('slug', 'laladia-com')->sole();
    FactsOrder::create(['business_id' => $customer->business_id, 'store_id' => $store->id, 'order_ref' => 'LLD-1', 'source' => 'test', 'status' => 'delivered', 'payment_status' => 'paid',
        'payment_method' => 'cod', 'subtotal' => 1200, 'discount_total' => 0, 'shipping_cost' => 90, 'gross' => 1290, 'customer_id' => $customer->id, 'placed_at' => now()->subDays(20), 'delivered_at' => now()->subDays(17), 'lines' => []]);
    FactsOrder::create(['business_id' => $customer->business_id, 'store_id' => $store->id, 'order_ref' => 'LLD-2', 'source' => 'test', 'status' => 'pending', 'payment_status' => 'unpaid',
        'payment_method' => 'cod', 'subtotal' => 1200, 'discount_total' => 0, 'shipping_cost' => 0, 'gross' => 1200, 'customer_id' => $customer->id, 'placed_at' => now()->subDays(1), 'lines' => []]);
    $memory = app(MemoryStore::class);
    $memory->remember('delivery_notes', ['text' => 'Gate locked after 8pm'], Provenance::Business, ['customer_id' => $customer->id, 'business_id' => $customer->business_id], ['source_ref' => 'user:1']);
    $memory->recordEpisode('Asked for a Friday delivery in August.', Provenance::Business, ['customer_id' => $customer->id, 'business_id' => $customer->business_id], ['occurred_at' => now()->subMonth()]);
    // Memory about someone else, and a closed value, never appear.
    $other = Customer::create(['business_id' => $customer->business_id, 'name' => 'Someone Else', 'first_seen_at' => now(), 'last_seen_at' => now(), 'attributes' => []]);
    $memory->remember('about', ['text' => 'not this customer'], Provenance::Business, ['customer_id' => $other->id, 'business_id' => $customer->business_id]);
    $memory->remember('preferred_language', ['text' => 'en'], Provenance::Business, ['customer_id' => $customer->id, 'business_id' => $customer->business_id]);
    $memory->remember('preferred_language', ['text' => 'bn'], Provenance::Business, ['customer_id' => $customer->id, 'business_id' => $customer->business_id]);

    $thread = threadFrom('আমার অর্ডার LLD-2 কবে আসবে?', 'psid-mem-1', $customer);
    $blocks = app(SupportContext::class)->blocksFor($thread);

    $sources = array_map(fn ($b) => $b->source, $blocks);
    expect($sources[0])->toBe('conversation:'.$thread->uuid)
        ->and($sources[1])->toBe('customer:'.$customer->id)
        ->and($sources[2])->toBe('memory:intro')
        ->and(array_slice($sources, 3, 3))->each->toStartWith('memory:')
        ->and(end($sources))->toStartWith('conversation:'.$thread->uuid.'/message:');

    // The customer block is HQ's own data and says so; the orders are facts with dates, newest first.
    expect($blocks[1]->provenance)->toBe(Provenance::Business)
        ->and($blocks[1]->content)->toContain('name: Rina Akter')->toContain('a known customer')->toContain('orders on record: 2')
        ->and($blocks[1]->content)->toContain('- LLD-2: pending, payment unpaid (cod), gross 1200.00')
        ->and(strpos($blocks[1]->content, 'LLD-2'))->toBeLessThan(strpos($blocks[1]->content, 'LLD-1'))
        ->and($blocks[1]->content)->toContain('delivered '.now()->subDays(17)->toDateString());

    // Memory: introduced as memory, each block under its own provenance, the superseded value gone.
    expect($blocks[2]->provenance)->toBe(Provenance::System)->and($blocks[2]->content)->toBe(SupportContext::MEMORY_INTRO);
    $memoryText = implode("\n", array_map(fn ($b) => $b->content, array_slice($blocks, 3, 3)));
    expect($memoryText)->toContain('[structured memory, recorded '.now()->toDateString().'] delivery_notes: Gate locked after 8pm')
        ->toContain('[episodic memory, recorded '.now()->subMonth()->toDateString().'] Asked for a Friday delivery in August.')
        ->toContain('preferred_language: bn')
        ->not->toContain('preferred_language: en')
        ->not->toContain('not this customer');
    expect(array_values(array_unique(array_map(fn ($b) => $b->provenance->value, array_slice($blocks, 3, 3)))))->toBe([Provenance::Business->value]);

    // The customer's words are untrusted, and last.
    $last = end($blocks);
    expect($last->provenance)->toBe(Provenance::Customer)->and($last->content)->toBe('আমার অর্ডার LLD-2 কবে আসবে?');

    // Round trip through plain data keeps every label.
    $again = SupportContext::fromData(SupportContext::asData($blocks));
    expect(array_map(fn ($b) => [$b->provenance->value, $b->source, $b->content], $again))
        ->toBe(array_map(fn ($b) => [$b->provenance->value, $b->source, $b->content], $blocks));
});

it('shows no memory section for a stranger, and writes nothing while building the bundle', function () {
    $thread = threadFrom('hi', 'psid-stranger');
    $before = Memory::count();

    $blocks = app(SupportContext::class)->blocksFor($thread);

    expect(array_map(fn ($b) => $b->source, $blocks))->not->toContain('memory:intro')
        ->and($blocks[1]->content)->toContain('a stranger so far')->toContain('recent orders: none on record')
        ->and(Memory::count())->toBe($before);
});

it('captures the same blocks in the simulation, and the P3-G2 subject hands them to the agent', function () {
    $this->seed(SafetyCorpusSeeder::class);
    $this->seed(SupportBehaviourSuiteSeeder::class);
    FixtureProvider::reset();
    aiProviders();
    enableAiRuntime();
    aiModel('fixture', 'fixture-model', ModelTier::Medium);
    $owner = User::factory()->create();
    $owner->givePermissionTo(['hq.tools.facts.read', 'hq.tools.commerce.read', 'hq.inbox.view', 'hq.inbox.manage']);
    $this->seed(AgentSeeder::class);
    Agent::where('key', 'support')->update(['status' => 'active', 'enabling_user_id' => $owner->id]);

    $scenario = ScenarioLibrary::find('known_customer.with_memory.agent_active');
    $thread = app(ConversationSimulator::class)->capture($scenario, 'LLD');
    $sources = array_column($thread['context'], 'source');

    expect($thread['core_passed'])->toBeTrue(implode('; ', $thread['core_mismatches']))
        ->and($thread['with_agent'])->toBeTrue()
        ->and($sources[2])->toBe('memory:intro')
        ->and(implode("\n", array_column($thread['context'], 'content')))->toContain('delivery_notes: Gate is locked after 8pm')->toContain('Asked twice in August')
        ->and(Memory::count())->toBe(0)->and(Customer::count())->toBe(0); // rolled back

    FixtureProvider::script('fixture', [
        FixtureProvider::reply((string) json_encode(['status' => AgentResponseSchema::STATUS_NEED_EVIDENCE, 'answer' => '', 'refusal_reason' => '', 'facts' => [], 'inferences' => [],
            'tool_calls' => [['tool' => 'messaging.send_reply', 'args' => ['business_slug' => 'laladia', 'conversation_uuid' => 'x', 'text' => 'জি, একই ঠিকানায় দুই বয়াম। একজন সহকর্মী তারিখ নিশ্চিত করবেন।'], 'why' => 'the reply']],
            'task' => 'answer the customer on this thread', 'authority_request' => 'none'])),
        FixtureProvider::reply((string) json_encode(['status' => AgentResponseSchema::STATUS_ANSWER, 'answer' => 'Proposed a reply.', 'refusal_reason' => '', 'facts' => [], 'inferences' => [], 'tool_calls' => [],
            'task' => 'answer the customer on this thread', 'authority_request' => 'none'])),
    ]);
    $case = EvaluationCase::where('key', 'sim.known_customer.with_memory.agent_active')->sole();
    $observation = (new AgentEvaluationSubject(app(AgentRunner::class), 'support'))->run($case);

    $sent = json_encode(FixtureProvider::requestsTo('fixture')[0], JSON_UNESCAPED_UNICODE);
    expect($observation->agentWoken)->toBeTrue()
        ->and($sent)->toContain(SupportContext::MEMORY_INTRO)
        ->and($sent)->toContain('delivery_notes: Gate is locked after 8pm')
        ->and($sent)->toContain('name: Rina Akter');
});

it('lets a manager remember a fact and an episode on a resolved thread, audited, and refuses an open thread and a stranger', function () {
    $user = $this->hqUser('support_agent');
    $customer = knownCustomer();
    $thread = threadFrom('Where is my order?', 'psid-mem-2', $customer);

    // Open thread: refused.
    $this->actingAsHq($user)->post("/inbox/{$thread->uuid}/remember", ['kind' => 'structured', 'key' => 'about', 'value' => 'x'])
        ->assertSessionHasErrors('kind');
    expect(Memory::count())->toBe(0);

    $thread = resolvedBy($thread, $user);
    $before = AuditLog::max('id') ?? 0;

    $this->actingAsHq($user)->post("/inbox/{$thread->uuid}/remember", ['kind' => 'structured', 'key' => 'delivery_notes', 'value' => 'Leave with the guard'])
        ->assertRedirect()->assertSessionHas('status', 'remembered');
    $this->actingAsHq($user)->post("/inbox/{$thread->uuid}/remember", ['kind' => 'structured', 'key' => 'delivery_notes', 'value' => 'Call first, gate locked'])
        ->assertRedirect();
    $this->actingAsHq($user)->post("/inbox/{$thread->uuid}/remember", ['kind' => 'episodic', 'content' => 'Asked for a refund on 3 September and withdrew it.'])
        ->assertRedirect();
    $this->actingAsHq($user)->post("/inbox/{$thread->uuid}/remember", ['kind' => 'structured', 'key' => 'address', 'value' => 'x'])
        ->assertSessionHasErrors('key');

    $current = Memory::whereNull('valid_to')->orderBy('id')->get();
    expect(Memory::count())->toBe(3)
        ->and($current)->toHaveCount(2)
        ->and($current[0]->key)->toBe('delivery_notes')->and($current[0]->value)->toBe(['text' => 'Call first, gate locked'])
        ->and($current[0]->supersedes_id)->not->toBeNull()
        ->and($current[0]->provenance)->toBe(Provenance::Business)
        ->and($current[0]->customer_id)->toBe($customer->id)
        ->and($current[0]->conversation_id)->toBe($thread->id)
        ->and($current[0]->source_ref)->toBe('user:'.$user->id)
        ->and($current[1]->content)->toContain('withdrew it')
        ->and(AuditLog::where('id', '>', $before)->where('action', 'memory.remembered')->count())->toBe(3);

    // The page shows what is remembered, with its source and date, and offers to close a fact.
    $this->actingAsHq($user)->get("/inbox/{$thread->uuid}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('inbox/show')
            ->where('can_remember', true)
            ->has('memory', 2)
            // Newest first: the episode was remembered last.
            ->where('memory.0.kind', 'episodic')->where('memory.0.closable', false)
            ->where('memory.1.key', 'delivery_notes')->where('memory.1.text', 'Call first, gate locked')->where('memory.1.closable', true)
            ->where('memory_keys', ['delivery_notes', 'preferred_language', 'preferred_contact_time', 'about']));

    // A stranger is a customer row already (docs/14: a stranger until a phone or an order links them), keyed
    // by the unified id, so a fact about them can be remembered too, and follows them when they are merged.
    $strangerThread = resolvedBy(threadFrom('hello', 'psid-nobody'), $user);
    $this->actingAsHq($user)->get("/inbox/{$strangerThread->uuid}")->assertInertia(fn ($page) => $page->where('can_remember', true)->where('memory', []));
    $this->actingAsHq($user)->post("/inbox/{$strangerThread->uuid}/remember", ['kind' => 'episodic', 'content' => 'Asked whether we deliver to Sylhet.'])->assertRedirect();
    expect(Memory::where('customer_id', $strangerThread->customer_id)->count())->toBe(1);

    // A viewer cannot remember.
    $viewer = $this->hqUser('finance_viewer');
    $this->actingAsHq($viewer)->post("/inbox/{$thread->uuid}/remember", ['kind' => 'episodic', 'content' => 'x'])->assertForbidden();
});

it('closes a structured fact without deleting it, and never closes an episode or another customer\'s fact', function () {
    $user = $this->hqUser('support_agent');
    $customer = knownCustomer();
    $thread = resolvedBy(threadFrom('Where is my order?', 'psid-mem-3', $customer), $user);
    $memory = app(MemoryStore::class);
    $fact = $memory->remember('about', ['text' => 'Prefers calls'], Provenance::Business, ['customer_id' => $customer->id, 'business_id' => $customer->business_id]);
    $episode = $memory->recordEpisode('Complained once.', Provenance::Business, ['customer_id' => $customer->id, 'business_id' => $customer->business_id], ['occurred_at' => now()]);
    $other = Customer::create(['business_id' => $customer->business_id, 'name' => 'Other', 'first_seen_at' => now(), 'last_seen_at' => now(), 'attributes' => []]);
    $theirs = $memory->remember('about', ['text' => 'theirs'], Provenance::Business, ['customer_id' => $other->id, 'business_id' => $customer->business_id]);

    $this->actingAsHq($user)->post("/inbox/{$thread->uuid}/memory/{$fact->id}/forget")->assertRedirect()->assertSessionHas('status', 'forgotten');
    $this->actingAsHq($user)->post("/inbox/{$thread->uuid}/memory/{$episode->id}/forget")->assertSessionHasErrors('memory');
    $this->actingAsHq($user)->post("/inbox/{$thread->uuid}/memory/{$theirs->id}/forget")->assertSessionHasErrors('memory');

    expect(Memory::count())->toBe(3)
        ->and($fact->refresh()->valid_to)->not->toBeNull()
        ->and($episode->refresh()->valid_to)->toBeNull()
        ->and($theirs->refresh()->valid_to)->toBeNull()
        ->and(AuditLog::where('action', 'memory.closed')->count())->toBe(1);

    // Closed, so no longer in the bundle; the row is still on the record.
    $blocks = app(SupportContext::class)->blocksFor($thread->fresh());
    expect(implode("\n", array_map(fn ($b) => $b->content, $blocks)))->not->toContain('Prefers calls')->toContain('Complained once.');
});

it('carries memory across a customer merge, the kept customer keeping its own live fact on a collision', function () {
    $memory = app(MemoryStore::class);
    $kept = knownCustomer();
    $absorbed = Customer::create(['business_id' => $kept->business_id, 'name' => 'R. Akter', 'first_seen_at' => now(), 'last_seen_at' => now(), 'attributes' => ['stranger' => true]]);
    $scope = fn (Customer $c) => ['customer_id' => $c->id, 'business_id' => $c->business_id];
    $memory->remember('delivery_notes', ['text' => 'kept: gate locked'], Provenance::Business, $scope($kept));
    $memory->remember('delivery_notes', ['text' => 'absorbed: ring twice'], Provenance::Business, $scope($absorbed));
    $memory->remember('preferred_language', ['text' => 'bn'], Provenance::Business, $scope($absorbed));
    $memory->recordEpisode('Asked about Sylhet delivery.', Provenance::Business, $scope($absorbed), ['occurred_at' => now()]);

    app(IdentityResolver::class)->merge($kept, $absorbed, null, 'same person');

    $live = Memory::where('customer_id', $kept->id)->whereNull('valid_to')->get();
    expect(Memory::where('customer_id', $absorbed->id)->count())->toBe(0)
        ->and(Memory::where('customer_id', $kept->id)->count())->toBe(4)
        ->and($live->where('key', 'delivery_notes')->sole()->value)->toBe(['text' => 'kept: gate locked'])
        ->and($live->where('key', 'preferred_language')->sole()->value)->toBe(['text' => 'bn'])
        ->and($live->where('kind', 'episodic')->count())->toBe(1)
        ->and(AuditLog::where('action', 'customer.merged')->latest('id')->first()->after['moved_memories'])->toBe(3);
});
