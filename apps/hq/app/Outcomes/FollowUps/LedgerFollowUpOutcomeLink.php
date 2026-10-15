<?php

namespace App\Outcomes\FollowUps;

use App\FollowUps\Outcomes\FollowUpOutcomeLink;
use App\Models\FollowUp;
use App\Models\Opportunity;
use App\Models\OutcomeAttribution;
use App\Models\User;
use App\Outcomes\ActionType;

/**
 * S4 on the ruled S3 contract (docs/123 section 1): the follow-up side of the ONE outcome model. No
 * second attribution model, no follow-up-specific credit column, no revenue figure (D3, D9).
 *
 * ⛔ Writes nothing. A follow-up is credited only through the human-only `outcomes.attribute` tool
 *    (`action_type` = `follow_up`, method `operator_asserted`, a reason and evidence), which reaches
 *    `OutcomeRecorder`; `afterCompleted` deliberately records nothing in v1 (ruling Q3: no
 *    `rule_window`; C7: a person's word is never laundered into a proof method).
 *
 * ⛔ Reads only the current view (D17): an attribution nothing superseded or retracted, on an outcome
 *    nothing superseded or retracted. Business-scoped twice (ruling Q7, D19): the viewer's
 *    `BusinessScope` through `visibleTo`, AND `business_id` = the follow-up's own business on both the
 *    attribution and the outcome. Nothing here reads another business's ledger rows.
 */
final class LedgerFollowUpOutcomeLink implements FollowUpOutcomeLink
{
    /** The outcome types that can support an opportunity a person closed as `converted`. */
    public const CONVERSION_TYPES = ['order.placed', 'order.confirmed', 'order.delivered'];

    public function afterCompleted(FollowUp $followUp, User $by): void
    {
        // Deliberately nothing (see the class comment). A person who knows what came of the follow-up
        // asserts it through `outcomes.attribute`; nothing here guesses it.
    }

    public function creditsFor(int $businessId, array $followUpIds, User $viewer): array
    {
        $ids = array_values(array_unique(array_map('intval', $followUpIds)));
        if ($ids === []) {
            return [];
        }

        $credits = [];
        foreach ($this->credits($businessId, $viewer)->whereIn('outcome_attributions.follow_up_id', $ids)->get() as $row) {
            $credits[(int) $row->follow_up_id][] = $this->present($row);
        }

        return $credits;
    }

    public function outcomesFor(Opportunity $opportunity, User $viewer): array
    {
        $businessId = (int) $opportunity->business_id;
        $rows = $this->credits($businessId, $viewer)
            ->whereIn('outcome_attributions.follow_up_id', FollowUp::query()
                ->where('opportunity_id', $opportunity->id)->where('business_id', $businessId)->select('id'))
            ->get();

        return $rows->map(fn (OutcomeAttribution $row) => $this->present($row))->values()->all();
    }

    public function supportsConversion(Opportunity $opportunity, User $viewer): bool
    {
        foreach ($this->outcomesFor($opportunity, $viewer) as $credit) {
            if (in_array($credit['outcome_type'], self::CONVERSION_TYPES, true)) {
                return true;
            }
        }

        return false;
    }

    /** Current follow-up credits of one business, on current outcomes of that business, inside the viewer's scope. */
    private function credits(int $businessId, User $viewer)
    {
        return OutcomeAttribution::query()->visibleTo($viewer)->current()
            ->where('outcome_attributions.business_id', $businessId)
            ->where('outcome_attributions.action_type', ActionType::FollowUp->value)
            ->whereHas('outcome', fn ($q) => $q->visibleTo($viewer)->current()->where('outcomes.business_id', $businessId))
            ->with(['outcome:id,uuid,business_id,outcome_type,occurred_at', 'outcome.results:id,outcome_id,result_type,role,source_system,external_ref'])
            ->orderBy('outcome_attributions.id');
    }

    /** @return array<string, mixed> identifiers and the claim; never an amount (D3) */
    private function present(OutcomeAttribution $row): array
    {
        return [
            'attribution_uuid' => $row->uuid,
            'outcome_uuid' => $row->outcome->uuid,
            'outcome_type' => $row->outcome->outcome_type,
            'occurred_at' => $row->outcome->occurred_at?->toIso8601String(),
            'role' => $row->role->value,
            'method' => $row->method->value,
            'confidence' => (float) $row->confidence,
            'results' => $row->outcome->results->map(fn ($r) => [
                'result_type' => $r->result_type->value,
                'role' => $r->role,
                'ref' => $r->source_system.':'.$r->external_ref,
            ])->values()->all(),
        ];
    }
}
