<?php

namespace App\FollowUps;

use App\Consent\ConsentDecision;
use App\Consent\ConsentLedger;
use App\FollowUps\Outcomes\FollowUpOutcomeLink;
use App\FollowUps\Workflow\FollowUpWorkflow;
use App\Gateway\Descriptor\Channel;
use App\Models\Brand;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\FollowUp;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Authorization\BusinessScope;
use App\Workflow\WorkflowEngine;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The only writer of `opportunities` and `follow_ups` (docs/113 sections 3 to 7).
 *
 * Proposal-only, by construction:
 *  - a person or an agent run may PROPOSE (`Proposer`); only a `User` with `hq.followups.decide` may
 *    decide, so an agent run cannot reach any deciding method at all;
 *  - a follow-up's consent is asked of the S7 ledger at proposal time and again at approval time
 *    (F8), with the SAME `ConsentLedger::permits` the Tool Gateway uses; a refusal is a `refused` row
 *    with its class, never a dropped proposal (F7);
 *  - approval schedules exactly one WorkflowEngine run of `followup.await_person`, which sleeps and
 *    then waits for a person (F10); nothing here, and nothing in that run, reaches a customer;
 *  - every transition is one conditional update (F3) and one audit row as the acting person or agent (F5).
 *
 * ⛔ This class imports no tool, gateway, connector, platform or conversation outbound class, and
 * `FollowUpStructureTest` keeps it that way.
 */
final class FollowUpProposalService
{
    public const PERMISSION_VIEW = 'hq.followups.view';

    public const PERMISSION_PROPOSE = 'hq.followups.propose';

    public const PERMISSION_DECIDE = 'hq.followups.decide';

    /** Evidence is identifiers, not a data store (docs/107 D5, same bound). */
    private const EVIDENCE_MAX_BYTES = 16 * 1024;

    public function __construct(
        private readonly ConsentLedger $ledger,
        private readonly Auditor $auditor,
        private readonly WorkflowEngine $workflows,
        private readonly BusinessScope $scope,
        private readonly FollowUpOutcomeLink $outcomes,
    ) {}

    // ---------------------------------------------------------------- proposing

    public function proposeOpportunity(Proposer $by, OpportunityProposal $spec): Opportunity
    {
        $customer = $this->proposable($by, $spec->customer, $spec->brandId, $spec->conversationId, $spec->evidence);
        $subject = trim($spec->subject);
        if ($subject === '' || mb_strlen($subject) > 500) {
            throw new InvalidArgumentException('An opportunity needs a subject of 1 to 500 characters.');
        }

        return DB::transaction(function () use ($by, $spec, $customer, $subject) {
            $opportunity = Opportunity::query()->create([
                'uuid' => (string) Str::uuid(),
                'business_id' => $customer->business_id,
                'brand_id' => $spec->brandId,
                'customer_id' => $customer->id,
                'conversation_id' => $spec->conversationId,
                'kind' => $spec->kind->value,
                'subject' => $subject,
                'expected_at' => $spec->expectedAt?->format('Y-m-d'),
                'state' => OpportunityState::Proposed->value,
                'evidence' => $spec->evidence === [] ? null : $spec->evidence,
            ] + $by->columns());

            $this->audit($by, 'opportunity.proposed', $opportunity, after: [
                'state' => OpportunityState::Proposed->value, 'kind' => $spec->kind->value, 'customer_id' => $customer->id,
                'business_id' => $customer->business_id, 'brand_id' => $spec->brandId, 'subject' => $subject,
            ]);

            return $opportunity;
        });
    }

    public function proposeFollowUp(Proposer $by, FollowUpProposal $spec): FollowUp
    {
        $customer = $this->proposable($by, $spec->customer, $spec->brandId, $spec->conversationId, $spec->evidence);
        $intent = trim($spec->intent);
        if ($intent === '' || mb_strlen($intent) > 500) {
            throw new InvalidArgumentException('A follow-up needs an intent of 1 to 500 characters: what the contact is for, never the message itself.');
        }
        if ($spec->channel === Channel::Internal) {
            throw new InvalidArgumentException('A follow-up names the channel a person would use; [internal] is not one (docs/113 F9).');
        }
        if ($spec->opportunity !== null) {
            if ($spec->opportunity->customer_id !== $customer->id || $spec->opportunity->business_id !== $customer->business_id) {
                throw new InvalidArgumentException('A follow-up serves an opportunity of the same customer and business.');
            }
            if ($spec->opportunity->state->isFinal()) {
                throw new InvalidArgumentException("Opportunity {$spec->opportunity->uuid} is [{$spec->opportunity->state->value}]; a follow-up serves an open one.");
            }
        }

        $dueAt = CarbonImmutable::instance(Carbon::parse($spec->dueAt));
        $decision = $this->consent($customer, $spec->channel, $spec->kind, $spec->brandId);

        return DB::transaction(function () use ($by, $spec, $customer, $intent, $dueAt, $decision) {
            $followUp = FollowUp::query()->create([
                'uuid' => (string) Str::uuid(),
                'business_id' => $customer->business_id,
                'brand_id' => $spec->brandId,
                'customer_id' => $customer->id,
                'conversation_id' => $spec->conversationId,
                'opportunity_id' => $spec->opportunity?->id,
                'origin' => $spec->origin->value,
                'kind' => $spec->kind->value,
                'channel' => $spec->channel->value,
                'due_at' => $dueAt,
                'intent' => $intent,
                'state' => ($decision->permitted ? FollowUpState::Proposed : FollowUpState::Refused)->value,
                'evidence' => $spec->evidence === [] ? null : $spec->evidence,
            ] + $by->columns() + $this->consentColumns($decision));

            $after = [
                'state' => $followUp->state->value, 'kind' => $spec->kind->value, 'channel' => $spec->channel->value,
                'customer_id' => $customer->id, 'business_id' => $customer->business_id, 'brand_id' => $spec->brandId,
                'due_at' => $dueAt->toIso8601String(), 'opportunity_id' => $spec->opportunity?->id,
            ];
            if ($decision->permitted) {
                $this->audit($by, 'follow_up.proposed', $followUp, after: $after + ['consent_event_id' => $decision->eventId]);
            } else {
                // docs/113 F7: recorded and audited with the class, so a refused plan is countable, never invisible.
                $this->audit($by, 'follow_up.refused', $followUp, after: $after + $this->consentColumns($decision),
                    reason: 'consent refused at proposal: ['.$decision->refusal?->value.'] '.$decision->reason);
            }

            return $followUp;
        });
    }

    // ---------------------------------------------------------------- deciding on an opportunity

    public function openOpportunity(Opportunity $opportunity, User $by, ?string $reason = null): Opportunity
    {
        $this->decider($by, $opportunity);
        $from = $opportunity->state;
        $opportunity->transition(OpportunityState::Open, $this->decision($by, $reason));
        $this->auditPerson($by, 'opportunity.opened', $opportunity, $from->value, $reason);

        return $opportunity;
    }

    public function declineOpportunity(Opportunity $opportunity, User $by, string $reason): Opportunity
    {
        $this->decider($by, $opportunity);
        $reason = $this->requiredReason($reason);
        $from = $opportunity->state;
        $opportunity->transition(OpportunityState::Declined, $this->decision($by, $reason));
        $this->auditPerson($by, 'opportunity.declined', $opportunity, $from->value, $reason);

        return $opportunity;
    }

    public function closeOpportunity(Opportunity $opportunity, User $by, OpportunityCloseReason $closeReason, ?string $note = null): Opportunity
    {
        $this->decider($by, $opportunity);
        $note = $note === null ? null : mb_substr(trim($note), 0, 1000);
        $from = $opportunity->state;
        // docs/113 F4: a person's word. No outcome row is consulted or written here.
        $opportunity->transition(OpportunityState::Closed, [
            'closed_by_user_id' => $by->getKey(), 'closed_at' => CarbonImmutable::now(),
            'close_reason' => $closeReason->value, 'close_note' => $note,
        ]);
        $this->auditPerson($by, 'opportunity.closed', $opportunity, $from->value, $note, ['close_reason' => $closeReason->value]);

        return $opportunity;
    }

    // ---------------------------------------------------------------- deciding on a follow-up

    /**
     * Approve and schedule in one transaction (docs/113 section 3.1): the consent is asked again, and a
     * ledger that now says no moves the row to `refused` instead; otherwise the WorkflowEngine run is
     * started as the approving person and the row becomes `scheduled`. If the start fails nothing moves.
     */
    public function approveFollowUp(FollowUp $followUp, User $by, ?string $reason = null): FollowUp
    {
        $this->decider($by, $followUp);
        if ($followUp->state !== FollowUpState::Proposed) {
            throw new IllegalTransition("Follow-up {$followUp->uuid} is [{$followUp->state->value}], not [proposed].");
        }

        $decision = $this->consent($followUp->customer, Channel::from($followUp->channel), $followUp->kind, $followUp->brand_id);

        return DB::transaction(function () use ($followUp, $by, $reason, $decision) {
            if (! $decision->permitted) {
                $followUp->transition(FollowUpState::Refused, $this->decision($by, $reason) + $this->consentColumns($decision));
                $this->auditPerson($by, 'follow_up.refused', $followUp, FollowUpState::Proposed->value,
                    'consent refused at approval: ['.$decision->refusal?->value.'] '.$decision->reason, $this->consentColumns($decision));

                return $followUp;
            }

            $followUp->transition(FollowUpState::Approved, $this->decision($by, $reason) + $this->consentColumns($decision));
            $this->auditPerson($by, 'follow_up.approved', $followUp, FollowUpState::Proposed->value, $reason, ['consent_event_id' => $decision->eventId]);

            $key = self::instanceKey($followUp);
            $now = CarbonImmutable::now();
            $run = $this->workflows->start(FollowUpWorkflow::TYPE, $key, [
                'follow_up_uuid' => $followUp->uuid,
                'customer_id' => $followUp->customer_id,
                'business_id' => $followUp->business_id,
                'due_at' => $followUp->due_at->toIso8601String(),
                'seconds_until_due' => max(0, (int) $now->diffInSeconds($followUp->due_at, false)),
            ], $by, $followUp->business_id);

            $followUp->transition(FollowUpState::Scheduled, [
                'workflow_type' => FollowUpWorkflow::TYPE, 'workflow_instance_key' => $key, 'workflow_run_uuid' => $run->runId,
            ]);
            $this->auditPerson($by, 'follow_up.scheduled', $followUp, FollowUpState::Approved->value, null, [
                'workflow_type' => FollowUpWorkflow::TYPE, 'workflow_instance_key' => $key, 'workflow_run_uuid' => $run->runId,
            ]);

            return $followUp;
        });
    }

    public function declineFollowUp(FollowUp $followUp, User $by, string $reason): FollowUp
    {
        $this->decider($by, $followUp);
        $reason = $this->requiredReason($reason);
        $from = $followUp->state;
        $followUp->transition(FollowUpState::Declined, $this->decision($by, $reason));
        $this->auditPerson($by, 'follow_up.declined', $followUp, $from->value, $reason);

        return $followUp;
    }

    /** The person did the follow-up themselves (by whatever means they chose) and says so. */
    public function completeFollowUp(FollowUp $followUp, User $by, string $note): FollowUp
    {
        $this->decider($by, $followUp);
        $note = $this->requiredReason($note);
        $from = $followUp->state;

        DB::transaction(function () use ($followUp, $by, $note, $from) {
            $followUp->transition(FollowUpState::Done, [
                'closed_by_user_id' => $by->getKey(), 'closed_at' => CarbonImmutable::now(), 'close_note' => $note,
            ]);
            $this->auditPerson($by, 'follow_up.done', $followUp, $from->value, $note);
            if ($followUp->workflow_instance_key !== null) {
                // The run is only the timer; a finished (timed-out) run simply takes no signal.
                $this->workflows->signal($followUp->workflow_instance_key, FollowUpWorkflow::SIGNAL_PERSON_DECIDED,
                    ['decision' => 'done', 'by_user_id' => $by->getKey()], 'follow_up:'.$followUp->uuid.':done');
            }
            // docs/113 section 10: the S3 seam. Part 1's binding does nothing.
            $this->outcomes->afterCompleted($followUp, $by);
        });

        return $followUp;
    }

    public function cancelFollowUp(FollowUp $followUp, User $by, string $reason): FollowUp
    {
        $this->decider($by, $followUp);
        $reason = $this->requiredReason($reason);
        $from = $followUp->state;

        DB::transaction(function () use ($followUp, $by, $reason, $from) {
            $followUp->transition(FollowUpState::Cancelled, [
                'closed_by_user_id' => $by->getKey(), 'closed_at' => CarbonImmutable::now(), 'close_note' => $reason,
            ]);
            $this->auditPerson($by, 'follow_up.cancelled', $followUp, $from->value, $reason);
            if ($followUp->workflow_instance_key !== null) {
                $this->workflows->cancel($followUp->workflow_instance_key, $by, "follow-up {$followUp->uuid} cancelled");
            }
        });

        return $followUp;
    }

    public static function instanceKey(FollowUp $followUp): string
    {
        return 'follow_up:'.$followUp->uuid;
    }

    // ---------------------------------------------------------------- guards

    /** Who may propose (docs/113 section 4) and whether what they name belongs together (section 5). Fail closed. */
    private function proposable(Proposer $by, Customer $customer, ?int $brandId, ?int $conversationId, array $evidence): Customer
    {
        if ($by->isPerson()) {
            if (! $by->user->is_active || ! $by->user->can(self::PERMISSION_PROPOSE)) {
                throw new AuthorizationException('Proposing a follow-up or opportunity needs '.self::PERMISSION_PROPOSE.'.');
            }
            if (! $this->scope->allows($by->user, $customer->business_id)) {
                throw new AuthorizationException('This customer belongs to a business outside your scope.');
            }
        } else {
            $runBusiness = $by->run->business_id;
            if ($runBusiness !== null && (int) $runBusiness !== (int) $customer->business_id) {
                throw new InvalidArgumentException("Agent run {$by->run->uuid} belongs to business {$runBusiness}, not the customer's {$customer->business_id}.");
            }
        }

        $canonical = $customer->canonical();
        if ($canonical->id !== $customer->id) {
            throw new InvalidArgumentException("Customer {$customer->id} was merged into {$canonical->id}; propose for the canonical customer.");
        }
        if ($brandId !== null) {
            $brand = Brand::query()->find($brandId);
            if ($brand === null || (int) $brand->business_id !== (int) $customer->business_id) {
                throw new InvalidArgumentException("Brand {$brandId} does not belong to business {$customer->business_id}.");
            }
        }
        if ($conversationId !== null) {
            $conversation = Conversation::query()->find($conversationId);
            if ($conversation === null || (int) $conversation->business_id !== (int) $customer->business_id) {
                throw new InvalidArgumentException("Conversation {$conversationId} does not belong to business {$customer->business_id}.");
            }
        }
        if (strlen((string) json_encode($evidence, JSON_UNESCAPED_UNICODE)) > self::EVIDENCE_MAX_BYTES) {
            throw new InvalidArgumentException('Evidence holds identifiers, not copies: at most 16 KB.');
        }

        return $customer;
    }

    private function decider(User $by, FollowUp|Opportunity $row): void
    {
        if (! $by->is_active || ! $by->can(self::PERMISSION_DECIDE)) {
            throw new AuthorizationException('Deciding on a follow-up or opportunity needs '.self::PERMISSION_DECIDE.'.');
        }
        if (! $this->scope->allows($by, $row->business_id)) {
            throw new AuthorizationException('This record belongs to a business outside your scope.');
        }
    }

    /** The S7 answer, through the one method the Tool Gateway itself uses (docs/113 section 6). */
    private function consent(Customer $customer, Channel $channel, FollowUpKind $kind, ?int $brandId): ConsentDecision
    {
        return $this->ledger->permits($customer->id, $customer->business_id, $channel->value, $kind->consentPurpose(), $brandId);
    }

    /** @return array<string, mixed> */
    private function consentColumns(ConsentDecision $decision): array
    {
        return [
            'consent_checked_at' => CarbonImmutable::now(),
            'consent_permitted' => $decision->permitted,
            'consent_refusal' => $decision->permitted ? null : $decision->refusal?->value,
            'consent_event_id' => $decision->eventId,
        ];
    }

    /** @return array<string, mixed> */
    private function decision(User $by, ?string $reason): array
    {
        return ['decided_by_user_id' => $by->getKey(), 'decided_at' => CarbonImmutable::now(), 'decision_reason' => $reason === null ? null : mb_substr(trim($reason), 0, 1000)];
    }

    private function requiredReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required.');
        }

        return mb_substr($reason, 0, 1000);
    }

    /** @param  array<string, mixed>  $after */
    private function audit(Proposer $by, string $action, Model $target, array $after, ?string $reason = null): void
    {
        if ($by->isPerson()) {
            $this->auditor->record($action, $target, after: $after, reason: $reason, actorUserId: $by->user->getKey());
        } else {
            $this->auditor->recordAgent($by->run->agent_key, $action, $target, after: $after + ['agent_run_uuid' => $by->run->uuid, 'proposal_ref' => $by->proposalRef], reason: $reason);
        }
    }

    /** @param  array<string, mixed>  $extra */
    private function auditPerson(User $by, string $action, FollowUp|Opportunity $target, string $fromState, ?string $reason, array $extra = []): void
    {
        $this->auditor->record($action, $target, before: ['state' => $fromState], after: ['state' => $target->state->value] + $extra,
            reason: $reason, actorUserId: $by->getKey());
    }
}
