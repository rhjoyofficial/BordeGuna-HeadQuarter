<?php

use App\Consent\ConsentBasis;
use App\Consent\ConsentLedger;
use App\Consent\ConsentPurpose;
use App\Consent\ConsentRefusal;
use App\Consent\ConsentState;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Business;
use App\Models\ConsentEvent;
use App\Models\ConsentProjection;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use Database\Seeders\DomainSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 | Phase 2.5 B: consent is an append-only ledger, and the question it exists to answer is historical.
 |
 | The mutable `customers.consent` blob could say whether we may contact somebody NOW. It could never
 | say whether consent was valid AT THE MOMENT a particular message was sent, because the value that
 | was true then has since been overwritten. These tests hold the difference in place.
 */

function consentSubject(): array
{
    $business = Business::where('slug', 'bor-de-guna')->sole();
    $customer = Customer::create([
        'business_id' => $business->id,
        'primary_phone_canonical' => '01711000000',
        'name' => 'Consent Test Customer',
    ]);

    return [$business->id, $customer->id];
}

beforeEach(function () {
    $this->seed(DomainSeeder::class);
});

it('permits nothing until something is recorded, and says so in those words', function () {
    [$businessId, $customerId] = consentSubject();

    $decision = app(ConsentLedger::class)->permits($customerId, $businessId, 'whatsapp', ConsentPurpose::Marketing);

    expect($decision->permitted)->toBeFalse()
        ->and($decision->reason)->toContain('no consent event has ever been recorded')
        ->and($decision->eventId)->toBeNull();

    // "Never asked" is not "said no", and the reason distinguishes them. Silence is still refusal.
    expect(ConsentPurpose::Service->permittedWithoutRecord())->toBeFalse()
        ->and(ConsentPurpose::Marketing->permittedWithoutRecord())->toBeFalse();
});

it('permits once a grant is recorded, naming the event that decided it', function () {
    [$businessId, $customerId] = consentSubject();
    $ledger = app(ConsentLedger::class);

    $event = $ledger->record($customerId, $businessId, ConsentPurpose::Marketing, ConsentState::Granted, ConsentBasis::Checkout, 'whatsapp');

    $decision = $ledger->permits($customerId, $businessId, 'whatsapp', ConsentPurpose::Marketing);

    expect($decision->permitted)->toBeTrue()
        ->and($decision->eventId)->toBe($event->id)
        ->and($decision->reason)->toContain("#{$event->id}");

    // A different channel is a different grant. Agreeing on WhatsApp is not agreeing on SMS.
    expect($ledger->permits($customerId, $businessId, 'sms', ConsentPurpose::Marketing)->permitted)->toBeFalse();
    // A different purpose likewise: an order enquiry is not permission to advertise.
    expect($ledger->permits($customerId, $businessId, 'whatsapp', ConsentPurpose::Service)->permitted)->toBeFalse();
});

it('answers the question compliance actually asks: was it permitted at the time', function () {
    [$businessId, $customerId] = consentSubject();
    $ledger = app(ConsentLedger::class);

    $granted = Carbon::parse('2026-06-01T10:00:00Z');
    $withdrawn = Carbon::parse('2026-08-01T10:00:00Z');
    $sentAt = Carbon::parse('2026-07-01T10:00:00Z');

    $ledger->record($customerId, $businessId, ConsentPurpose::Marketing, ConsentState::Granted, ConsentBasis::Checkout, 'sms', $granted);
    $ledger->record($customerId, $businessId, ConsentPurpose::Marketing, ConsentState::Withdrawn, ConsentBasis::Inbound, 'sms', $withdrawn);

    // Today: refused, and rightly.
    expect($ledger->permits($customerId, $businessId, 'sms', ConsentPurpose::Marketing)->permitted)->toBeFalse();

    // On the day the message was actually sent: permitted, and the event that permitted it is named.
    $then = $ledger->permittedAt($customerId, $businessId, 'sms', ConsentPurpose::Marketing, $sentAt);
    expect($then->permitted)->toBeTrue()
        ->and($then->eventId)->not->toBeNull();

    // This is the whole point: a mutable column would now say "no" for both questions, and the
    // complaint about July's message would be answered with August's state.
    $before = $ledger->permittedAt($customerId, $businessId, 'sms', ConsentPurpose::Marketing, Carbon::parse('2026-05-01T10:00:00Z'));
    expect($before->permitted)->toBeFalse();
});

it('lets a person-level do-not-contact outrank a later channel grant', function () {
    [$businessId, $customerId] = consentSubject();
    $ledger = app(ConsentLedger::class);

    $ledger->record($customerId, $businessId, null, ConsentState::DoNotContact, ConsentBasis::Inbound, null, Carbon::parse('2026-06-01T10:00:00Z'));
    // Recorded AFTER the suppression, and narrower. Deny still wins.
    $ledger->record($customerId, $businessId, ConsentPurpose::Marketing, ConsentState::Granted, ConsentBasis::Operator, 'sms', Carbon::parse('2026-07-01T10:00:00Z'));

    $decision = $ledger->permits($customerId, $businessId, 'sms', ConsentPurpose::Marketing);

    expect($decision->permitted)->toBeFalse()
        ->and($decision->reason)->toContain('do-not-contact')
        ->and($decision->state)->toBe(ConsentState::DoNotContact);

    // And it covers every channel and purpose, which is what makes it person-level.
    expect($ledger->permits($customerId, $businessId, 'whatsapp', ConsentPurpose::Service)->permitted)->toBeFalse();
});

it('treats an expired grant as expired without anybody having to run a job', function () {
    [$businessId, $customerId] = consentSubject();
    $ledger = app(ConsentLedger::class);

    $ledger->record(
        $customerId, $businessId, ConsentPurpose::Marketing, ConsentState::Granted, ConsentBasis::Explicit, 'email',
        Carbon::parse('2026-01-01T00:00:00Z'),
        ['expires_at' => Carbon::parse('2026-02-01T00:00:00Z')],
    );

    expect($ledger->permits($customerId, $businessId, 'email', ConsentPurpose::Marketing)->permitted)->toBeFalse();
    // At a moment inside the window it was live.
    expect($ledger->permittedAt($customerId, $businessId, 'email', ConsentPurpose::Marketing, Carbon::parse('2026-01-15T00:00:00Z'))->permitted)->toBeTrue();
});

it('refuses to let history be edited or deleted, in the model and in the database', function () {
    [$businessId, $customerId] = consentSubject();
    $event = app(ConsentLedger::class)->record($customerId, $businessId, ConsentPurpose::Service, ConsentState::Granted, ConsentBasis::Inbound, 'messenger');

    expect(fn () => $event->update(['state' => 'withdrawn']))->toThrow(LogicException::class, 'append-only');
    expect(fn () => $event->delete())->toThrow(LogicException::class, 'append-only');

    // The model guard is a courtesy. The database is the guarantee, so bypass Eloquent entirely.
    expect(fn () => DB::transaction(fn () => DB::table('consent_events')->where('id', $event->id)->update(['state' => 'withdrawn'])))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('consent_events')->where('id', $event->id)->delete()))
        ->toThrow(QueryException::class);

    expect(ConsentEvent::find($event->id)->state)->toBe(ConsentState::Granted);
});

it('keeps the projection derived, and rebuilds it from the ledger', function () {
    [$businessId, $customerId] = consentSubject();
    $ledger = app(ConsentLedger::class);

    $ledger->record($customerId, $businessId, ConsentPurpose::Marketing, ConsentState::Granted, ConsentBasis::Checkout, 'sms');
    $ledger->record($customerId, $businessId, ConsentPurpose::Service, ConsentState::Granted, ConsentBasis::Inbound, 'messenger');

    expect(ConsentProjection::count())->toBe(2);

    // Corrupt the projection the way a bug would, then prove the ledger wins.
    ConsentProjection::query()->update(['state' => ConsentState::Withdrawn->value]);
    expect(ConsentProjection::where('state', 'withdrawn')->count())->toBe(2);

    $written = $ledger->rebuild();

    expect($written)->toBe(2)
        ->and(ConsentProjection::where('state', 'granted')->count())->toBe(2)
        ->and(ConsentProjection::count())->toBe(2);
});

it('audits that consent was recorded, because adding consent is itself a privileged act', function () {
    [$businessId, $customerId] = consentSubject();
    app(ConsentLedger::class)->record($customerId, $businessId, ConsentPurpose::Marketing, ConsentState::Granted, ConsentBasis::Operator, 'sms');

    $audit = AuditLog::where('action', 'consent.recorded')->sole();
    expect($audit->after['purpose'])->toBe('marketing')
        ->and($audit->after['state'])->toBe('granted')
        ->and($audit->after['basis'])->toBe('operator');
});

it('keeps each brand its own row in the projection, and the rebuild agrees (docs/108 §6)', function () {
    [$businessId, $customerId] = consentSubject();
    $ledger = app(ConsentLedger::class);
    $first = Brand::create(['business_id' => $businessId, 'code' => 'PJA', 'slug' => 'pja', 'name' => 'Brand A', 'is_active' => true]);
    $second = Brand::create(['business_id' => $businessId, 'code' => 'PJB', 'slug' => 'pjb', 'name' => 'Brand B', 'is_active' => true]);

    $ledger->record($customerId, $businessId, ConsentPurpose::Marketing, ConsentState::Granted, ConsentBasis::Checkout, 'sms', attributes: ['brand_id' => $first->id]);
    $ledger->record($customerId, $businessId, ConsentPurpose::Marketing, ConsentState::Withdrawn, ConsentBasis::Operator, 'sms', attributes: ['brand_id' => $second->id]);

    $rows = fn () => ConsentProjection::where('customer_id', $customerId)->orderBy('brand_id')->get(['brand_id', 'state'])
        ->map(fn ($r) => [$r->brand_id, $r->state->value])->all();
    expect($rows())->toBe([[$first->id, 'granted'], [$second->id, 'withdrawn']]);

    $ledger->rebuild($customerId);
    expect($rows())->toBe([[$first->id, 'granted'], [$second->id, 'withdrawn']])
        ->and($ledger->permits($customerId, $businessId, 'sms', ConsentPurpose::Marketing, $first->id)->permitted)->toBeTrue()
        ->and($ledger->permits($customerId, $businessId, 'sms', ConsentPurpose::Marketing, $second->id)->permitted)->toBeFalse();
});

/** Two identities of one person (docs/114 §2): two WhatsApp numbers. */
function consentIdentities(int $customerId): array
{
    $a = CustomerIdentity::create(['customer_id' => $customerId, 'channel' => 'whatsapp', 'external_id' => '8801711000001']);
    $b = CustomerIdentity::create(['customer_id' => $customerId, 'channel' => 'whatsapp', 'external_id' => '8801711000002']);

    return [$a->id, $b->id];
}

it('scopes a restriction to one identity, which no grant on another identity defeats, while a customer-wide restriction covers every identity (docs/114 §2)', function () {
    [$businessId, $customerId] = consentSubject();
    [$a, $b] = consentIdentities($customerId);
    $ledger = app(ConsentLedger::class);

    // A customer-wide Service grant on WhatsApp (every identity), then a do-not-contact on identity A only,
    // then a grant on identity B recorded AFTER it, as explicit as a grant gets. B's grant says nothing about A.
    $ledger->record($customerId, $businessId, ConsentPurpose::Service, ConsentState::Granted, ConsentBasis::Explicit, 'whatsapp');
    $ledger->record($customerId, $businessId, null, ConsentState::DoNotContact, ConsentBasis::Operator, null, attributes: ['identity_id' => $a]);
    $ledger->record($customerId, $businessId, ConsentPurpose::Service, ConsentState::Granted, ConsentBasis::Explicit, 'whatsapp', attributes: ['identity_id' => $b]);

    expect($ledger->permits($customerId, $businessId, 'whatsapp', ConsentPurpose::Service, null, $a)->refusal)->toBe(ConsentRefusal::DoNotContact)
        ->and($ledger->permits($customerId, $businessId, 'whatsapp', ConsentPurpose::Service, null, $b)->permitted)->toBeTrue()
        // Unresolved identity: A's denial still applies (fail closed), whatever the customer-wide grant says.
        ->and($ledger->permits($customerId, $businessId, 'whatsapp', ConsentPurpose::Service, null, null)->refusal)->toBe(ConsentRefusal::DoNotContact);

    // A customer-wide do-not-contact stops B as well, whatever B's own grant says.
    $ledger->record($customerId, $businessId, null, ConsentState::DoNotContact, ConsentBasis::Operator, null);
    expect($ledger->permits($customerId, $businessId, 'whatsapp', ConsentPurpose::Service, null, $b)->refusal)->toBe(ConsentRefusal::DoNotContact);
});

it('covers only its own identity with an identity-scoped grant: never another identity, never a send whose identity is unresolved', function () {
    [$businessId, $customerId] = consentSubject();
    [$a, $b] = consentIdentities($customerId);
    $ledger = app(ConsentLedger::class);

    $ledger->record($customerId, $businessId, ConsentPurpose::Service, ConsentState::Granted, ConsentBasis::Explicit, 'whatsapp', attributes: ['identity_id' => $a]);

    expect($ledger->permits($customerId, $businessId, 'whatsapp', ConsentPurpose::Service, null, $a)->permitted)->toBeTrue()
        ->and($ledger->permits($customerId, $businessId, 'whatsapp', ConsentPurpose::Service, null, $b)->permitted)->toBeFalse()
        ->and($ledger->permits($customerId, $businessId, 'whatsapp', ConsentPurpose::Service, null, $b)->refusal)->toBe(ConsentRefusal::NotRecorded)
        ->and($ledger->permits($customerId, $businessId, 'whatsapp', ConsentPurpose::Service, null, null)->permitted)->toBeFalse()
        ->and($ledger->permits($customerId, $businessId, 'whatsapp', ConsentPurpose::Service, null, null)->refusal)->toBe(ConsentRefusal::NotRecorded);

    // The historical question carries the identity too.
    expect($ledger->permittedAt($customerId, $businessId, 'whatsapp', ConsentPurpose::Service, now()->subMinute(), null, $a)->permitted)->toBeFalse()
        ->and($ledger->permittedAt($customerId, $businessId, 'whatsapp', ConsentPurpose::Service, now()->addMinute(), null, $a)->permitted)->toBeTrue();
});

it('keeps each identity its own row in the projection, answers latestAtScope at the exact identity, and the rebuild agrees', function () {
    [$businessId, $customerId] = consentSubject();
    [$a, $b] = consentIdentities($customerId);
    $ledger = app(ConsentLedger::class);

    $ledger->record($customerId, $businessId, null, ConsentState::DoNotContact, ConsentBasis::Operator, null, attributes: ['identity_id' => $a]);
    $ledger->record($customerId, $businessId, null, ConsentState::DoNotContact, ConsentBasis::Operator, null, attributes: ['identity_id' => $b]);
    $ledger->record($customerId, $businessId, null, ConsentState::DoNotContact, ConsentBasis::Operator, null);

    expect(ConsentProjection::where('customer_id', $customerId)->count())->toBe(3)
        ->and(ConsentProjection::where('customer_id', $customerId)->whereNotNull('identity_id')->pluck('identity_id')->map(fn ($i) => (int) $i)->sort()->values()->all())->toBe([$a, $b])
        ->and((int) $ledger->latestAtScope($customerId, $businessId, null, null, null, $a)->identity_id)->toBe($a)
        ->and($ledger->latestAtScope($customerId, $businessId, null, null, null, null)->identity_id)->toBeNull();

    $shape = fn () => ConsentProjection::where('customer_id', $customerId)->orderBy('last_event_id')->get(['identity_id', 'state', 'last_event_id'])->toArray();
    $before = $shape();
    expect($ledger->rebuild($customerId))->toBe(3)
        ->and($shape())->toEqual($before);
});
