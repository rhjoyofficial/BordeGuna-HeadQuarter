<?php

use App\Consent\ConsentBasis;
use App\Consent\ConsentLedger;
use App\Consent\ConsentPurpose;
use App\Consent\ConsentState;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Business;
use App\Models\ConsentEvent;
use App\Models\ConsentProjection;
use App\Models\Customer;
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
