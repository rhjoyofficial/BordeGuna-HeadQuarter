<?php

use App\Consent\ConsentBasis;
use App\Consent\ConsentLedger;
use App\Consent\ConsentPurpose;
use App\Consent\ConsentState;
use App\FollowUps\FollowUpKind;
use App\FollowUps\FollowUpOrigin;
use App\FollowUps\FollowUpProposal;
use App\FollowUps\FollowUpProposalService;
use App\FollowUps\FollowUpState;
use App\FollowUps\Proposer;
use App\Gateway\Descriptor\Channel;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Business;
use App\Models\Customer;
use App\Models\UserBusinessScope;
use Database\Seeders\DomainSeeder;

/*
 | docs/131 section 2 item 4 (docs/133): a sole eligible owner/operator may decide their own follow-up proposal,
 | recorded `self_decided` with an audit row naming the bootstrap rule. Once another eligible independent decider
 | exists (enabled, holds hq.followups.decide, may act on the business), the proposer may not approve it. The
 | docs/125 item 22 playbook rule, reused.
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->lld = Brand::where('code', 'LLD')->sole();
    $this->owner = $this->hqUser('owner');
    $this->customer = Customer::create(['business_id' => $this->lld->business_id, 'name' => 'Self Decision Customer', 'primary_phone_canonical' => '01711000777']);
    app(ConsentLedger::class)->record($this->customer->id, $this->customer->business_id, ConsentPurpose::Service, ConsentState::Granted, ConsentBasis::Operator, 'whatsapp',
        now()->subMinute(), ['evidence_ref' => 'test', 'actor_type' => 'human', 'actor_label' => 'test']);
    $this->service = app(FollowUpProposalService::class);
    $this->propose = fn () => $this->service->proposeFollowUp(Proposer::person($this->owner), new FollowUpProposal(
        customer: $this->customer, kind: FollowUpKind::Service, channel: Channel::Whatsapp, dueAt: now()->addDay(),
        intent: 'Confirm the delivery address', origin: FollowUpOrigin::Operator,
    ));
});

it('allows the sole eligible decider to approve their own proposal, recorded self_decided with the bootstrap rule audited', function () {
    // A disabled holder and a holder without access to this business are not eligible deciders.
    $disabled = $this->hqUser('operator');
    $disabled->forceFill(['is_active' => false])->save();
    $elsewhere = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $elsewhere->id, 'business_id' => Business::where('slug', 'bionic')->value('id'), 'access' => 'full']);

    $own = ($this->propose)();
    expect($this->service->otherEligibleDeciders($own, $this->owner))->toBe(0);

    $approved = $this->service->approveFollowUp($own, $this->owner, 'only decider');
    expect($approved->state)->toBe(FollowUpState::Scheduled);

    $rule = AuditLog::where('action', 'follow_up.self_decision_bootstrap')->where('target_id', $own->id)->sole();
    expect($rule->after['rule'])->toBe(FollowUpProposalService::SELF_DECISION_BOOTSTRAP_RULE)
        ->and($rule->after['other_eligible_deciders'])->toBe(0)
        ->and($rule->actor_id)->toBe($this->owner->id)
        ->and(AuditLog::where('action', 'follow_up.approved')->where('target_id', $own->id)->sole()->after['self_decided'])->toBeTrue();

    // The visible label on the follow-up screen.
    $this->actingAsHq($this->owner)->get('/follow-ups?state=all')->assertOk()
        ->assertInertia(fn ($page) => $page->where('followUps', fn ($rows) => collect($rows)->firstWhere('uuid', $own->uuid)['self_decided'] === true));
});

it('refuses self-approval once another eligible decider exists, and the other person may approve', function () {
    $operator = $this->hqUser('operator');
    $own = ($this->propose)();

    expect(fn () => $this->service->approveFollowUp($own, $this->owner))->toThrow(InvalidArgumentException::class, 'Decider independence');
    expect($own->fresh()->state)->toBe(FollowUpState::Proposed)
        ->and(AuditLog::where('target_id', $own->id)->whereIn('action', ['follow_up.approved', 'follow_up.self_decision_bootstrap'])->count())->toBe(0);

    // Through the screen it is a form error, never a 500 and never a silent success.
    $this->actingAsHq($this->owner)->post("/follow-ups/{$own->uuid}/approve")->assertSessionHasErrors('reason');
    expect($own->fresh()->state)->toBe(FollowUpState::Proposed);

    // Declining one's own proposal is never refused on this ground (sending back, as item 22).
    $mine = ($this->propose)();
    expect($this->service->declineFollowUp($mine, $this->owner, 'not needed')->state)->toBe(FollowUpState::Declined);

    $approved = $this->service->approveFollowUp($own->fresh(), $operator, 'read it');
    expect($approved->state)->toBe(FollowUpState::Scheduled)
        ->and(AuditLog::where('action', 'follow_up.approved')->where('target_id', $own->id)->sole()->after)->not->toHaveKey('self_decided')
        ->and(AuditLog::where('action', 'follow_up.self_decision_bootstrap')->count())->toBe(0);
});

it('does not count a decider without access to the business, and counts one with it', function () {
    $elsewhere = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $elsewhere->id, 'business_id' => Business::where('slug', 'bionic')->value('id'), 'access' => 'full']);
    $own = ($this->propose)();
    expect($this->service->otherEligibleDeciders($own, $this->owner))->toBe(0);

    // The same person given this business becomes an independent decider, and the proposer is refused.
    UserBusinessScope::create(['user_id' => $elsewhere->id, 'business_id' => $this->lld->business_id, 'access' => 'full']);
    expect($this->service->otherEligibleDeciders($own, $this->owner))->toBe(1)
        ->and(fn () => $this->service->approveFollowUp($own, $this->owner))->toThrow(InvalidArgumentException::class, 'Decider independence');

    // A person without the permission is never a decider, whatever their scope.
    $agent = $this->hqUser('support_agent');
    expect($this->service->otherEligibleDeciders($own, $agent))->toBe(2);
});
