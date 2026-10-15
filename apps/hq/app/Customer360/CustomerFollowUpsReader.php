<?php

namespace App\Customer360;

use App\FollowUps\FollowUpOrigin;
use App\FollowUps\FollowUpProposalService;
use App\FollowUps\FollowUpState;
use App\FollowUps\OpportunityCloseReason;
use App\FollowUps\OpportunityState;
use App\FollowUps\Outcomes\FollowUpOutcomeLink;
use App\Models\Customer;
use App\Models\FollowUp;
use App\Models\Opportunity;
use App\Models\User;

/**
 * Customer 360's follow-ups section (docs/120 item 14, docs/123 section 2): this customer's follow-ups,
 * promises and opportunities, READ-ONLY and business-scoped.
 *
 * ⛔ READ-ONLY. Selects only; no event, no audit, no cache. The section carries no write control: deciding
 *    stays on the follow-ups screen, behind its own permission (docs/113 section 4).
 * ⛔ BUSINESS-SCOPED, three times: the viewer's `BusinessScope` (`visibleTo`), the customer's id, and
 *    `business_id` = the CUSTOMER's business on every row. A row carrying this customer's id under another
 *    business (which the service never writes) is still not shown.
 * ⛔ Shown only to a viewer holding `hq.followups.view`; otherwise the section is null and the page says
 *    which permission it needs (the inbox sections' rule, docs/109 section 3).
 *
 * Promises are follow-ups whose origin is `promise` (docs/113 section 2): not a separate ledger. What the
 * outcome ledger credits is read through the S4 seam, never computed here, and never an amount.
 */
final class CustomerFollowUpsReader
{
    public const LIMIT = 50;

    public function __construct(private readonly FollowUpOutcomeLink $ledger) {}

    /** @return array<string, mixed>|null */
    public function read(Customer $customer, User $viewer): ?array
    {
        if (! $viewer->can(FollowUpProposalService::PERMISSION_VIEW)) {
            return null;
        }

        $businessId = (int) $customer->business_id;
        $followUps = FollowUp::query()->visibleTo($viewer)
            ->where('follow_ups.customer_id', $customer->id)
            ->where('follow_ups.business_id', $businessId)
            ->orderByRaw("CASE WHEN state IN ('proposed', 'approved', 'scheduled') THEN 0 ELSE 1 END")
            ->orderBy('due_at')->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get(['id', 'uuid', 'business_id', 'brand_id', 'opportunity_id', 'origin', 'kind', 'channel', 'due_at', 'intent', 'state',
                'consent_refusal', 'decided_at', 'closed_at', 'close_note']);

        $done = $followUps->where('state', FollowUpState::Done)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $credits = $this->ledger->creditsFor($businessId, $done, $viewer);

        $opportunities = Opportunity::query()->visibleTo($viewer)
            ->where('opportunities.customer_id', $customer->id)
            ->where('opportunities.business_id', $businessId)
            ->orderByRaw("CASE WHEN state IN ('proposed', 'open') THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get(['id', 'uuid', 'business_id', 'kind', 'subject', 'expected_at', 'state', 'close_reason', 'closed_at']);

        $open = fn ($q) => $q->visibleTo($viewer)->where('customer_id', $customer->id)->where('business_id', $businessId)
            ->whereIn('state', [FollowUpState::Proposed->value, FollowUpState::Approved->value, FollowUpState::Scheduled->value]);

        return [
            'counts' => [
                'open' => $open(FollowUp::query())->count(),
                'open_promises' => $open(FollowUp::query())->where('origin', FollowUpOrigin::Promise->value)->count(),
                'due' => $open(FollowUp::query())->where('state', FollowUpState::Scheduled->value)->where('due_at', '<=', now())->count(),
                'active_opportunities' => Opportunity::query()->visibleTo($viewer)->where('customer_id', $customer->id)->where('business_id', $businessId)
                    ->whereIn('state', [OpportunityState::Proposed->value, OpportunityState::Open->value])->count(),
            ],
            'follow_ups' => $followUps->map(fn (FollowUp $f) => [
                'uuid' => $f->uuid,
                'state' => $f->state->value,
                'kind' => $f->kind->value,
                'origin' => $f->origin->value,
                'promise' => $f->origin === FollowUpOrigin::Promise,
                'channel' => $f->channel,
                'due_at' => $f->due_at->toIso8601String(),
                'due' => $f->isDue(),
                'intent' => $f->intent,
                'consent_refusal' => $f->consent_refusal?->value,
                'decided_at' => $f->decided_at?->toIso8601String(),
                'closed_at' => $f->closed_at?->toIso8601String(),
                'close_note' => $f->close_note,
                'ledger_credits' => $credits[$f->id] ?? [],
            ])->values()->all(),
            'opportunities' => $opportunities->map(fn (Opportunity $o) => [
                'uuid' => $o->uuid,
                'state' => $o->state->value,
                'kind' => $o->kind->value,
                'subject' => $o->subject,
                'expected_at' => $o->expected_at?->toDateString(),
                'close_reason' => $o->close_reason?->value,
                'closed_at' => $o->closed_at?->toIso8601String(),
                // docs/113 F4: `converted` is a person's word; the ledger only says whether it supports it.
                'conversion_supported' => $o->close_reason === OpportunityCloseReason::Converted ? $this->ledger->supportsConversion($o, $viewer) : null,
            ])->values()->all(),
        ];
    }
}
