<?php

use App\Conversation\Channels\InboundMessage;
use App\Conversation\ConversationService;
use App\Conversation\ConversationState;
use App\Conversation\SupportContext;
use App\Facts\FactsOrderUpserter;
use App\Gateway\Descriptor\Channel;
use App\Identity\CrossBusinessIdentity;
use App\Identity\IdentityQuarantined;
use App\Identity\IdentityResolver;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\CustomerIdentityConflict;
use App\Models\CustomerMerge;
use App\Models\DomainEvent;
use App\Models\FactsOrder;
use App\Models\Store;
use App\Support\Events\Handlers\CustomerRegisteredHandler;
use Carbon\CarbonImmutable;
use Database\Seeders\DomainSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 | docs/122 (ruling docs/120 A2, A4, item 38): business-scoped customer identities. One phone in LaLaDia and
 | in Bionic is two customers; no lookup falls back to another business; an identity held by one customer is
 | never silently moved to another; a quarantined identity is never guessed; every reader filters its own
 | business independently of the resolver.
 */

beforeEach(function () {
    Cache::flush();
    Http::fake();
    config(['hq.alerts.telegram_bot_token' => '', 'hq.alerts.telegram_chat_id' => '']);
    $this->seed(DomainSeeder::class);
    $this->lld = Store::where('slug', 'laladia-com')->sole();
    $this->bnc = Store::where('slug', 'bionic-garden')->sole();
    $this->laladia = Business::where('slug', 'laladia')->sole();
    $this->bionic = Business::where('slug', 'bionic')->sole();
});

function scopeOrder(string $ref, string $phone = '01711000000', array $extra = []): array
{
    return $extra + ['order_number' => $ref, 'order_status' => 'pending', 'grand_total' => 500, 'customer_name' => 'Karim',
        'customer_phone' => $phone, 'placed_at' => '2026-10-01 10:00:00', 'shipping_address' => ['district' => 'Dhaka']];
}

function scopeMessage(string $pageRef, string $psid, string $text = 'hello'): InboundMessage
{
    return new InboundMessage(Channel::Messenger, $pageRef, "{$pageRef}:{$psid}", 'mid-'.Str::uuid(), $psid, $text,
        CarbonImmutable::now(), hash('sha256', $text));
}

function identityRows(int $businessId, string $channel, string $externalId): int
{
    return CustomerIdentity::query()->where('business_id', $businessId)->where('channel', $channel)->where('external_id', $externalId)->count();
}

// ---- 1. the same phone in two businesses ---------------------------------------------------------------

it('keeps the same phone in two businesses as two customers with two identity rows', function () {
    $upserter = app(FactsOrderUpserter::class);
    $l = $upserter->upsertFromShopPayload($this->lld, scopeOrder('LLD-1'));
    $b = $upserter->upsertFromShopPayload($this->bnc, scopeOrder('BNC-1'));

    expect($l->customer_id)->not->toBe($b->customer_id)
        ->and(Customer::find($l->customer_id)->business_id)->toBe($this->laladia->id)
        ->and(Customer::find($b->customer_id)->business_id)->toBe($this->bionic->id)
        ->and(identityRows($this->laladia->id, 'phone', '01711000000'))->toBe(1)
        ->and(identityRows($this->bionic->id, 'phone', '01711000000'))->toBe(1)
        ->and(CustomerIdentity::where('channel', 'phone')->where('external_id', '01711000000')->count())->toBe(2);

    // The same for a channel id: one Messenger PSID writing to both pages is two strangers.
    $service = app(ConversationService::class);
    $mL = $service->ingest(scopeMessage('100085015853889', 'psid-both'));
    $mB = $service->ingest(scopeMessage('321936947659712', 'psid-both'));
    expect($mL->customer_id)->not->toBe($mB->customer_id)
        ->and(Customer::find($mB->customer_id)->business_id)->toBe($this->bionic->id)
        ->and(Conversation::find($mB->conversation_id)->business_id)->toBe($this->bionic->id);
});

// ---- 2. no cross-business reassignment -----------------------------------------------------------------

it('never re-points an identity across businesses, whatever order the events arrive in', function () {
    $upserter = app(FactsOrderUpserter::class);
    $l1 = $upserter->upsertFromShopPayload($this->lld, scopeOrder('LLD-1', extra: ['user_id' => 77]));
    // A Bionic order for the same phone carrying a Bionic shop user: the old steal path (docs/119 §2.3).
    $b1 = $upserter->upsertFromShopPayload($this->bnc, scopeOrder('BNC-1', extra: ['user_id' => 77]));
    $l2 = $upserter->upsertFromShopPayload($this->lld, scopeOrder('LLD-2'));
    $b2 = $upserter->upsertFromShopPayload($this->bnc, scopeOrder('BNC-2'));

    $lldPhone = CustomerIdentity::query()->key($this->laladia->id, 'phone', '01711000000')->sole();
    $bncPhone = CustomerIdentity::query()->key($this->bionic->id, 'phone', '01711000000')->sole();
    expect($l2->customer_id)->toBe($l1->customer_id)
        ->and($b2->customer_id)->toBe($b1->customer_id)
        ->and($lldPhone->customer_id)->toBe($l1->customer_id)
        ->and($bncPhone->customer_id)->toBe($b1->customer_id)
        // Each store's shop user id lives in its own store's business.
        ->and(CustomerIdentity::query()->key($this->bionic->id, 'shop_user', '77', $this->bnc->id)->value('customer_id'))->toBe($b1->customer_id)
        ->and(CustomerIdentity::query()->key($this->laladia->id, 'shop_user', '77', $this->lld->id)->value('customer_id'))->toBe($l1->customer_id)
        ->and(CustomerIdentityConflict::count())->toBe(0);

    // Every identity row hangs under a customer of its own business (the database holds it; this reads it).
    $crossing = DB::table('customer_identities as i')->join('customers as c', 'c.id', '=', 'i.customer_id')
        ->whereColumn('c.business_id', '!=', 'i.business_id')->count();
    expect($crossing)->toBe(0);
});

it('refuses to move an identity between two customers of one business and records an auditable requirement', function () {
    $resolver = app(IdentityResolver::class);
    $x = $resolver->resolve($this->lld, ['phone' => '01711000000', 'name' => 'X']);
    // A different shop user who already exists as Y without a phone.
    $y = $resolver->resolve($this->lld, ['shop_user_id' => 900, 'name' => 'Y']);
    $phoneBefore = CustomerIdentity::query()->key($this->laladia->id, 'phone', '01711000000')->sole();

    // Y now places an order with X's phone: the old code moved the phone row to Y. Now it stays with X.
    $again = $resolver->resolve($this->lld, ['shop_user_id' => 900, 'phone' => '01711000000', 'order_ref' => 'LLD-Y1']);
    $resolver->resolve($this->lld, ['shop_user_id' => 900, 'phone' => '01711000000', 'order_ref' => 'LLD-Y2']);

    $phoneAfter = $phoneBefore->fresh();
    $conflict = CustomerIdentityConflict::sole();
    expect($again->id)->toBe($y->id)
        ->and($phoneAfter->customer_id)->toBe($x->id)
        ->and($conflict->kind)->toBe('link_refused')
        ->and($conflict->reason)->toBe('held_by_another_customer')
        ->and($conflict->identity_id)->toBe($phoneBefore->id)
        ->and($conflict->holder_customer_id)->toBe($x->id)
        ->and($conflict->claimant_customer_id)->toBe($y->id)
        ->and($conflict->occurrences)->toBe(2)
        ->and($conflict->resolved_at)->toBeNull()
        ->and(AuditLog::where('action', 'identity.link_refused')->count())->toBe(1);
});

// ---- 3. store / business mismatch ----------------------------------------------------------------------

it('refuses a store of another business at the database, the resolver, the event handler and the thread', function () {
    $customer = app(IdentityResolver::class)->resolve($this->lld, ['phone' => '01711000000']);

    // The database: a live row whose store is another business's.
    expect(fn () => DB::transaction(fn () => DB::table('customer_identities')->insert([
        'customer_id' => $customer->id, 'business_id' => $this->laladia->id, 'channel' => 'shop_user', 'external_id' => '1',
        'source_store_id' => $this->bnc->id, 'confidence' => 1, 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class, 'customer_identities_store_business_foreign');
    // The database: an identity whose business is not its customer's.
    expect(fn () => DB::transaction(fn () => DB::table('customer_identities')->insert([
        'customer_id' => $customer->id, 'business_id' => $this->bionic->id, 'channel' => 'email', 'external_id' => 'a@b.test',
        'confidence' => 1, 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class, 'customer_identities_customer_business_foreign');

    // The event handler: a customer.registered recorded for Bionic that names the LaLaDia store.
    $event = DomainEvent::create(['event_id' => (string) Str::uuid(), 'type' => 'customer.registered', 'direction' => 'inbound',
        'source' => 'shop:bionic-garden', 'business_id' => $this->bionic->id, 'dedupe_key' => 'x-'.Str::uuid(), 'occurred_at' => now(),
        'payload' => ['_store' => 'laladia-com', 'user' => ['id' => 5, 'phone' => '01711000000']]]);
    expect(fn () => app(CustomerRegisteredHandler::class)->handle($event))->toThrow(CrossBusinessIdentity::class);

    // The thread: a participant of a Bionic thread may not be a LaLaDia customer or identity.
    $service = app(ConversationService::class);
    $thread = $service->openOrFind($this->bionic->id, Channel::Messenger, 'bnc-thread');
    $lldIdentity = CustomerIdentity::query()->key($this->laladia->id, 'phone', '01711000000')->sole();
    expect(fn () => $service->addParticipant($thread, 'customer', ['customer_id' => $customer->id]))->toThrow(CrossBusinessIdentity::class)
        ->and(fn () => $service->addParticipant($thread, 'customer', ['identity_id' => $lldIdentity->id]))->toThrow(CrossBusinessIdentity::class)
        ->and(fn () => $service->openOrFind($this->bionic->id, Channel::Messenger, 'bnc-thread-2', $customer->id))->toThrow(CrossBusinessIdentity::class);
});

// ---- 5. ambiguous identity matches ---------------------------------------------------------------------

it('refuses to guess between two customers holding one quarantined value, and never links past the quarantine', function () {
    $a = Customer::create(['business_id' => $this->laladia->id, 'name' => 'A']);
    $b = Customer::create(['business_id' => $this->laladia->id, 'name' => 'B']);
    foreach ([$a, $b] as $c) {
        $row = CustomerIdentity::create(['customer_id' => $c->id, 'business_id' => $this->laladia->id, 'channel' => 'phone', 'external_id' => '01722000000']);
        $row->forceFill(['quarantined_at' => now(), 'quarantine_reason' => 'duplicate_ambiguous_owner'])->save();
    }
    $customers = Customer::count();

    // Only the quarantined phone could say who this is: refused, nothing created.
    expect(fn () => app(IdentityResolver::class)->resolve($this->lld, ['phone' => '01722000000', 'name' => 'Who']))->toThrow(IdentityQuarantined::class);
    expect(Customer::count())->toBe($customers)
        ->and(fn () => app(FactsOrderUpserter::class)->upsertFromShopPayload($this->lld, scopeOrder('LLD-Q', '01722000000')))->toThrow(IdentityQuarantined::class);
    expect(FactsOrder::where('order_ref', 'LLD-Q')->exists())->toBeFalse();

    // A stronger key names the customer: the order resolves, the quarantined phone is NOT linked, and the
    // refusal is recorded against the quarantined row.
    $c = app(IdentityResolver::class)->resolve($this->lld, ['shop_user_id' => 41, 'name' => 'C']);
    $again = app(IdentityResolver::class)->resolve($this->lld, ['shop_user_id' => 41, 'phone' => '01722000000']);
    expect($again->id)->toBe($c->id)
        ->and(CustomerIdentity::query()->key($this->laladia->id, 'phone', '01722000000')->exists())->toBeFalse()
        ->and(CustomerIdentityConflict::where('reason', 'key_quarantined')->where('claimant_customer_id', $c->id)->count())->toBe(1);

    // The same in Bionic is untouched by LaLaDia's quarantine.
    expect(app(IdentityResolver::class)->resolve($this->bnc, ['phone' => '01722000000'])->business_id)->toBe($this->bionic->id);
});

// ---- 6. idempotent resolution --------------------------------------------------------------------------

it('resolves the same order and the same message twice to the same customer with no new rows', function () {
    $upserter = app(FactsOrderUpserter::class);
    $first = $upserter->upsertFromShopPayload($this->bnc, scopeOrder('BNC-9', extra: ['customer_email' => 'K@Example.com', 'user_id' => 5]));
    $counts = [Customer::count(), CustomerIdentity::count(), CustomerIdentityConflict::count(), AuditLog::where('action', 'customer.created')->count()];
    $second = $upserter->upsertFromShopPayload($this->bnc, scopeOrder('BNC-9', extra: ['customer_email' => 'K@Example.com', 'user_id' => 5]));
    expect($second->customer_id)->toBe($first->customer_id)
        ->and([Customer::count(), CustomerIdentity::count(), CustomerIdentityConflict::count(), AuditLog::where('action', 'customer.created')->count()])->toBe($counts);

    $resolver = app(IdentityResolver::class);
    $one = $resolver->resolveChannelIdentity(Channel::Messenger, 'psid-idem', $this->bionic);
    $two = $resolver->resolveChannelIdentity(Channel::Messenger, 'psid-idem', $this->bionic);
    expect($two->id)->toBe($one->id)->and(identityRows($this->bionic->id, 'messenger_psid', 'psid-idem'))->toBe(1);
});

// ---- 7. no unscoped fallback ---------------------------------------------------------------------------

it('never falls back to another business: a value known only elsewhere is a stranger here', function () {
    $resolver = app(IdentityResolver::class);
    $lld = $resolver->resolve($this->lld, ['phone' => '01733000000', 'email' => 'only@lld.test', 'name' => 'L']);
    $lldPsid = $resolver->resolveChannelIdentity(Channel::Whatsapp, '8801733000000', $this->laladia);

    $bncByPhone = $resolver->resolve($this->bnc, ['phone' => '01733000000']);
    $bncByEmail = $resolver->resolve($this->bnc, ['email' => 'ONLY@lld.test']);
    $bncWa = $resolver->resolveChannelIdentity(Channel::Whatsapp, '8801733000000', $this->bionic);

    expect($bncByPhone->id)->not->toBe($lld->id)->and($bncByPhone->business_id)->toBe($this->bionic->id)
        ->and($bncByEmail->id)->not->toBe($lld->id)->and($bncByEmail->business_id)->toBe($this->bionic->id)
        ->and($bncWa->id)->not->toBe($lldPsid->id)->and($bncWa->business_id)->toBe($this->bionic->id)
        // and the LaLaDia rows still name LaLaDia's customers
        ->and(CustomerIdentity::query()->key($this->laladia->id, 'phone', '01733000000')->value('customer_id'))->toBe($lld->id)
        ->and(CustomerIdentity::query()->key($this->laladia->id, 'whatsapp', '8801733000000')->value('customer_id'))->toBe($lldPsid->id);

    // ThreadIdentity and linkByIdentity read only the thread's business.
    $service = app(ConversationService::class);
    $thread = $service->openOrFind($this->bionic->id, Channel::Whatsapp, 'wa-bnc');
    expect($service->linkByIdentity($thread, 'phone', '01733000000'))->toBe($bncByPhone->id);
    $empty = $service->openOrFind($this->bionic->id, Channel::Whatsapp, 'wa-bnc-2');
    expect($service->linkByIdentity($empty, 'email', 'nobody@lld.test'))->toBeNull();
});

it('does not follow a merge pointer into another business', function () {
    $l = Customer::create(['business_id' => $this->laladia->id, 'name' => 'L']);
    $b = Customer::create(['business_id' => $this->bionic->id, 'name' => 'B']);
    DB::table('customers')->where('id', $b->id)->update(['merged_into_id' => $l->id]); // pre-guard data only
    CustomerIdentity::create(['customer_id' => $b->id, 'business_id' => $this->bionic->id, 'channel' => 'phone', 'external_id' => '01744000000']);

    $found = app(IdentityResolver::class)->resolve($this->bnc, ['phone' => '01744000000']);
    expect($found->id)->toBe($b->id)->and($b->fresh()->canonical()->id)->toBe($b->id);
});

// ---- A4: every reader filters its own business ---------------------------------------------------------

/** A link written before the fix: a Bionic thread and a Bionic order under a LaLaDia customer. */
function plantPreFixLink(object $t): array
{
    $l = app(FactsOrderUpserter::class)->upsertFromShopPayload($t->lld, scopeOrder('LLD-OWN', '01755000000'));
    $customer = Customer::find($l->customer_id);
    $bncOrder = FactsOrder::create(['business_id' => $t->bionic->id, 'store_id' => $t->bnc->id, 'order_ref' => 'BNC-SECRET', 'status' => 'pending',
        'gross' => 9999, 'customer_id' => $customer->id, 'customer_phone_canonical' => '01755000000', 'placed_at' => now()]);
    $thread = Conversation::create(['uuid' => (string) Str::uuid(), 'business_id' => $t->bionic->id, 'customer_id' => $customer->id,
        'channel' => 'messenger', 'status' => 'open', 'state' => ConversationState::HumanRequired->value, 'opened_at' => now(), 'last_message_at' => now()]);
    $ownThread = Conversation::create(['uuid' => (string) Str::uuid(), 'business_id' => $t->laladia->id, 'customer_id' => $customer->id,
        'channel' => 'messenger', 'status' => 'open', 'state' => ConversationState::HumanRequired->value, 'opened_at' => now(), 'last_message_at' => now()]);

    return [$customer, $bncOrder, $thread, $ownThread];
}

it('shows no other business\'s orders or customer in the inbox and the support context', function () {
    [$customer, , $thread, $ownThread] = plantPreFixLink($this);
    $owner = $this->hqUser('owner');

    $this->actingAsHq($owner)->get('/inbox/'.$thread->uuid)->assertOk()
        ->assertInertia(fn ($page) => $page->where('orders', [])->where('thread.customer', null)->where('thread.customer_id', null)->where('memory', []));
    $this->actingAsHq($owner)->get('/inbox/'.$ownThread->uuid)->assertOk()
        ->assertInertia(fn ($page) => $page->has('orders', 1)->where('orders.0.ref', 'LLD-OWN'));

    $foreign = collect(app(SupportContext::class)->blocksFor($thread))->map(fn ($b) => $b->content)->implode("\n");
    $own = collect(app(SupportContext::class)->blocksFor($ownThread))->map(fn ($b) => $b->content)->implode("\n");
    expect($foreign)->not->toContain('BNC-SECRET')->not->toContain('LLD-OWN')->not->toContain('customer:'.$customer->id)
        ->and($own)->toContain('LLD-OWN')->not->toContain('BNC-SECRET')->toContain('orders on record: 1');
});

it('leaves a pre-fix copy under another business\'s customer for the repair: upserter, rebuild and recount', function () {
    [$customer, $bncOrder] = plantPreFixLink($this);

    // The next Bionic event for that order updates it but does not move it (repair is docs/120 A3, not a side effect).
    $after = app(FactsOrderUpserter::class)->upsertFromShopPayload($this->bnc, scopeOrder('BNC-SECRET', '01755000000', ['order_status' => 'confirmed']));
    expect($after->customer_id)->toBe($customer->id)->and($after->status)->toBe('confirmed');

    $this->artisan('hq:customers:rebuild')->expectsOutputToContain('1 order fact(s) under another business\'s customer left for the repair')->assertSuccessful();
    expect($bncOrder->fresh()->customer_id)->toBe($customer->id)
        ->and($customer->fresh()->orders_count)->toBe(1)
        ->and((string) $customer->fresh()->lifetime_value)->toBe('500.00');
});

it('hides quarantined identities from Customer 360 and the consent screen', function () {
    $customer = app(IdentityResolver::class)->resolve($this->lld, ['phone' => '01766000000', 'name' => 'Q']);
    $held = CustomerIdentity::create(['customer_id' => $customer->id, 'business_id' => $this->laladia->id, 'channel' => 'messenger_psid', 'external_id' => 'psid-held']);
    $held->forceFill(['quarantined_at' => now(), 'quarantine_reason' => 'contradicted_by_evidence'])->save();
    $owner = $this->hqUser('owner');

    $this->actingAsHq($owner)->get('/customers/'.$customer->id)->assertOk()
        ->assertInertia(fn ($page) => $page->has('observed.identities', 1)->where('observed.identities.0.channel', 'phone')->has('inferred.identity_links', 1));
    $this->actingAsHq($owner)->get('/customers/'.$customer->id.'/consent')->assertOk()
        ->assertInertia(fn ($page) => $page->has('identities', 1));
});

// ---- item 38: no search by a withheld value ------------------------------------------------------------

it('never matches a search on a withheld phone or e-mail, and still finds by own values', function () {
    [$customer] = plantPreFixLink($this);
    // The pre-fix resolver filled LaLaDia's empty phone from a Bionic order: only Bionic's copy carries it.
    $filled = Customer::create(['business_id' => $this->laladia->id, 'name' => 'Filled', 'primary_phone_canonical' => '01788000000']);
    FactsOrder::create(['business_id' => $this->bionic->id, 'store_id' => $this->bnc->id, 'order_ref' => 'BNC-FILL', 'status' => 'pending',
        'gross' => 1, 'customer_id' => $filled->id, 'customer_phone_canonical' => '01788000000', 'placed_at' => now()]);
    // A cross-business merge copied a Bionic e-mail onto a LaLaDia row (docs/117).
    $kept = Customer::create(['business_id' => $this->laladia->id, 'name' => 'Kept', 'email' => 'secret@bnc.test']);
    $absorbed = Customer::create(['business_id' => $this->bionic->id, 'name' => 'Absorbed', 'email' => 'secret@bnc.test']);
    CustomerMerge::create(['kept_id' => $kept->id, 'absorbed_id' => $absorbed->id, 'reason' => 'pre-guard', 'moved_identity_ids' => []]);
    $owner = $this->hqUser('owner');

    $ids = fn (string $q, array $extra = []) => collect($this->actingAsHq($owner)->get('/customers?'.http_build_query(['q' => $q] + $extra))->assertOk()
        ->viewData('page')['props']['customers']['data'])->pluck('id')->all();

    expect($ids('01788000000'))->toBe([])
        ->and($ids('secret@bnc.test'))->toBe([$absorbed->id])
        // Own values still match, and an explicit business narrows the search.
        ->and($ids('01755000000'))->toBe([$customer->id])
        ->and($ids('01755000000', ['business' => $this->bionic->id]))->toBe([])
        ->and($ids('Filled'))->toBe([$filled->id]);
});

// ---- structural pin: every identity lookup names a business --------------------------------------------

it('finds no lookup of customer_identities by channel or external id that does not name a business', function () {
    $offenders = [];
    $files = new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())), '/\.php$/');
    foreach ($files as $file) {
        $code = (string) file_get_contents($file->getPathname());
        if (! preg_match('/CustomerIdentity|customer_identities|identities\(\)/', $code)) {
            continue;
        }
        // One PHP statement at a time (up to the next semicolon).
        foreach (preg_split('/;\s*\n/', $code) as $statement) {
            $touchesIdentities = preg_match('/CustomerIdentity::|customer_identities|->identities\(\)/', $statement);
            $byValue = preg_match("/where\\(\\s*'(?:[a-z_]+\\.)?(external_id|channel)'/", $statement)
                || preg_match("/where\\(\\s*\\[\\s*'(external_id|channel)'/", $statement)
                || preg_match('/updateOrCreate|firstOrCreate|firstOrNew/', $statement) && $touchesIdentities;
            if ($touchesIdentities && $byValue && ! preg_match('/business_id|->key\\(/', $statement)) {
                $offenders[] = str_replace(app_path(), 'app', $file->getPathname()).': '.trim(Str::limit(preg_replace('/\s+/', ' ', $statement), 160));
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('pins the scope key itself: business, channel, external id, and a null store matched as null', function () {
    $sql = CustomerIdentity::query()->key(7, 'phone', '01700000000')->toRawSql();
    expect($sql)->toContain('"business_id" = 7')->toContain('"quarantined_at" is null')->toContain('"source_store_id" is null');
    $withStore = CustomerIdentity::query()->key(7, 'shop_user', '5', 3)->toRawSql();
    expect($withStore)->toContain('"source_store_id" = 3');
});
