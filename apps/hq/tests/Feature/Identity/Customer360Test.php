<?php

use App\Consent\ConsentBasis;
use App\Consent\ConsentLedger;
use App\Consent\ConsentPurpose;
use App\Consent\ConsentState;
use App\Context\Provenance;
use App\Conversation\ConversationState;
use App\Conversation\EscalationTriggers;
use App\Conversation\HandoffStateMachine;
use App\Conversation\HandoverReason;
use App\Identity\CrossBusinessMerge;
use App\Identity\IdentityResolver;
use App\Memory\MemoryStore;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\CustomerMergeCandidate;
use App\Models\FactsOrder;
use App\Models\Store;
use App\Models\UserBusinessScope;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 | docs/109 (stream S2, ruling docs/103 §5 and §11): the Customer 360 page. Read-only, business-scoped,
 | kinds kept apart (observed, inferred, source-of-truth order copies, conversations, memory, consent,
 | promises, hand-overs, an S3 placeholder), no cross-brand identity, and rendering it writes nothing.
 */

beforeEach(function () {
    Cache::flush();
    Http::fake();
    config(['hq.alerts.telegram_bot_token' => '', 'hq.alerts.telegram_chat_id' => '']);
    $this->seed(DomainSeeder::class);
    $this->owner = $this->hqUser('owner');
    $this->laladia = Business::where('slug', 'laladia')->sole();
    $this->bionic = Business::where('slug', 'bionic')->sole();
});

/** A customer of one business with one of everything the page reads. */
function c360Customer(Business $business, string $phone = '01711000001', string $tag = 'L'): Customer
{
    $customer = Customer::create([
        'business_id' => $business->id, 'primary_phone_canonical' => $phone, 'name' => "Rahim {$tag}", 'email' => strtolower($tag).'-rahim@example.test',
        'first_seen_at' => now()->subDays(40), 'last_seen_at' => now()->subDay(), 'orders_count' => 2, 'lifetime_value' => 1500,
        'consent' => ['legacy_marketing' => true], 'attributes' => ['aliases' => ["Rahim Uddin {$tag}"]],
    ]);
    CustomerIdentity::create(['customer_id' => $customer->id, 'business_id' => $customer->business_id, 'channel' => 'phone', 'external_id' => $phone, 'confidence' => 1.0, 'last_seen_at' => now()]);
    CustomerIdentity::create(['customer_id' => $customer->id, 'business_id' => $customer->business_id, 'channel' => 'messenger', 'external_id' => "psid-{$tag}-".Str::random(6), 'confidence' => 1.0]);

    $store = Store::where('business_id', $business->id)->firstOrFail();
    FactsOrder::create([
        'business_id' => $business->id, 'store_id' => $store->id, 'order_ref' => "{$tag}-ORD-1", 'source' => 'website',
        'status' => 'delivered', 'payment_status' => 'paid', 'gross' => 1000, 'customer_id' => $customer->id,
        'placed_at' => now()->subDays(10), 'last_event_at' => now()->subDays(9), 'synced_at' => now()->subDays(2),
    ]);
    FactsOrder::create([
        'business_id' => $business->id, 'store_id' => $store->id, 'order_ref' => "{$tag}-ORD-2", 'source_system' => 'erp', 'provenance' => 'erp_read_back',
        'erp_order_number' => "SO-{$tag}-77", 'status' => 'pending', 'gross' => 500, 'customer_id' => $customer->id,
        'placed_at' => now()->subDays(3), 'read_back_at' => now()->subDays(1),
    ]);

    $conversation = Conversation::create([
        'uuid' => (string) Str::uuid(), 'business_id' => $business->id, 'customer_id' => $customer->id, 'channel' => 'messenger',
        'status' => 'open', 'state' => ConversationState::AiActive->value, 'opened_at' => now()->subDays(5), 'last_message_at' => now()->subDays(4),
    ]);
    $machine = app(HandoffStateMachine::class);
    $machine->transition($conversation, ConversationState::HumanRequired, null, EscalationTriggers::REASON_MONEY_OR_COMPLAINT);
    $machine->transition($conversation->fresh(), ConversationState::HumanActive, test()->owner->id, 'taken over from the inbox');
    $machine->transition($conversation->fresh(), ConversationState::HumanResolved, test()->owner->id, 'refund agreed by phone');
    $machine->transition($conversation->fresh(), ConversationState::AiResumed, test()->owner->id, 'handed back to the agent from the inbox');

    $scope = ['customer_id' => $customer->id, 'business_id' => $business->id];
    app(MemoryStore::class)->remember('delivery_notes', ['text' => "Call before delivery {$tag}"], Provenance::Business, $scope, ['source_ref' => 'user:'.test()->owner->id, 'conversation_id' => $conversation->id]);
    app(MemoryStore::class)->recordEpisode("Complained about a broken jar {$tag}", Provenance::Business, $scope, ['occurred_at' => now()->subDays(4)]);
    app(MemoryStore::class)->believe("Probably prefers ghee {$tag}", 0.4, Provenance::Generated, $scope);

    app(ConsentLedger::class)->record($customer->id, $business->id, ConsentPurpose::Marketing, ConsentState::Granted, ConsentBasis::Operator, 'whatsapp', now()->subDays(20), [
        'actor_type' => 'human', 'actor_id' => test()->owner->id, 'actor_label' => "secret-actor-label-{$tag}",
        'reason' => "secret-consent-reason-{$tag}", 'evidence_ref' => "secret-evidence-ref-{$tag}", 'evidence' => ['note' => "secret-evidence-body-{$tag}"],
    ]);

    return $customer->fresh();
}

function c360Props($response): array
{
    return $response->viewData('page')['props'];
}

it('is a GET behind auth, both 2FA gates and the customers permission that already exists, and adds no write route', function () {
    $route = Route::getRoutes()->getByName('customers.show');

    expect($route->methods())->toBe(['GET', 'HEAD'])
        ->and($route->gatherMiddleware())->toContain('auth', '2fa.enabled', '2fa.verified', 'permission:hq.customers.view')
        // The write routes under /customers, pinned on purpose: identity merge and dismiss (pre-existing) and the
        // consent ledger write of docs/108 (S7), the quarantine review decision of docs/117, and the docs/126 repair and held-memory assignment. Sorted, so
        // registration order cannot move the pin.
        ->and(collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'customers') && array_diff($r->methods(), ['GET', 'HEAD']) !== [])
            ->map(fn ($r) => $r->uri())->sort()->values()->all())->toBe(['customers/candidates/{candidate}/dismiss', 'customers/merge', 'customers/quarantine/memories/{memory}/assign', 'customers/quarantine/repair', 'customers/quarantine/{quarantine}/review', 'customers/{customer}/consent']);
});

it('opens for the roles that may see customers and for no other', function () {
    $customer = c360Customer($this->laladia);
    $this->get("/customers/{$customer->id}")->assertRedirect();

    foreach (['owner' => true, 'operator' => true, 'brand_manager' => true, 'support_agent' => true, 'finance_viewer' => false] as $role => $allowed) {
        $response = $this->actingAsHq($this->hqUser($role))->get("/customers/{$customer->id}");
        $allowed ? $response->assertOk() : $response->assertForbidden();
    }
});

it('shows every section, each under its own kind, without blending them', function () {
    $customer = c360Customer($this->laladia);

    $response = $this->actingAsHq($this->owner)->get("/customers/{$customer->id}")->assertOk();
    $response->assertInertia(fn ($page) => $page->component('customers/show')
        ->where('customer.business.slug', 'laladia')
        // Observed.
        ->where('observed.phone', '01711000001')
        ->has('observed.identities', 2)
        // Inferred: link rules, rollups, aliases, beliefs (semantic memory only).
        ->where('inferred.identity_links.0.rule', 'canonical_phone')
        ->where('inferred.orders_count', 2)
        ->where('inferred.aliases', ['Rahim Uddin L'])
        ->has('inferred.beliefs', 1)
        ->where('inferred.beliefs.0.text', 'Probably prefers ghee L')
        // Source-of-truth orders: who holds the truth and how old the copy is.
        ->where('orders.total', 2)
        ->where('orders.rows.0.ref', 'L-ORD-2')
        ->where('orders.rows.0.owner_system', 'erp')
        ->where('orders.rows.0.erp_order_number', 'SO-L-77')
        ->where('orders.rows.1.owner_system', 'shop')
        ->whereType('orders.rows.1.copy_updated_at', 'string')
        // Conversations.
        ->where('conversations.total', 1)
        ->where('conversations.rows.0.state', 'ai_resumed')
        // Memory: structured and episodic only.
        ->has('memory.rows', 2)
        // Consent: projection + ledger, legacy blob apart.
        ->where('consent.current.0.purpose', 'marketing')
        ->where('consent.current.0.state', 'granted')
        ->where('consent.current.0.permits', true)
        ->where('consent.ledger.0.basis', 'operator')
        ->where('consent.ledger.0.has_evidence', true)
        ->where('consent.legacy', ['legacy_marketing' => true])
        // Promises are follow-ups of origin `promise` (docs/123; CustomerFollowUpsSectionTest covers the section), and the S3 outcome section is a placeholder.
        ->where('follow_ups.follow_ups', [])
        ->where('outcomes', ['status' => 'awaiting_s3'])
        // Hand-overs: agent -> person (with reason) and person -> agent; person -> person is not one.
        ->has('handovers.rows', 2)
        ->where('handovers.rows.0.direction', 'to_agent')
        ->where('handovers.rows.0.by', $this->owner->email)
        ->where('handovers.rows.1.direction', 'to_person')
        ->where('handovers.rows.1.reason', HandoverReason::MoneyOrComplaint->value)
        ->where('handovers.rows.1.by', null)
    );

    $props = c360Props($response);
    $memoryText = collect($props['memory']['rows'])->pluck('text')->all();
    expect($memoryText)->toContain('Call before delivery L', 'Complained about a broken jar L')
        ->not->toContain('Probably prefers ghee L')
        ->and(json_encode($props['observed']))->not->toContain('Call before delivery')->not->toContain('ghee')
        ->and(json_encode($props['inferred']))->not->toContain('Call before delivery')->not->toContain('broken jar')
        ->and(json_encode($props['observed']))->not->toContain('legacy_marketing')
        ->and(json_encode($props['inferred']))->not->toContain('legacy_marketing');
});

it('never sends consent evidence, its reason or who recorded it by name', function () {
    $customer = c360Customer($this->laladia);

    $json = json_encode(c360Props($this->actingAsHq($this->owner)->get("/customers/{$customer->id}")->assertOk()));

    expect($json)->not->toContain('secret-actor-label')
        ->not->toContain('secret-consent-reason')
        ->not->toContain('secret-evidence-ref')
        ->not->toContain('secret-evidence-body');
});

it('shows inbox-derived sections only to people who may read the inbox', function () {
    $customer = c360Customer($this->laladia);

    // brand_manager holds hq.customers.view and not hq.inbox.view.
    $this->actingAsHq($this->hqUser('brand_manager'))->get("/customers/{$customer->id}")->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('conversations', null)->where('memory', null)->where('handovers', null)
            ->where('can.inbox', false)
            ->where('orders.total', 2)->has('consent.ledger', 1));

    $this->actingAsHq($this->hqUser('support_agent'))->get("/customers/{$customer->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.inbox', true)->has('memory.rows', 2)->has('handovers.rows', 2));
});

it('keeps one phone in two businesses as two customers, and shows neither on the other\'s page', function () {
    $lld = c360Customer($this->laladia, '01711000009', 'L');
    $bnc = c360Customer($this->bionic, '01711000009', 'B');
    // A pending candidate pointing across businesses (which the resolver never queues) is not shown either.
    CustomerMergeCandidate::create(['customer_id' => $lld->id, 'candidate_id' => $bnc->id, 'confidence' => 0.6, 'reason' => 'planted', 'status' => 'pending']);

    expect($lld->id)->not->toBe($bnc->id);

    $props = c360Props($this->actingAsHq($this->owner)->get("/customers/{$lld->id}")->assertOk());
    $json = json_encode($props);

    expect($props['customer']['business']['slug'])->toBe('laladia')
        ->and(collect($props['orders']['rows'])->pluck('ref')->all())->toBe(['L-ORD-2', 'L-ORD-1'])
        ->and($props['inferred']['candidates'])->toBe([])
        ->and($json)->not->toContain('Rahim B')->not->toContain('b-rahim@')->not->toContain('B-ORD')
        ->not->toContain('ghee B')->not->toContain('broken jar B')->not->toContain('Call before delivery B')
        ->not->toContain('"other_id":'.$bnc->id)->not->toContain('"absorbed_id":'.$bnc->id);
});

it('enforces business scope: a person restricted to one business cannot open or list another\'s customer', function () {
    $lld = c360Customer($this->laladia, '01711000011', 'L');
    $bnc = c360Customer($this->bionic, '01711000012', 'B');

    $scoped = $this->hqUser('owner');
    UserBusinessScope::create(['user_id' => $scoped->id, 'business_id' => $this->laladia->id, 'access' => 'full']);

    $this->actingAsHq($scoped)->get("/customers/{$bnc->id}")->assertNotFound();
    $this->actingAsHq($scoped)->get("/customers/{$lld->id}")->assertOk();
    $this->actingAsHq($scoped)->get('/customers')->assertOk()
        ->assertInertia(fn ($page) => $page->where('customers.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$lld->id]));

    // Group-wide (no scope rows) sees both.
    $this->actingAsHq($this->owner)->get("/customers/{$bnc->id}")->assertOk();
});

it('follows a merge within one business, and never into another business (docs/117)', function () {
    // Within one business: the absorbed row's link opens the survivor.
    $survivor = c360Customer($this->laladia, '01711000020', 'L');
    $old = Customer::create(['business_id' => $this->laladia->id, 'name' => 'Old L row', 'merged_into_id' => $survivor->id]);
    $this->actingAsHq($this->owner)->get("/customers/{$old->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('customer.id', $survivor->id));

    // Across businesses (a pre-guard link): it stops at the requested row. Before docs/117 a person restricted
    // to LaLaDia got a 404 here and an unrestricted one landed on the Bionic page; now both see the LaLaDia
    // row itself, with nothing of Bionic and the link held.
    $kept = c360Customer($this->bionic, '01711000021', 'B');
    $absorbed = Customer::create(['business_id' => $this->laladia->id, 'name' => 'Old row', 'merged_into_id' => $kept->id]);

    $scoped = $this->hqUser('owner');
    UserBusinessScope::create(['user_id' => $scoped->id, 'business_id' => $this->laladia->id, 'access' => 'full']);

    foreach ([$scoped, $this->owner] as $viewer) {
        $props = c360Props($this->actingAsHq($viewer)->get("/customers/{$absorbed->id}")->assertOk());
        expect($props['customer']['id'])->toBe($absorbed->id)
            ->and($props['customer']['business']['slug'])->toBe('laladia')
            ->and($props['quarantine']['link_held'])->toBeTrue()
            ->and(json_encode($props))->not->toContain('Rahim B')->not->toContain('B-ORD')->not->toContain('"id":'.$kept->id.',');
    }
});

it('refuses to merge customers of different businesses, for anyone, and a restricted person cannot merge outside their businesses', function () {
    $lld = c360Customer($this->laladia, '01711000031', 'L');
    $bnc = c360Customer($this->bionic, '01711000031', 'B');

    expect(fn () => app(IdentityResolver::class)->merge($lld, $bnc, $this->owner->id, 'same phone'))->toThrow(CrossBusinessMerge::class);

    $this->actingAsHq($this->owner)->post('/customers/merge', ['kept_id' => $lld->id, 'absorbed_id' => $bnc->id])
        ->assertSessionHasErrors('absorbed_id');
    expect($bnc->fresh()->merged_into_id)->toBeNull();

    $otherBionic = Customer::create(['business_id' => $this->bionic->id, 'name' => 'Another']);
    $scoped = $this->hqUser('owner');
    UserBusinessScope::create(['user_id' => $scoped->id, 'business_id' => $this->laladia->id, 'access' => 'full']);
    $this->actingAsHq($scoped)->post('/customers/merge', ['kept_id' => $bnc->id, 'absorbed_id' => $otherBionic->id])->assertNotFound();
    expect($otherBionic->fresh()->merged_into_id)->toBeNull();
});

it('writes nothing when it is read', function () {
    $customer = c360Customer($this->laladia);
    $brandManager = $this->hqUser('brand_manager');

    $tables = ['customers', 'customer_identities', 'customer_merges', 'customer_merge_candidates', 'facts_orders', 'conversations', 'conversation_messages',
        'memories', 'consent_events', 'consent_state', 'audit_logs', 'events', 'tool_calls', 'approvals', 'agent_runs', 'ai_runs', 'decision_records'];
    // Rows added show in the count and the highest id; a row changed in place shows in the full rows of the
    // tables a read could plausibly touch (a "last seen" bump, a memory closed, a projection rebuilt).
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count().':'.DB::table($t)->max('id')])->all()
        + ['customer_rows' => DB::table('customers')->orderBy('id')->get()->toJson(),
            'identity_rows' => DB::table('customer_identities')->orderBy('id')->get()->toJson(),
            'memory_rows' => DB::table('memories')->orderBy('id')->get()->toJson(),
            'consent_rows' => DB::table('consent_state')->orderBy('id')->get()->toJson(),
            'conversation_rows' => DB::table('conversations')->orderBy('id')->get()->toJson(),
            'order_rows' => DB::table('facts_orders')->orderBy('id')->get()->toJson()];

    $before = $snapshot();
    $this->actingAsHq($this->owner)->get("/customers/{$customer->id}")->assertOk();
    $this->actingAsHq($brandManager)->get("/customers/{$customer->id}")->assertOk();

    expect($snapshot())->toBe($before);
});
