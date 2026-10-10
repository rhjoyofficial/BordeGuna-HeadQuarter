<?php

use App\Consent\ConsentLedger;
use App\Consent\ConsentPurpose;
use App\Consent\ConsentRefusal;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Business;
use App\Models\ConsentEvent;
use App\Models\Customer;
use App\Models\UserBusinessScope;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
 | docs/108 §7 (S7): the operator screen records consent acts as ledger events, as the acting person,
 | behind 2FA, a view permission, separate restrict/grant permissions and the business scope. What it
 | records is what the gateway enforces (ConsentEnforcementTest holds the runtime side).
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->lld = Brand::where('code', 'LLD')->sole();
    $this->customer = Customer::create(['business_id' => $this->lld->business_id, 'name' => 'Screen Customer', 'primary_phone_canonical' => '01711000999']);
});

function csPost(Customer $customer, array $data): array
{
    return ['/customers/'.$customer->id.'/consent', $data + ['evidence_ref' => 'WhatsApp message 10 Oct', 'reason' => 'asked to stop']];
}

it('registers both routes behind auth, both 2FA gates and a consent permission', function () {
    foreach (['customers.consent' => 'permission:hq.consent.view', 'customers.consent.store' => 'permission:hq.consent.restrict|hq.consent.grant|hq.consent.lift_restriction'] as $name => $permission) {
        expect(Route::getRoutes()->getByName($name)->gatherMiddleware())->toContain('auth', '2fa.enabled', '2fa.verified', $permission);
    }
});

it('shows the consent page with the gateway\'s own answer, the position and the history', function () {
    $owner = $this->hqUser('owner');
    $this->actingAsHq($owner)->post(...csPost($this->customer, ['act' => 'marketing_opt_in', 'channel' => 'whatsapp', 'brand_id' => $this->lld->id, 'reason' => 'ticked the box']))
        ->assertSessionHasNoErrors();

    $this->actingAsHq($owner)->get('/customers/'.$this->customer->id.'/consent')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('customers/consent')
            ->where('customer.id', $this->customer->id)
            ->has('history', 1)
            ->where('history.0.state', 'granted')
            ->where('history.0.basis', 'operator')
            ->where('history.0.actor_label', $owner->email)
            ->where('position.0.brand_id', $this->lld->id)
            ->where('grid.1.purpose', 'marketing')
            ->where('grid.1.cells.whatsapp.permitted', true)
            ->where('grid.1.cells.sms.refusal', ConsentRefusal::OptInMissing->value)
            ->where('grid.0.cells.whatsapp.refusal', ConsentRefusal::NotRecorded->value)
            ->where('can.grant', true));
});

it('needs a passed 2FA challenge and the view permission', function () {
    $this->get('/customers/'.$this->customer->id.'/consent')->assertRedirect();

    $owner = $this->hqUser('owner');
    $this->actingAs($owner)->get('/customers/'.$this->customer->id.'/consent')->assertRedirect(); // challenge not passed

    $this->actingAsHq($this->hqUser('brand_manager'))->get('/customers/'.$this->customer->id.'/consent')->assertForbidden();
});

it('lets support staff restrict but never widen', function () {
    $agent = $this->hqUser('support_agent');

    $this->actingAsHq($agent)->post(...csPost($this->customer, ['act' => 'withdraw', 'purpose' => 'marketing']))->assertSessionHasNoErrors();
    $this->actingAsHq($agent)->post(...csPost($this->customer, ['act' => 'do_not_contact']))->assertSessionHasNoErrors();
    $this->actingAsHq($agent)->post(...csPost($this->customer, ['act' => 'marketing_opt_in', 'channel' => 'whatsapp', 'brand_id' => $this->lld->id]))->assertForbidden();
    $this->actingAsHq($agent)->post(...csPost($this->customer, ['act' => 'lift']))->assertForbidden();

    expect(ConsentEvent::where('customer_id', $this->customer->id)->pluck('state')->map->value->all())->toBe(['withdrawn', 'do_not_contact']);
});

it('records the act as the acting person, with evidence, and audits it twice: the ledger write and the named act', function () {
    $owner = $this->hqUser('owner');

    $this->actingAsHq($owner)->post(...csPost($this->customer, ['act' => 'do_not_contact', 'evidence_ref' => 'call log #77', 'reason' => 'asked never to be called']))
        ->assertSessionHasNoErrors();

    $event = ConsentEvent::where('customer_id', $this->customer->id)->sole();
    expect($event->state->value)->toBe('do_not_contact')
        ->and($event->basis->value)->toBe('operator')
        ->and($event->actor_type)->toBe('human')
        ->and((int) $event->actor_id)->toBe($owner->id)
        ->and($event->evidence_ref)->toBe('call log #77')
        ->and($event->reason)->toBe('asked never to be called')
        ->and($event->channel)->toBeNull()
        ->and($event->purpose)->toBeNull();

    foreach (['consent.recorded', 'consent.operator_recorded'] as $action) {
        $audit = AuditLog::where('action', $action)->latest('id')->firstOrFail();
        expect((int) $audit->actor_id)->toBe($owner->id);
    }

    // And the runtime answer changed at once: every purpose, every channel.
    expect(app(ConsentLedger::class)->permits($this->customer->id, $this->customer->business_id, 'messenger', ConsentPurpose::Service, $this->lld->id)->refusal)
        ->toBe(ConsentRefusal::DoNotContact);
});

it('refuses an act without evidence or reason, dated in the future, or an opt-in without its channel or brand', function () {
    $owner = $this->hqUser('owner');
    $url = '/customers/'.$this->customer->id.'/consent';

    $this->actingAsHq($owner)->post($url, ['act' => 'withdraw'])->assertSessionHasErrors(['evidence_ref', 'reason']);
    $this->actingAsHq($owner)->post(...csPost($this->customer, ['act' => 'withdraw', 'occurred_at' => now()->addDay()->toIso8601String()]))->assertSessionHasErrors('occurred_at');
    $this->actingAsHq($owner)->post(...csPost($this->customer, ['act' => 'marketing_opt_in', 'brand_id' => $this->lld->id]))->assertSessionHasErrors('act');
    $this->actingAsHq($owner)->post(...csPost($this->customer, ['act' => 'marketing_opt_in', 'channel' => 'whatsapp']))->assertSessionHasErrors('act');
    // A brand from another business is refused.
    $other = Brand::where('code', 'BNC')->sole();
    $this->actingAsHq($owner)->post(...csPost($this->customer, ['act' => 'marketing_opt_in', 'channel' => 'whatsapp', 'brand_id' => $other->id]))->assertSessionHasErrors('act');
    // Nothing standing to lift.
    $this->actingAsHq($owner)->post(...csPost($this->customer, ['act' => 'lift']))->assertSessionHasErrors('act');

    expect(ConsentEvent::where('customer_id', $this->customer->id)->count())->toBe(0);
});

it('keeps a restricted person inside their businesses', function () {
    $owner = $this->hqUser('owner');
    UserBusinessScope::create(['user_id' => $owner->id, 'business_id' => Business::where('slug', 'bionic')->sole()->id]);

    $this->actingAsHq($owner)->get('/customers/'.$this->customer->id.'/consent')->assertForbidden();
    $this->actingAsHq($owner)->post(...csPost($this->customer, ['act' => 'do_not_contact']))->assertForbidden();
    expect(ConsentEvent::where('customer_id', $this->customer->id)->count())->toBe(0);
});

it('records an act for one identity of the person, refuses another person\'s identity or a channel the identity does not live on, and shows the per-identity grid (docs/114 §2)', function () {
    $owner = $this->hqUser('owner');
    $wa = \App\Models\CustomerIdentity::create(['customer_id' => $this->customer->id, 'channel' => 'whatsapp', 'external_id' => '8801711000999']);
    $stranger = Customer::create(['business_id' => $this->lld->business_id, 'name' => 'Someone else']);
    $foreign = \App\Models\CustomerIdentity::create(['customer_id' => $stranger->id, 'channel' => 'whatsapp', 'external_id' => '8801711000111']);
    $url = '/customers/'.$this->customer->id.'/consent';

    $this->actingAsHq($owner)->post(...csPost($this->customer, ['act' => 'do_not_contact', 'identity_id' => $wa->id]))->assertSessionHasNoErrors();
    $event = ConsentEvent::where('customer_id', $this->customer->id)->sole();
    expect((int) $event->identity_id)->toBe($wa->id)->and($event->channel)->toBeNull()->and($event->state->value)->toBe('do_not_contact');

    // Another person's identity, and a Messenger channel on a WhatsApp identity: both refused, nothing written.
    $this->actingAsHq($owner)->post(...csPost($this->customer, ['act' => 'do_not_contact', 'identity_id' => $foreign->id]))->assertSessionHasErrors('act');
    $this->actingAsHq($owner)->post(...csPost($this->customer, ['act' => 'withdraw', 'identity_id' => $wa->id, 'channel' => 'messenger']))->assertSessionHasErrors('act');
    expect(ConsentEvent::where('customer_id', $this->customer->id)->count())->toBe(1);

    $label = 'whatsapp ...0999 (#'.$wa->id.')';
    $this->actingAsHq($owner)->get($url)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('customers/consent')
            ->has('identities', 1)
            ->where('identities.0.id', $wa->id)
            ->where('identities.0.label', $label)
            ->where('identity_grid.0.identity_id', $wa->id)
            ->where('identity_grid.0.channel', 'whatsapp')
            ->where('identity_grid.0.cells.service.refusal', ConsentRefusal::DoNotContact->value)
            ->where('identity_grid.0.cells.marketing.refusal', ConsentRefusal::DoNotContact->value)
            ->where('position.0.identity_id', $wa->id)
            ->where('position.0.identity', $label)
            ->where('position.0.two_person', true)
            ->where('history.0.identity', $label)
            // The customer-wide grid asks with no identity resolved: the identity's denial still applies.
            ->where('grid.0.cells.whatsapp.refusal', ConsentRefusal::DoNotContact->value)
            ->has('pending_lifts', 0)
            ->where('can.lift_restriction', true));
});
