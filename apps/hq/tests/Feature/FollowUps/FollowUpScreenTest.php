<?php

use App\Consent\ConsentBasis;
use App\Consent\ConsentLedger;
use App\Consent\ConsentPurpose;
use App\Consent\ConsentRefusal;
use App\Consent\ConsentState;
use App\FollowUps\FollowUpState;
use App\FollowUps\OpportunityState;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\FollowUp;
use App\Models\Opportunity;
use App\Models\UserBusinessScope;
use App\Models\WorkflowInstance;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
 | docs/113 sections 4 and 5: the follow-up screen. Behind 2FA, a view permission, separate propose and
 | decide permissions and the business scope; reading writes nothing; every write is a transition the
 | service audits as the acting person. FollowUpContractTest holds the service itself.
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->lld = Brand::where('code', 'LLD')->sole();
    $this->bnc = Brand::where('code', 'BNC')->sole();
    $this->owner = $this->hqUser('owner');
    $this->customer = Customer::create(['business_id' => $this->lld->business_id, 'name' => 'Screen Customer', 'primary_phone_canonical' => '01711000555']);
    app(ConsentLedger::class)->record($this->customer->id, $this->customer->business_id, ConsentPurpose::Service, ConsentState::Granted, ConsentBasis::Operator, 'whatsapp', now()->subMinute(),
        ['evidence_ref' => 'test', 'actor_type' => 'human', 'actor_label' => 'test']);
});

function fsFollowUp(array $over = []): array
{
    return $over + ['customer_id' => test()->customer->id, 'kind' => 'service', 'origin' => 'promise', 'channel' => 'whatsapp',
        'due_at' => now()->addDay()->toIso8601String(), 'intent' => 'Call about the honey'];
}

it('registers every route behind auth, both 2FA gates and the follow-up permissions', function () {
    $expected = [
        'follow-ups.index' => 'permission:hq.followups.view',
        'follow-ups.store' => 'permission:hq.followups.propose',
        'follow-ups.opportunities.store' => 'permission:hq.followups.propose',
        'follow-ups.opportunities.open' => 'permission:hq.followups.decide',
        'follow-ups.opportunities.decline' => 'permission:hq.followups.decide',
        'follow-ups.opportunities.close' => 'permission:hq.followups.decide',
        'follow-ups.approve' => 'permission:hq.followups.decide',
        'follow-ups.decline' => 'permission:hq.followups.decide',
        'follow-ups.complete' => 'permission:hq.followups.decide',
        'follow-ups.cancel' => 'permission:hq.followups.decide',
    ];
    foreach ($expected as $name => $permission) {
        expect(Route::getRoutes()->getByName($name)->gatherMiddleware())->toContain('auth', '2fa.enabled', '2fa.verified', $permission);
    }
    expect(collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'follow-ups'))->count())->toBe(count($expected));
});

it('needs a passed 2FA challenge and the view permission; roles see it as seeded', function () {
    $this->get('/follow-ups')->assertRedirect();
    $this->actingAs($this->owner)->get('/follow-ups')->assertRedirect();

    foreach (['owner' => true, 'operator' => true, 'brand_manager' => true, 'support_agent' => true, 'finance_viewer' => false] as $role => $allowed) {
        $response = $this->actingAsHq($this->hqUser($role))->get('/follow-ups');
        $allowed ? $response->assertOk() : $response->assertForbidden();
    }
});

it('writes nothing when it is read', function () {
    $this->actingAsHq($this->owner)->post('/follow-ups', fsFollowUp())->assertSessionHasNoErrors();
    $this->actingAsHq($this->owner)->post('/follow-ups/opportunities', ['customer_id' => $this->customer->id, 'kind' => 'reorder', 'subject' => 'honey again'])->assertSessionHasNoErrors();

    $tables = ['follow_ups', 'opportunities', 'audit_logs', 'workflow_instances', 'workflow_steps', 'consent_events', 'tool_calls'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count().':'.DB::table($t)->max('id')])->all()
        + ['rows' => DB::table('follow_ups')->orderBy('id')->get()->toJson().DB::table('opportunities')->orderBy('id')->get()->toJson()];

    $before = $snapshot();
    foreach (['open', 'due', 'proposed', 'all'] as $state) {
        $this->actingAsHq($this->owner)->get('/follow-ups?state='.$state.'&opportunity_state=all')->assertOk();
    }
    expect($snapshot())->toBe($before);
});

it('proposes, shows, approves (scheduling a human-wait run) and completes as the acting person, each audited', function () {
    $this->actingAsHq($this->owner)->post('/follow-ups/opportunities', ['customer_id' => $this->customer->id, 'kind' => 'reorder', 'subject' => 'honey again', 'brand_id' => $this->lld->id])
        ->assertRedirect()->assertSessionHas('status', 'opportunity-proposed');
    $opportunity = Opportunity::sole();
    $this->actingAsHq($this->owner)->post('/follow-ups', fsFollowUp(['opportunity_id' => $opportunity->id, 'brand_id' => $this->lld->id]))
        ->assertRedirect()->assertSessionHas('status', 'follow-up-proposed');
    $followUp = FollowUp::sole();

    $this->actingAsHq($this->owner)->get('/follow-ups')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('follow-ups/index')
            ->has('followUps', 1)
            ->where('followUps.0.uuid', $followUp->uuid)
            ->where('followUps.0.state', 'proposed')
            ->where('followUps.0.consent.permitted', true)
            ->where('followUps.0.opportunity.subject', 'honey again')
            ->where('followUps.0.proposer', $this->owner->email)
            ->where('opportunities.0.state', 'proposed')
            ->where('counts.proposed', 1)
            ->where('can.decide', true));

    $this->actingAsHq($this->owner)->post("/follow-ups/opportunities/{$opportunity->uuid}/open")->assertSessionHas('status', 'opportunity-opened');
    $this->actingAsHq($this->owner)->post("/follow-ups/{$followUp->uuid}/approve", ['reason' => 'fine'])->assertSessionHas('status', 'follow-up-scheduled');

    $followUp->refresh();
    expect($followUp->state)->toBe(FollowUpState::Scheduled)
        ->and($followUp->decided_by_user_id)->toBe($this->owner->id)
        ->and(WorkflowInstance::where('instance_key', $followUp->workflow_instance_key)->sole()->status)->toBe(WorkflowInstance::WAITING)
        ->and(DB::table('workflow_steps')->whereNotNull('tool_key')->count())->toBe(0)
        ->and(DB::table('tool_calls')->count())->toBe(0);

    $this->actingAsHq($this->owner)->post("/follow-ups/{$followUp->uuid}/complete", ['note' => ''])->assertSessionHasErrors('note');
    $this->actingAsHq($this->owner)->post("/follow-ups/{$followUp->uuid}/complete", ['note' => 'called him'])->assertSessionHas('status', 'follow-up-done');
    $this->actingAsHq($this->owner)->post("/follow-ups/opportunities/{$opportunity->uuid}/close", ['close_reason' => 'converted', 'note' => 'his word'])->assertSessionHas('status', 'opportunity-closed');

    expect($followUp->fresh()->state)->toBe(FollowUpState::Done)
        ->and($opportunity->fresh()->state)->toBe(OpportunityState::Closed)
        ->and(AuditLog::whereIn('action', ['follow_up.proposed', 'follow_up.approved', 'follow_up.scheduled', 'follow_up.done', 'opportunity.proposed', 'opportunity.opened', 'opportunity.closed'])
            ->where('actor_id', $this->owner->id)->count())->toBe(7);

    // An illegal second decision is a form error, not a 500.
    $this->actingAsHq($this->owner)->post("/follow-ups/{$followUp->uuid}/approve")->assertSessionHasErrors('reason');
});

it('records a consent refusal from the screen as a refused row with its class, and tells the person', function () {
    $this->actingAsHq($this->owner)->post('/follow-ups', fsFollowUp(['kind' => 'marketing', 'brand_id' => $this->lld->id]))
        ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('status', 'follow-up-refused:'.ConsentRefusal::OptInMissing->value);

    $row = FollowUp::sole();
    expect($row->state)->toBe(FollowUpState::Refused)->and($row->consent_refusal)->toBe(ConsentRefusal::OptInMissing);

    $this->actingAsHq($this->owner)->get('/follow-ups?state=refused')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('followUps.0.consent.refusal', ConsentRefusal::OptInMissing->value)->where('followUps.0.state', 'refused'));
    $this->actingAsHq($this->owner)->post("/follow-ups/{$row->uuid}/approve")->assertSessionHasErrors('reason');
});

it('lets support staff and brand managers propose but never decide', function () {
    foreach (['support_agent', 'brand_manager'] as $role) {
        $user = $this->hqUser($role);
        $this->actingAsHq($user)->post('/follow-ups', fsFollowUp())->assertSessionHasNoErrors();
        $followUp = FollowUp::latest('id')->first();
        $this->actingAsHq($user)->post("/follow-ups/{$followUp->uuid}/approve")->assertForbidden();
        $this->actingAsHq($user)->post("/follow-ups/{$followUp->uuid}/decline", ['reason' => 'x'])->assertForbidden();
        $this->actingAsHq($user)->get('/follow-ups')->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.decide', false)->where('can.propose', true));
    }
    $viewer = $this->hqUser('finance_viewer');
    $this->actingAsHq($viewer)->post('/follow-ups', fsFollowUp())->assertForbidden();
    expect(FollowUp::count())->toBe(2)->and(FollowUp::where('state', FollowUpState::Proposed->value)->count())->toBe(2);
});

it('keeps another business\'s rows out of a scoped operator\'s page and decisions', function () {
    $this->actingAsHq($this->owner)->post('/follow-ups', fsFollowUp())->assertSessionHasNoErrors();
    $followUp = FollowUp::sole();

    $scoped = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $scoped->id, 'business_id' => $this->bnc->business_id]);

    $this->actingAsHq($scoped)->get('/follow-ups')->assertOk()->assertInertia(fn (Assert $page) => $page->has('followUps', 0)->where('counts.proposed', 0));
    $this->actingAsHq($scoped)->post("/follow-ups/{$followUp->uuid}/approve")->assertNotFound();
    $this->actingAsHq($scoped)->post("/follow-ups/{$followUp->uuid}/decline", ['reason' => 'x'])->assertNotFound();
    $this->actingAsHq($scoped)->post('/follow-ups', fsFollowUp())->assertNotFound();
    $this->actingAsHq($scoped)->get('/follow-ups?customer='.$this->customer->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('prefill', null));

    expect($followUp->fresh()->state)->toBe(FollowUpState::Proposed)->and(FollowUp::count())->toBe(1);
});

it('validates and turns the service\'s refusals into form errors', function () {
    $this->actingAsHq($this->owner)->post('/follow-ups', fsFollowUp(['channel' => 'internal']))->assertSessionHasErrors('channel');
    $this->actingAsHq($this->owner)->post('/follow-ups', fsFollowUp(['kind' => 'transactional']))->assertSessionHasErrors('kind');
    $this->actingAsHq($this->owner)->post('/follow-ups', fsFollowUp(['intent' => str_repeat('x', 501)]))->assertSessionHasErrors('intent');
    $this->actingAsHq($this->owner)->post('/follow-ups', fsFollowUp(['brand_id' => $this->bnc->id]))->assertSessionHasErrors('intent');
    $this->actingAsHq($this->owner)->post('/follow-ups', fsFollowUp(['customer_id' => 999999]))->assertNotFound();
    $this->actingAsHq($this->owner)->post('/follow-ups', fsFollowUp(['opportunity_id' => 999999]))->assertSessionHasErrors('opportunity_id');
    $this->actingAsHq($this->owner)->post('/follow-ups/opportunities', ['customer_id' => $this->customer->id, 'kind' => 'reorder', 'subject' => ''])->assertSessionHasErrors('subject');

    expect(FollowUp::count())->toBe(0)->and(Opportunity::count())->toBe(0);
});

it('links the page from the sidebar under its view permission', function () {
    expect(file_get_contents(resource_path('js/components/app-sidebar.tsx')))
        // docs/123: with the overdue badge, the responsible person's own count.
        ->toContain("{ title: 'Follow-ups', url: '/follow-ups', icon: CalendarCheck, permission: 'hq.followups.view', badge: 'followups' }");
});
