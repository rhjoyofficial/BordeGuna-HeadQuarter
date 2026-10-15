<?php

use App\Conversation\ConversationState;
use App\Customer360\Customer360Reader;
use App\Identity\IdentityResolver;
use App\Identity\Quarantine\CrossBusinessDetector;
use App\Identity\Quarantine\Finding;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\CrossBusinessQuarantine;
use App\Models\CrossBusinessQuarantineReview;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\CustomerMerge;
use App\Models\FactsOrder;
use App\Models\Store;
use App\Models\UserBusinessScope;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 | docs/117 (ruling docs/110 §5 (m)): pre-existing cross-business merges are detected (read-only), flagged
 | (append-only, audited, idempotent) and suppressed from every Customer 360 read and the customer list by
 | the reader itself, before and regardless of any flag or review. Nothing is undone, moved or deleted.
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

function qMemory(Customer $customer, ?int $businessId, string $text, array $extra = []): int
{
    return DB::table('memories')->insertGetId($extra + [
        'uuid' => (string) Str::uuid(), 'kind' => 'episodic', 'business_id' => $businessId, 'customer_id' => $customer->id,
        'content' => $text, 'provenance' => 'business', 'occurred_at' => now()->subDays(6), 'valid_from' => now()->subDays(6),
        'created_at' => now()->subDays(6), 'updated_at' => now()->subDays(6),
    ]);
}

function qConsent(Customer $customer, int $businessId, string $reason): void
{
    DB::table('consent_events')->insert([
        'customer_id' => $customer->id, 'business_id' => $businessId, 'channel' => 'whatsapp', 'purpose' => 'marketing', 'state' => 'granted',
        'basis' => 'operator', 'occurred_at' => now()->subDays(8), 'recorded_at' => now()->subDays(8), 'actor_type' => 'system', 'reason' => $reason,
    ]);
}

/**
 * The world a pre-guard cross-business merge left behind. The guard in IdentityResolver::merge() now refuses
 * this, so the test writes exactly what the old merge wrote (identities, orders and memories re-pointed,
 * figures recounted across both, email copied, merged_into_id set, a customer_merges row), plus one Bionic
 * conversation and consent event hanging under the LaLaDia customer with no merge (an unscoped lookup).
 *
 * @return array{kept: Customer, absorbed: Customer, merge: CustomerMerge, laterMemory: int}
 */
function plantHistoricalCrossBusinessMerge(Business $laladia, Business $bionic): array
{
    $lldStore = Store::where('business_id', $laladia->id)->firstOrFail();
    $bncStore = Store::where('business_id', $bionic->id)->firstOrFail();

    $kept = Customer::create(['business_id' => $laladia->id, 'name' => 'Karim LaLaDia', 'primary_phone_canonical' => '01711222333',
        'email' => null, 'first_seen_at' => now()->subDays(30), 'last_seen_at' => now()->subDay()]);
    CustomerIdentity::create(['customer_id' => $kept->id, 'business_id' => $kept->business_id, 'channel' => 'phone', 'external_id' => '01711222333', 'confidence' => 1.0]);
    FactsOrder::create(['business_id' => $laladia->id, 'store_id' => $lldStore->id, 'order_ref' => 'LLD-OWN-1', 'status' => 'delivered', 'gross' => 700,
        'customer_id' => $kept->id, 'placed_at' => now()->subDays(9)]);
    qMemory($kept, $laladia->id, 'Kept own memory LLD');

    $absorbed = Customer::create(['business_id' => $bionic->id, 'name' => 'Secret Bionic Person', 'primary_phone_canonical' => '01999888777',
        'email' => 'secret-bionic@example.test', 'first_seen_at' => now()->subDays(20), 'last_seen_at' => now()->subDays(2)]);
    $bIdentities = [
        CustomerIdentity::create(['customer_id' => $absorbed->id, 'business_id' => $absorbed->business_id, 'channel' => 'phone', 'external_id' => '01999888777', 'confidence' => 1.0])->id,
        CustomerIdentity::create(['customer_id' => $absorbed->id, 'business_id' => $absorbed->business_id, 'channel' => 'messenger', 'external_id' => 'psid-secret-bionic', 'confidence' => 1.0])->id,
        CustomerIdentity::create(['customer_id' => $absorbed->id, 'business_id' => $absorbed->business_id, 'channel' => 'shop_order', 'external_id' => 'BNC-SECRET-9', 'source_store_id' => $bncStore->id, 'confidence' => 1.0])->id,
    ];
    FactsOrder::create(['business_id' => $bionic->id, 'store_id' => $bncStore->id, 'order_ref' => 'BNC-SECRET-9', 'status' => 'delivered', 'gross' => 5000,
        'customer_id' => $absorbed->id, 'placed_at' => now()->subDays(4)]);
    qMemory($absorbed, $bionic->id, 'Secret bionic scoped memory');
    qMemory($absorbed, null, 'Secret bionic unscoped memory');
    $bncBrand = DB::table('brands')->where('business_id', $bionic->id)->value('id');
    if ($bncBrand !== null) {
        qMemory($absorbed, null, 'Secret bionic brand memory', ['brand_id' => $bncBrand]);
    }
    // The absorbed row's own Bionic thread and consent stay under it (the old merge did not move them).
    Conversation::create(['uuid' => (string) Str::uuid(), 'business_id' => $bionic->id, 'customer_id' => $absorbed->id, 'channel' => 'messenger',
        'status' => 'open', 'state' => ConversationState::AiActive->value, 'opened_at' => now()->subDays(3), 'last_message_at' => now()->subDays(3)]);
    qConsent($absorbed, $bionic->id, 'secret bionic consent reason');

    // What IdentityResolver::merge() did before the docs/109 §6 guard, as the docs/122 migration leaves it: every
    // moved row hangs in the kept customer's business, and the Bionic store's order id, which cannot belong to a
    // LaLaDia customer, is quarantined (never guessed; the database refuses it live).
    DB::table('customer_identities')->where('id', $bIdentities[2])->update(['quarantined_at' => now(), 'quarantine_reason' => 'store_business_mismatch']);
    DB::transaction(function () use ($kept, $absorbed) {
        DB::table('customer_identities')->where('customer_id', $absorbed->id)->update(['customer_id' => $kept->id, 'business_id' => $kept->business_id]);
        DB::table('facts_orders')->where('customer_id', $absorbed->id)->update(['customer_id' => $kept->id]);
        DB::table('memories')->where('customer_id', $absorbed->id)->update(['customer_id' => $kept->id]);
        DB::table('customers')->where('id', $absorbed->id)->update(['merged_into_id' => $kept->id]);
        DB::table('customers')->where('id', $kept->id)->update([
            'orders_count' => DB::table('facts_orders')->where('customer_id', $kept->id)->count(),
            'lifetime_value' => DB::table('facts_orders')->where('customer_id', $kept->id)->where('status', '!=', 'cancelled')->sum('gross'),
            'email' => 'secret-bionic@example.test',
        ]);
    });
    $merge = CustomerMerge::create(['kept_id' => $kept->id, 'absorbed_id' => $absorbed->id, 'reason' => 'same person (pre-guard)', 'moved_identity_ids' => $bIdentities]);

    // No merge: a Bionic thread and consent event under the LaLaDia customer (unscoped identity lookup).
    Conversation::create(['uuid' => (string) Str::uuid(), 'business_id' => $bionic->id, 'customer_id' => $kept->id, 'channel' => 'whatsapp',
        'status' => 'open', 'state' => ConversationState::AiActive->value, 'opened_at' => now()->subDay(), 'last_message_at' => now()->subDay()]);
    qConsent($kept, $bionic->id, 'secret stray consent reason');

    // After the merge, a memory the LaLaDia side recorded itself without a business: it is not withheld.
    $later = qMemory($kept, null, 'Kept later unscoped memory', ['created_at' => now()->addMinute(), 'updated_at' => now()->addMinute()]);

    return ['kept' => $kept->fresh(), 'absorbed' => $absorbed->fresh(), 'merge' => $merge, 'laterMemory' => $later];
}

/** Every table a detection, a flag or a read could touch, by count, highest id and full rows. */
function qSnapshot(): array
{
    $tables = ['customers', 'customer_identities', 'customer_merges', 'customer_merge_candidates', 'facts_orders', 'conversations', 'memories',
        'consent_events', 'consent_state', 'audit_logs', 'events', 'cross_business_quarantines', 'cross_business_quarantine_reviews'];

    return collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->toJson()])->all();
}

it('detects a planted historical cross-business merge and everything it left across the boundary, read-only and idempotently', function () {
    ['kept' => $kept, 'absorbed' => $absorbed, 'merge' => $merge] = plantHistoricalCrossBusinessMerge($this->laladia, $this->bionic);

    $before = qSnapshot();
    $first = app(CrossBusinessDetector::class)->detect();
    $second = app(CrossBusinessDetector::class)->detect();
    expect(qSnapshot())->toBe($before)
        ->and(array_map(fn (Finding $f) => $f->toArray(), $second))->toBe(array_map(fn (Finding $f) => $f->toArray(), $first));

    $by = collect($first)->groupBy('reason')->map->count()->all();
    $hasBrand = DB::table('brands')->where('business_id', $this->bionic->id)->exists();

    expect($by['cross_business_merge'])->toBe(1)
        ->and($by['cross_business_merged_into'])->toBe(1)
        ->and($by['identity_moved_by_cross_business_merge'])->toBe(3)
        ->and($by['identity_store_other_business'])->toBe(1)
        ->and($by['order_other_business'])->toBe(1)
        ->and($by['conversation_other_business'])->toBe(1)
        ->and($by['memory_other_business'])->toBe($hasBrand ? 2 : 1)
        ->and($by['memory_unscoped_under_cross_business_merge'])->toBe(1)
        ->and($by['consent_other_business'])->toBe(1);

    $mergeFinding = collect($first)->firstWhere('reason', 'cross_business_merge');
    expect($mergeFinding->subjectId)->toBe($merge->id)
        ->and($mergeFinding->customerId)->toBe($kept->id)
        ->and($mergeFinding->customerBusinessId)->toBe($this->laladia->id)
        ->and($mergeFinding->rowBusinessId)->toBe($this->bionic->id);

    // Lineage: the moved order is attributed to the merge; the stray thread has none.
    expect(collect($first)->firstWhere('reason', 'order_other_business')->mergeId)->toBe($merge->id)
        ->and(collect($first)->firstWhere('reason', 'conversation_other_business')->mergeId)->toBe($merge->id);
    expect(collect($first)->firstWhere('reason', 'cross_business_merged_into')->subjectId)->toBe($absorbed->id);
});

it('finds nothing on production-like data that has no cross-business rows, and flags nothing', function () {
    $lldStore = Store::where('business_id', $this->laladia->id)->firstOrFail();
    $bncStore = Store::where('business_id', $this->bionic->id)->firstOrFail();
    $resolver = app(IdentityResolver::class);

    // Shop orders resolved the normal way in both businesses, and a same-business human merge.
    $a = $resolver->resolve($lldStore, ['name' => 'A', 'phone' => '01710000001', 'order_ref' => 'L-1']);
    $b = $resolver->resolve($bncStore, ['name' => 'B', 'phone' => '01710000002', 'order_ref' => 'B-1']);
    $c = $resolver->resolve($lldStore, ['name' => 'C', 'phone' => '01710000003', 'order_ref' => 'L-2']);
    $resolver->merge($a, $c, $this->owner->id, 'same person');

    expect(app(CrossBusinessDetector::class)->detect())->toBe([]);

    $before = qSnapshot();
    expect(Artisan::call('hq:identity:cross-business', ['--flag' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('No relationship crosses a business boundary');
    expect(qSnapshot())->toBe($before);

    $this->actingAsHq($this->owner)->get("/customers/{$a->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('quarantine.held', false)->where('quarantine.withheld', [])->has('observed.merges', 1));
    expect($b->id)->not->toBe($a->id);
});

it('lists findings in a dry run by default and writes nothing; --flag writes audited append-only flags once', function () {
    plantHistoricalCrossBusinessMerge($this->laladia, $this->bionic);
    $expected = count(app(CrossBusinessDetector::class)->detect());

    $before = qSnapshot();
    expect(Artisan::call('hq:identity:cross-business'))->toBe(0);
    expect(Artisan::output())->toContain('cross_business_merge')->toContain('Dry run: nothing written');
    expect(Artisan::call('hq:identity:cross-business', ['--dry-run' => true]))->toBe(0);
    expect(qSnapshot())->toBe($before);

    $audits = AuditLog::where('action', 'identity.quarantine.flagged')->count();
    expect(Artisan::call('hq:identity:cross-business', ['--flag' => true]))->toBe(0);
    expect(CrossBusinessQuarantine::count())->toBe($expected)
        ->and(AuditLog::where('action', 'identity.quarantine.flagged')->count())->toBe($audits + $expected)
        ->and(AuditLog::where('action', 'identity.quarantine.flagged')->first()->actor_type)->toBe('system');

    // Idempotent: a second run flags nothing new and audits nothing new.
    $afterFirst = qSnapshot();
    Artisan::call('hq:identity:cross-business', ['--flag' => true]);
    expect(Artisan::output())->toContain('0 flag(s) written');
    expect(qSnapshot())->toBe($afterFirst);

    // Append-only, at the model and in the database.
    $flag = CrossBusinessQuarantine::first();
    expect(fn () => $flag->forceFill(['reason' => 'x'])->save())->toThrow(LogicException::class)
        ->and(fn () => $flag->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::table('cross_business_quarantines')->where('id', $flag->id)->update(['reason' => 'x']))->toThrow(Exception::class);
    expect(fn () => DB::table('cross_business_quarantines')->where('id', $flag->id)->delete())->toThrow(Exception::class);
});

it('shows nothing of business B on the Customer 360 page of the business A survivor, enforced in the reader', function () {
    ['kept' => $kept, 'absorbed' => $absorbed, 'merge' => $merge, 'laterMemory' => $later] = plantHistoricalCrossBusinessMerge($this->laladia, $this->bionic);

    // Suppression does not wait for the flags: no command has run.
    expect(CrossBusinessQuarantine::count())->toBe(0);

    $props = $this->actingAsHq($this->owner)->get("/customers/{$kept->id}")->assertOk()->viewData('page')['props'];
    $json = json_encode($props);

    expect($props['customer']['business']['slug'])->toBe('laladia')
        ->and($json)->not->toContain('Secret Bionic Person')->not->toContain('secret-bionic@')->not->toContain('01999888777')
        ->not->toContain('psid-secret-bionic')->not->toContain('BNC-SECRET-9')->not->toContain('Secret bionic')->not->toContain('secret stray')
        ->not->toContain('"absorbed_id":'.$absorbed->id)->not->toContain('"other_id":'.$absorbed->id)
        // Its own data is still there.
        ->and($props['observed']['phone'])->toBe('01711222333')
        ->and($props['observed']['email'])->toBeNull()
        ->and(collect($props['observed']['identities'])->pluck('external_id')->all())->toBe(['01711222333'])
        ->and(collect($props['orders']['rows'])->pluck('ref')->all())->toBe(['LLD-OWN-1'])
        ->and($props['inferred']['orders_count'])->toBe(1)
        ->and($props['inferred']['lifetime_value'])->toBe('700.00')
        ->and($props['conversations']['total'])->toBe(0)
        ->and($props['consent']['ledger'])->toBe([])
        ->and(collect($props['memory']['rows'])->pluck('text')->all())->toContain('Kept own memory LLD', 'Kept later unscoped memory')
        ->and($props['observed']['merges'])->toBe([])
        // The held section names kinds and the merge id only.
        ->and($props['quarantine']['held'])->toBeTrue()
        ->and($props['quarantine']['withheld'])->toContain('email', 'aggregates', 'identities', 'memories')
        ->and($props['quarantine']['merges'])->toBe([['id' => $merge->id, 'side' => 'kept', 'at' => $merge->created_at->toIso8601String(), 'flagged' => false, 'review' => null]]);

    // The reader enforces it: called directly, without the controller or the page.
    $direct = json_encode(app(Customer360Reader::class)->read($kept, $this->owner));
    expect($direct)->not->toContain('Secret Bionic Person')->not->toContain('secret-bionic@')->not->toContain('BNC-SECRET-9')->not->toContain('psid-secret-bionic');
    expect($later)->toBeInt();
});

it('opens the absorbed business B row as itself and shows nothing of business A', function () {
    ['kept' => $kept, 'absorbed' => $absorbed] = plantHistoricalCrossBusinessMerge($this->laladia, $this->bionic);

    $bionicOnly = $this->hqUser('owner');
    UserBusinessScope::create(['user_id' => $bionicOnly->id, 'business_id' => $this->bionic->id, 'access' => 'full']);

    foreach ([$this->owner, $bionicOnly] as $viewer) {
        $props = $this->actingAsHq($viewer)->get("/customers/{$absorbed->id}")->assertOk()->viewData('page')['props'];
        $json = json_encode($props);
        expect($props['customer']['id'])->toBe($absorbed->id)
            ->and($props['customer']['business']['slug'])->toBe('bionic')
            ->and($json)->not->toContain('Karim LaLaDia')->not->toContain('01711222333')->not->toContain('LLD-OWN-1')->not->toContain('Kept own memory')
            ->and($props['quarantine']['link_held'])->toBeTrue()
            ->and($props['quarantine']['merges'][0]['side'])->toBe('absorbed');
    }

    // A person restricted to Bionic never reaches the LaLaDia survivor.
    $this->actingAsHq($bionicOnly)->get("/customers/{$kept->id}")->assertNotFound();
});

it('masks the customer list the same way and keeps the absorbed row listed in its own business', function () {
    ['kept' => $kept, 'absorbed' => $absorbed] = plantHistoricalCrossBusinessMerge($this->laladia, $this->bionic);

    $rows = collect($this->actingAsHq($this->owner)->get('/customers')->assertOk()->viewData('page')['props']['customers']['data'])->keyBy('id');

    expect($rows[$kept->id]['email'])->toBeNull()
        ->and($rows[$kept->id]['orders_count'])->toBe(1)
        ->and($rows[$kept->id]['lifetime_value'])->toBe('700.00')
        ->and($rows[$kept->id]['quarantine_held'])->toBeTrue()
        ->and($rows->has($absorbed->id))->toBeTrue()
        ->and($rows[$absorbed->id]['merged_into_id'])->toBeNull()
        ->and(json_encode($rows[$kept->id]))->not->toContain('secret-bionic@');
});

it('writes nothing when the pages are read, flags or not', function () {
    ['kept' => $kept, 'absorbed' => $absorbed] = plantHistoricalCrossBusinessMerge($this->laladia, $this->bionic);
    Artisan::call('hq:identity:cross-business', ['--flag' => true]);

    $before = qSnapshot();
    $this->actingAsHq($this->owner)->get("/customers/{$kept->id}")->assertOk();
    $this->actingAsHq($this->owner)->get("/customers/{$absorbed->id}")->assertOk();
    $this->actingAsHq($this->owner)->get('/customers')->assertOk();
    $this->actingAsHq($this->owner)->get('/customers/quarantine')->assertOk();
    expect(qSnapshot())->toBe($before);
});

it('lets a person with the permission record an audited review that changes nothing else', function () {
    ['kept' => $kept, 'merge' => $merge] = plantHistoricalCrossBusinessMerge($this->laladia, $this->bionic);
    Artisan::call('hq:identity:cross-business', ['--flag' => true]);
    $flag = CrossBusinessQuarantine::where('subject_table', 'customer_merges')->where('subject_id', $merge->id)->sole();

    $route = Route::getRoutes()->getByName('customers.quarantine.review');
    expect($route->methods())->toBe(['POST'])
        ->and($route->gatherMiddleware())->toContain('auth', '2fa.enabled', '2fa.verified', 'permission:hq.customers.quarantine.review');
    // "quarantine" is never read as a customer id.
    $this->actingAsHq($this->owner)->get('/customers/quarantine')->assertOk()
        ->assertInertia(fn ($page) => $page->component('customers/quarantine')->has('flags', CrossBusinessQuarantine::count()));

    foreach (['brand_manager', 'support_agent', 'finance_viewer'] as $role) {
        $this->actingAsHq($this->hqUser($role))->get('/customers/quarantine')->assertForbidden();
        $this->actingAsHq($this->hqUser($role))->post("/customers/quarantine/{$flag->id}/review", ['decision' => 'needs_repair', 'note' => 'nope'])->assertForbidden();
    }

    // A person who may see only LaLaDia may not see or review a flag that joins LaLaDia and Bionic.
    $scoped = $this->hqUser('owner');
    UserBusinessScope::create(['user_id' => $scoped->id, 'business_id' => $this->laladia->id, 'access' => 'full']);
    $this->actingAsHq($scoped)->get('/customers/quarantine')->assertOk()
        ->assertInertia(fn ($page) => $page->where('flags', fn ($flags) => collect($flags)->every(fn ($f) => $f['row_business'] === null)));
    $this->actingAsHq($scoped)->post("/customers/quarantine/{$flag->id}/review", ['decision' => 'needs_repair', 'note' => 'mine'])->assertNotFound();

    // Validation: a known decision and a note.
    $this->actingAsHq($this->owner)->post("/customers/quarantine/{$flag->id}/review", ['decision' => 'undo_it', 'note' => 'x'])->assertSessionHasErrors(['decision', 'note']);

    $mergeRow = DB::table('customer_merges')->where('id', $merge->id)->first();
    $customers = DB::table('customers')->orderBy('id')->get()->toJson();
    $audits = AuditLog::where('action', 'identity.quarantine.reviewed')->count();

    $this->actingAsHq($this->owner)->post("/customers/quarantine/{$flag->id}/review", ['decision' => 'needs_repair', 'note' => 'Different people, same household phone.'])
        ->assertSessionHasNoErrors();
    $this->actingAsHq($this->owner)->post("/customers/quarantine/{$flag->id}/review", ['decision' => 'confirmed_wrong', 'note' => 'Checked with the shop: two people.'])
        ->assertSessionHasNoErrors();

    $reviews = CrossBusinessQuarantineReview::where('quarantine_id', $flag->id)->orderBy('id')->get();
    expect($reviews)->toHaveCount(2)
        ->and($reviews[1]->reviewed_by)->toBe($this->owner->id)
        ->and($flag->latestReview()->first()->decision->value)->toBe('confirmed_wrong')
        ->and(AuditLog::where('action', 'identity.quarantine.reviewed')->count())->toBe($audits + 2)
        ->and(AuditLog::where('action', 'identity.quarantine.reviewed')->latest('id')->first()->actor_id)->toBe($this->owner->id)
        // Lineage: the original merge row and every customer row are untouched.
        ->and(DB::table('customer_merges')->where('id', $merge->id)->first())->toEqual($mergeRow)
        ->and(DB::table('customers')->orderBy('id')->get()->toJson())->toBe($customers);
    expect(fn () => $reviews[0]->forceFill(['note' => 'edited'])->save())->toThrow(LogicException::class);

    // A review lifts no suppression.
    $this->actingAsHq($this->owner)->get("/customers/{$kept->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('quarantine.merges.0.review', 'confirmed_wrong')->where('quarantine.merges.0.flagged', true)
            ->where('observed.email', null)->where('inferred.orders_count', 1));
});
