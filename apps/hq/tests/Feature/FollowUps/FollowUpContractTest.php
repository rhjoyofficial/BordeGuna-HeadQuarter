<?php

use App\Consent\ConsentBasis;
use App\Consent\ConsentLedger;
use App\Consent\ConsentPurpose;
use App\Consent\ConsentRefusal;
use App\Consent\ConsentState;
use App\FollowUps\FollowUpKind;
use App\FollowUps\FollowUpOrigin;
use App\FollowUps\FollowUpProposal;
use App\FollowUps\FollowUpProposalService;
use App\FollowUps\FollowUpState;
use App\FollowUps\IllegalTransition;
use App\FollowUps\OpportunityCloseReason;
use App\FollowUps\OpportunityKind;
use App\FollowUps\OpportunityProposal;
use App\FollowUps\OpportunityState;
use App\FollowUps\Proposer;
use App\FollowUps\Workflow\FollowUpWorkflow;
use App\Gateway\Descriptor\Channel;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\FollowUp;
use App\Models\Opportunity;
use App\Models\UserBusinessScope;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStep;
use App\Workflow\WorkflowEngine;
use Database\Seeders\DomainSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | docs/113 (S4 part 1): the Follow-up and Opportunity contract, inert. Proposal-only; consent asked of
 | the S7 ledger at proposal and at approval, a refusal recorded with its class (F7); approval = one
 | WorkflowEngine run that sleeps and then waits for a person (F10); every move audited (F5); nothing
 | reaches a customer. The queue is sync, so a started run advances in place.
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->lld = Brand::where('code', 'LLD')->sole();
    $this->bnc = Brand::where('code', 'BNC')->sole();
    $this->owner = $this->hqUser('owner');
    $this->customer = Customer::create(['business_id' => $this->lld->business_id, 'name' => 'Follow-up Customer', 'primary_phone_canonical' => '01711000777']);
    $this->service = app(FollowUpProposalService::class);
    $this->ledger = app(ConsentLedger::class);
});

function fuConsent(Customer $c, ConsentState $state, ?ConsentPurpose $purpose = ConsentPurpose::Service, ?string $channel = 'whatsapp', ConsentBasis $basis = ConsentBasis::Operator, ?int $brandId = null): void
{
    app(ConsentLedger::class)->record($c->id, $c->business_id, $purpose, $state, $basis, $channel, now()->subMinute(),
        ['brand_id' => $brandId, 'evidence_ref' => 'test', 'actor_type' => 'human', 'actor_label' => 'test']);
}

function fuProposal(Customer $c, array $over = []): FollowUpProposal
{
    return new FollowUpProposal(...array_replace([
        'customer' => $c, 'kind' => FollowUpKind::Service, 'channel' => Channel::Whatsapp,
        'dueAt' => now()->addDays(3), 'intent' => 'Ask whether the honey order should be repeated', 'origin' => FollowUpOrigin::Promise,
    ], $over));
}

function fuAgentRun(?int $businessId): AgentRun
{
    return AgentRun::create(['uuid' => (string) Str::uuid(), 'agent_key' => 'support', 'correlation_id' => (string) Str::uuid(),
        'trigger' => 'console', 'question' => 'q', 'autonomy_level' => 'a0', 'status' => 'answered', 'business_id' => $businessId]);
}

function fuRun(FollowUp $f): WorkflowInstance
{
    return WorkflowInstance::query()->where('instance_key', FollowUpProposalService::instanceKey($f))->firstOrFail();
}

/** Every table a customer-facing effect would leave a row in. */
function fuOutboundSnapshot(): array
{
    return collect(['tool_calls', 'conversation_messages', 'message_deliveries', 'scheduled_jobs', 'approvals'])
        ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
}

it('proposes an opportunity and a service follow-up for a customer with service consent, as the person, audited', function () {
    fuConsent($this->customer, ConsentState::Granted);
    $before = fuOutboundSnapshot();

    $opportunity = $this->service->proposeOpportunity(Proposer::person($this->owner), new OpportunityProposal(
        $this->customer, OpportunityKind::Reorder, 'Wants the 5 kg honey again next month', now()->addMonth(), $this->lld->id));
    $followUp = $this->service->proposeFollowUp(Proposer::person($this->owner), fuProposal($this->customer, ['opportunity' => $opportunity, 'brandId' => $this->lld->id]));

    expect($opportunity->state)->toBe(OpportunityState::Proposed)
        ->and($opportunity->business_id)->toBe($this->lld->business_id)
        ->and($opportunity->proposer_type)->toBe('person')
        ->and($opportunity->proposed_by_user_id)->toBe($this->owner->id)
        ->and($followUp->state)->toBe(FollowUpState::Proposed)
        ->and($followUp->consent_permitted)->toBeTrue()
        ->and($followUp->consent_refusal)->toBeNull()
        ->and($followUp->consent_event_id)->not->toBeNull()
        ->and($followUp->opportunity_id)->toBe($opportunity->id)
        ->and($followUp->workflow_instance_key)->toBeNull()
        ->and(AuditLog::where('action', 'opportunity.proposed')->where('actor_id', $this->owner->id)->count())->toBe(1)
        ->and(AuditLog::where('action', 'follow_up.proposed')->where('actor_id', $this->owner->id)->count())->toBe(1)
        ->and(fuOutboundSnapshot())->toBe($before);
});

it('refuses at proposal time, recorded with the consent class and audited, never dropped: do-not-contact, withdrawn, marketing without opt-in, nothing recorded', function () {
    $propose = fn (FollowUpKind $kind) => $this->service->proposeFollowUp(Proposer::person($this->owner), fuProposal($this->customer, ['kind' => $kind, 'brandId' => $this->lld->id]));

    // Nothing recorded at all: silence is never consent.
    $silent = $propose(FollowUpKind::Service);
    expect($silent->state)->toBe(FollowUpState::Refused)->and($silent->consent_refusal)->toBe(ConsentRefusal::NotRecorded)->and($silent->consent_event_id)->toBeNull();

    // A live inbound service grant is not a marketing opt-in.
    fuConsent($this->customer, ConsentState::Granted, ConsentPurpose::Service, 'whatsapp', ConsentBasis::Inbound);
    $marketing = $propose(FollowUpKind::Marketing);
    expect($marketing->state)->toBe(FollowUpState::Refused)->and($marketing->consent_refusal)->toBe(ConsentRefusal::OptInMissing);
    expect($propose(FollowUpKind::Service)->state)->toBe(FollowUpState::Proposed);

    // Withdrawal of all purposes stops service too.
    fuConsent($this->customer, ConsentState::Withdrawn, null, null);
    $withdrawn = $propose(FollowUpKind::Service);
    expect($withdrawn->state)->toBe(FollowUpState::Refused)->and($withdrawn->consent_refusal)->toBe(ConsentRefusal::Withdrawn)->and($withdrawn->consent_event_id)->not->toBeNull();

    // Do-not-contact beats everything, a later operator grant included.
    fuConsent($this->customer, ConsentState::DoNotContact, null, null);
    fuConsent($this->customer, ConsentState::Granted, ConsentPurpose::Marketing, 'whatsapp', ConsentBasis::Operator, $this->lld->id);
    $dnc = $propose(FollowUpKind::Marketing);
    expect($dnc->state)->toBe(FollowUpState::Refused)->and($dnc->consent_refusal)->toBe(ConsentRefusal::DoNotContact);

    expect(FollowUp::where('customer_id', $this->customer->id)->count())->toBe(5)
        ->and(AuditLog::where('action', 'follow_up.refused')->count())->toBe(4)
        ->and(AuditLog::where('action', 'follow_up.refused')->latest('id')->first()->reason)->toContain('[consent_do_not_contact]')
        ->and(WorkflowInstance::count())->toBe(0);
});

it('isolates brands: a LaLaDia marketing opt-in does not permit a Bionic-brand follow-up, and the check is the gateway\'s own ledger answer', function () {
    fuConsent($this->customer, ConsentState::Granted, ConsentPurpose::Marketing, 'whatsapp', ConsentBasis::Operator, $this->lld->id);

    $lld = $this->service->proposeFollowUp(Proposer::person($this->owner), fuProposal($this->customer, ['kind' => FollowUpKind::Marketing, 'brandId' => $this->lld->id]));
    expect($lld->state)->toBe(FollowUpState::Proposed)
        ->and($lld->consent_event_id)->toBe($this->ledger->permits($this->customer->id, $this->customer->business_id, 'whatsapp', ConsentPurpose::Marketing, $this->lld->id)->eventId);

    // Same business, no brand named: a brand's grant never covers a brandless send (docs/108 §3).
    $brandless = $this->service->proposeFollowUp(Proposer::person($this->owner), fuProposal($this->customer, ['kind' => FollowUpKind::Marketing]));
    expect($brandless->state)->toBe(FollowUpState::Refused)->and($brandless->consent_refusal)->toBe(ConsentRefusal::OptInMissing);

    // Another channel of the same brand: the opt-in was for WhatsApp.
    $sms = $this->service->proposeFollowUp(Proposer::person($this->owner), fuProposal($this->customer, ['kind' => FollowUpKind::Marketing, 'brandId' => $this->lld->id, 'channel' => Channel::Sms]));
    expect($sms->state)->toBe(FollowUpState::Refused)->and($sms->consent_refusal)->toBe(ConsentRefusal::OptInMissing);
});

it('re-checks consent at approval: a withdrawal between proposal and approval moves the row to refused and schedules nothing', function () {
    fuConsent($this->customer, ConsentState::Granted);
    $followUp = $this->service->proposeFollowUp(Proposer::person($this->owner), fuProposal($this->customer));
    expect($followUp->state)->toBe(FollowUpState::Proposed);

    fuConsent($this->customer, ConsentState::Withdrawn, null, null);
    $this->service->approveFollowUp($followUp, $this->owner, 'go ahead');

    expect($followUp->fresh()->state)->toBe(FollowUpState::Refused)
        ->and($followUp->fresh()->consent_refusal)->toBe(ConsentRefusal::Withdrawn)
        ->and($followUp->fresh()->decided_by_user_id)->toBe($this->owner->id)
        ->and($followUp->fresh()->workflow_instance_key)->toBeNull()
        ->and(WorkflowInstance::count())->toBe(0)
        ->and(AuditLog::where('action', 'follow_up.refused')->first()->reason)->toContain('at approval');
});

it('approval starts exactly one WorkflowEngine run as the approving person: it sleeps until due, then waits for a person; no tool step, no tool call, nothing outbound', function () {
    fuConsent($this->customer, ConsentState::Granted);
    $before = fuOutboundSnapshot();
    $followUp = $this->service->proposeFollowUp(Proposer::person($this->owner), fuProposal($this->customer, ['dueAt' => now()->addDays(2)]));

    $this->service->approveFollowUp($followUp, $this->owner, 'yes');
    $followUp->refresh();

    $run = fuRun($followUp);
    $state = app(WorkflowEngine::class)->inspect($followUp->workflow_instance_key);
    expect($followUp->state)->toBe(FollowUpState::Scheduled)
        ->and($followUp->workflow_type)->toBe(FollowUpWorkflow::TYPE)
        ->and($followUp->workflow_run_uuid)->toBe($run->uuid)
        ->and($run->type)->toBe(FollowUpWorkflow::TYPE)
        ->and($run->requested_by_user_id)->toBe($this->owner->id)
        ->and($run->business_id)->toBe($followUp->business_id)
        ->and($run->correlation_key)->toBe('follow_up:'.$followUp->uuid)
        ->and($run->status)->toBe(WorkflowInstance::WAITING)
        ->and($state->wait['kind'])->toBe('timer')
        ->and($state->wait['for'])->toBe(FollowUpWorkflow::STEP_UNTIL_DUE)
        ->and(WorkflowStep::where('workflow_instance_id', $run->id)->pluck('kind')->unique()->all())->toBe(['sleep'])
        ->and(WorkflowStep::whereNotNull('tool_key')->count())->toBe(0)
        ->and(fuOutboundSnapshot())->toBe($before)
        ->and(AuditLog::whereIn('action', ['follow_up.approved', 'follow_up.scheduled', 'workflow.started'])->where('actor_id', $this->owner->id)->count())->toBe(3);

    // Past the due time the run waits on the person's signal, still with no tool step.
    $this->travel(3)->days();
    app(WorkflowEngine::class)->reconcile();
    $state = app(WorkflowEngine::class)->inspect($followUp->workflow_instance_key);
    expect($state->status)->toBe(WorkflowInstance::WAITING)
        ->and($state->wait['kind'])->toBe('signal')
        ->and($state->wait['for'])->toBe(FollowUpWorkflow::SIGNAL_PERSON_DECIDED)
        ->and(WorkflowStep::where('workflow_instance_id', $run->id)->pluck('kind')->sort()->values()->all())->toBe(['signal', 'sleep'])
        ->and(WorkflowStep::whereNotNull('tool_key')->count())->toBe(0)
        ->and($followUp->fresh()->isDue())->toBeTrue()
        ->and(fuOutboundSnapshot())->toBe($before);

    // Approving again is an illegal move, not a second run.
    expect(fn () => $this->service->approveFollowUp($followUp->fresh(), $this->owner))->toThrow(IllegalTransition::class)
        ->and(WorkflowInstance::count())->toBe(1);
});

it('a due follow-up is completed or cancelled by a person: completion signals the run to its end, cancellation cancels it', function () {
    fuConsent($this->customer, ConsentState::Granted);
    $done = $this->service->proposeFollowUp(Proposer::person($this->owner), fuProposal($this->customer, ['dueAt' => now()->subMinute()]));
    $cancelled = $this->service->proposeFollowUp(Proposer::person($this->owner), fuProposal($this->customer, ['dueAt' => now()->addDay()]));
    $this->service->approveFollowUp($done, $this->owner);
    $this->service->approveFollowUp($cancelled, $this->owner);

    expect(app(WorkflowEngine::class)->inspect(FollowUpProposalService::instanceKey($done->fresh()))->wait['kind'])->toBe('signal');

    $this->service->completeFollowUp($done->fresh(), $this->owner, 'Called him; he will order on Monday');
    $this->service->cancelFollowUp($cancelled->fresh(), $this->owner, 'customer already reordered');

    expect($done->fresh()->state)->toBe(FollowUpState::Done)
        ->and($done->fresh()->close_note)->toBe('Called him; he will order on Monday')
        ->and(fuRun($done)->status)->toBe(WorkflowInstance::COMPLETED)
        ->and($cancelled->fresh()->state)->toBe(FollowUpState::Cancelled)
        ->and(fuRun($cancelled)->status)->toBe(WorkflowInstance::CANCELLED)
        ->and(AuditLog::where('action', 'follow_up.done')->count())->toBe(1)
        ->and(AuditLog::where('action', 'follow_up.cancelled')->count())->toBe(1)
        ->and(AuditLog::where('action', 'workflow.cancelled')->count())->toBe(1)
        ->and(DB::table('tool_calls')->count())->toBe(0);
});

it('enforces the state machines: no illegal move, and a concurrent decision loses', function () {
    fuConsent($this->customer, ConsentState::Granted);
    $proposed = $this->service->proposeFollowUp(Proposer::person($this->owner), fuProposal($this->customer));
    $refused = $this->service->proposeFollowUp(Proposer::person($this->owner), fuProposal($this->customer, ['kind' => FollowUpKind::Marketing]));
    $opportunity = $this->service->proposeOpportunity(Proposer::person($this->owner), new OpportunityProposal($this->customer, OpportunityKind::Other, 'x'));

    expect(fn () => $this->service->completeFollowUp($proposed, $this->owner, 'n'))->toThrow(IllegalTransition::class)
        ->and(fn () => $this->service->cancelFollowUp($proposed, $this->owner, 'n'))->toThrow(IllegalTransition::class)
        ->and(fn () => $this->service->approveFollowUp($refused, $this->owner))->toThrow(IllegalTransition::class)
        ->and(fn () => $this->service->declineFollowUp($refused, $this->owner, 'n'))->toThrow(IllegalTransition::class)
        ->and(fn () => $this->service->closeOpportunity($opportunity, $this->owner, OpportunityCloseReason::Lost))->toThrow(IllegalTransition::class)
        ->and(fn () => $this->service->declineFollowUp($proposed, $this->owner, '  '))->toThrow(InvalidArgumentException::class);

    // Two operators holding the same proposed row: the second decision finds the row moved.
    $stale = FollowUp::find($proposed->id);
    $this->service->declineFollowUp($proposed, $this->owner, 'no need');
    expect(fn () => $this->service->approveFollowUp($stale, $this->owner))->toThrow(IllegalTransition::class)
        ->and($proposed->fresh()->state)->toBe(FollowUpState::Declined)
        ->and(WorkflowInstance::count())->toBe(0);

    $this->service->openOpportunity($opportunity, $this->owner);
    $this->service->closeOpportunity($opportunity, $this->owner, OpportunityCloseReason::Converted, 'he says he ordered');
    expect($opportunity->fresh()->state)->toBe(OpportunityState::Closed)
        ->and($opportunity->fresh()->close_reason)->toBe(OpportunityCloseReason::Converted)
        ->and(AuditLog::whereIn('action', ['opportunity.opened', 'opportunity.closed'])->count())->toBe(2)
        // docs/113 F4: a person's word writes no outcome row.
        ->and(DB::table('outcomes')->count())->toBe(0)->and(DB::table('outcome_attributions')->count())->toBe(0);
});

it('lets an agent run propose only, audited as the agent; deciding needs a person with the permission, inside their business scope', function () {
    fuConsent($this->customer, ConsentState::Granted);
    $run = fuAgentRun($this->customer->business_id);

    $followUp = $this->service->proposeFollowUp(Proposer::agentRun($run, 'p1'), fuProposal($this->customer));
    $opportunity = $this->service->proposeOpportunity(Proposer::agentRun($run, 'p2'), new OpportunityProposal($this->customer, OpportunityKind::Wholesale, 'asked for a 50 kg price'));
    $audit = AuditLog::where('action', 'follow_up.proposed')->sole();

    expect($followUp->state)->toBe(FollowUpState::Proposed)
        ->and($followUp->proposer_type)->toBe('agent_run')
        ->and($followUp->proposed_by_agent_run_id)->toBe($run->id)
        ->and($followUp->proposal_ref)->toBe('p1')
        ->and($opportunity->proposed_by_agent_run_id)->toBe($run->id)
        ->and($audit->actor_type)->toBe('agent')
        ->and($audit->after['agent_run_uuid'])->toBe($run->uuid)
        ->and(WorkflowInstance::count())->toBe(0);

    // A run of another business cannot propose for this customer.
    expect(fn () => $this->service->proposeFollowUp(Proposer::agentRun(fuAgentRun($this->bnc->business_id)), fuProposal($this->customer)))
        ->toThrow(InvalidArgumentException::class);

    // view + propose, no decide.
    $agent = $this->hqUser('support_agent');
    expect(fn () => $this->service->approveFollowUp($followUp, $agent))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->service->openOpportunity($opportunity, $agent))->toThrow(AuthorizationException::class);
    expect($this->service->proposeOpportunity(Proposer::person($agent), new OpportunityProposal($this->customer, OpportunityKind::Other, 'heard it'))->proposed_by_user_id)->toBe($agent->id);
    $viewer = $this->hqUser('finance_viewer');
    expect(fn () => $this->service->proposeOpportunity(Proposer::person($viewer), new OpportunityProposal($this->customer, OpportunityKind::Other, 'x')))->toThrow(AuthorizationException::class);

    // An operator scoped to Bionic neither sees nor decides on a LaLaDia row.
    $scoped = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $scoped->id, 'business_id' => $this->bnc->business_id]);
    expect(FollowUp::query()->visibleTo($scoped)->count())->toBe(0)
        ->and(Opportunity::query()->visibleTo($scoped)->count())->toBe(0)
        ->and(FollowUp::query()->visibleTo($this->owner)->count())->toBe(1)
        ->and(fn () => $this->service->approveFollowUp($followUp, $scoped))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->service->proposeFollowUp(Proposer::person($scoped), fuProposal($this->customer)))->toThrow(AuthorizationException::class);
    expect($followUp->fresh()->state)->toBe(FollowUpState::Proposed);
});

it('fails closed on what does not belong together, writing nothing', function () {
    fuConsent($this->customer, ConsentState::Granted);
    $other = Customer::create(['business_id' => $this->bnc->business_id, 'name' => 'Bionic Customer', 'primary_phone_canonical' => '01711000888']);
    $otherOpportunity = $this->service->proposeOpportunity(Proposer::person($this->owner), new OpportunityProposal($other, OpportunityKind::Other, 'y'));
    $merged = Customer::create(['business_id' => $this->lld->business_id, 'name' => 'Absorbed', 'primary_phone_canonical' => '01711000999', 'merged_into_id' => $this->customer->id]);
    $me = Proposer::person($this->owner);

    $cases = [
        'brand of another business' => fuProposal($this->customer, ['brandId' => $this->bnc->id]),
        'opportunity of another customer' => fuProposal($this->customer, ['opportunity' => $otherOpportunity]),
        'internal channel' => fuProposal($this->customer, ['channel' => Channel::Internal]),
        'empty intent' => fuProposal($this->customer, ['intent' => '   ']),
        'merged customer' => fuProposal($merged),
        'unknown conversation' => fuProposal($this->customer, ['conversationId' => 999999]),
    ];
    foreach ($cases as $name => $spec) {
        expect(fn () => $this->service->proposeFollowUp($me, $spec))->toThrow(InvalidArgumentException::class, message: $name);
    }
    expect(fn () => $this->service->proposeOpportunity($me, new OpportunityProposal($this->customer, OpportunityKind::Other, str_repeat('x', 501))))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Proposer::agentRun(fuAgentRun(null), 'bad ref!'))->toThrow(InvalidArgumentException::class);

    expect(FollowUp::count())->toBe(0)->and(Opportunity::count())->toBe(1)
        ->and(fn () => $otherOpportunity->delete())->toThrow(LogicException::class);
});
