<?php

namespace App\Autonomy;

use App\Gateway\Descriptor\AutonomyLevel;
use App\Models\AutonomyGrant;
use Illuminate\Support\Carbon;

/**
 * How much an agent may do without a human, for one specific proposed action (ADR-020).
 *
 * ## Why this is not part of the Policy Engine
 *
 * The Policy Engine is FIRST MATCH WINS, and that is correct for "which rule governs this call":
 * one rule is the governing one, and merging would make it impossible to say which. Autonomy
 * combines by MINIMUM: the agent's cap, the tool's cap, the channel's cap, the compliance ceiling
 * and the evaluation-derived ceiling are all simultaneously true, and the effective level is the
 * lowest. Two different combinators cannot live in one component without one of them corrupting
 * the other, so they are two components.
 *
 * ## The rules, all of which fail closed
 *
 * - **Absence is A0.** No matching grant means no autonomy, not the benefit of the doubt. Note this
 *   is the OPPOSITE default to `tool_registrations`, where an absent row means enabled: an absent
 *   tool row means a capability the deployed code already contains and a human already reviewed,
 *   while an absent autonomy grant means a combination nobody has evaluated.
 * - **Minimum wins**, across grants and across ceilings alike.
 * - **HUMAN_ONLY is permanent and unliftable** (ADR-025). It is checked first, and no grant,
 *   ceiling or approval reaches past it.
 * - **Untrusted context caps autonomy** (ADR-024). Reading a customer's message may inform a
 *   proposal; it may never be the reason an action became automatic.
 * - **A5 is unreachable.** Whatever the grants say, the result is capped at A4.
 * - **The evidence policy is a ceiling, never a grant** (ADR-048). When one is active the runner
 *   passes what it allows on the measured evidence, and it is combined by minimum like the others.
 *   A grant naming a suite is compared with that suite's own figure, never the pooled one.
 *
 * ## What this class cannot do
 *
 * ⛔ It has no route to execution. It takes no gateway, no registry, no tool, no connector and no
 * credential, and it returns a LEVEL. A higher autonomy level means fewer human interruptions,
 * never a different code path: every call still goes through the Tool Gateway, the Policy Engine,
 * the permission intersection, the Approval Center, the credential boundary, the audit trail and
 * the idempotency controls. A test asserts the constructor signature stays empty of anything that
 * could execute.
 */
final class AutonomyResolver
{
    public function resolve(AutonomyRequest $request): AutonomyDecision
    {
        $decision = $this->decide($request);

        // ADR-048: the policy and the evidence the answer was judged under travel with it, on every
        // path (a floor included), and only when a policy was active.
        return $request->evidence === null ? $decision : $decision->withEvidence($request->evidence->record);
    }

    private function decide(AutonomyRequest $request): AutonomyDecision
    {
        $at = Carbon::instance(Carbon::parse($request->at ?? now()));
        $constraints = [];

        // 1. HUMAN_ONLY, before anything that could be relaxed is even loaded.
        $descriptor = $request->descriptor;
        if ($descriptor !== null && ($reason = $descriptor->agentRefusalReason()) !== null) {
            return AutonomyDecision::floor($reason);
        }

        // 2. The grants that actually apply.
        $grants = $this->matchingGrants($request, $at);
        if ($grants === []) {
            return AutonomyDecision::floor(
                'no autonomy grant matches this combination, and an unevaluated combination is A0 by default'
            );
        }

        $level = AutonomyLevel::A5;
        $grantIds = [];
        foreach ($grants as $grant) {
            $level = $level->min($grant->max_level);
            $grantIds[] = (int) $grant->id;
            $constraints[] = [
                'source' => "grant:{$grant->id}",
                'level' => $grant->max_level->value,
                'detail' => $grant->name,
            ];
        }
        $boundBy = 'the lowest matching grant';

        // 3. The ceilings, each of which can only lower the answer.
        foreach ($this->ceilings($request) as [$source, $ceiling, $detail]) {
            $constraints[] = ['source' => $source, 'level' => $ceiling->value, 'detail' => $detail];
            if ($ceiling->rank() < $level->rank()) {
                $level = $ceiling;
                $boundBy = $detail;
            }
        }

        // 4. A5 exists as a state and is never reachable.
        if ($level->rank() > AutonomyLevel::ceiling()->rank()) {
            $level = AutonomyLevel::ceiling();
            $boundBy = 'A5 is defined but permanently unreachable; capped at A4';
            $constraints[] = ['source' => 'ladder', 'level' => $level->value, 'detail' => $boundBy];
        }

        return new AutonomyDecision($level, $boundBy, $constraints, $grantIds);
    }

    /**
     * The ceilings that apply regardless of any grant.
     *
     * @return list<array{0: string, 1: AutonomyLevel, 2: string}>
     */
    private function ceilings(AutonomyRequest $request): array
    {
        $out = [];

        if ($request->agentCeiling !== null) {
            $out[] = ['agent', $request->agentCeiling, "the agent's own configured ceiling"];
        }

        if ($request->complianceCeiling !== null) {
            $out[] = ['compliance', $request->complianceCeiling, 'a compliance ceiling applies'];
        }

        // ADR-024. The cap is structural: it reads the provenance labels on the assembled context,
        // not the text, so no wording inside the untrusted content can affect it.
        $contextCeiling = $request->context?->ceiling();
        if ($contextCeiling !== null) {
            $out[] = ['context', $contextCeiling, 'the context contains untrusted content, which can never raise autonomy'];
        }

        // ADR-048. The autonomy evidence policy is one more ceiling, combined by minimum like the
        // rest: necessary, never sufficient. It cannot raise a level and it cannot stand in for a
        // grant, because it is only ever read after a grant has matched.
        if ($request->evidence !== null) {
            $out[] = ['evidence_policy', $request->evidence->ceiling, $request->evidence->detail()];
        }

        return $out;
    }

    /** @return list<AutonomyGrant> */
    private function matchingGrants(AutonomyRequest $request, Carbon $at): array
    {
        $candidates = AutonomyGrant::query()
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $at))
            ->where(fn ($q) => $q->whereNull('agent_key')->orWhere('agent_key', $request->agentKey))
            ->where(fn ($q) => $q->whereNull('business_id')->orWhere('business_id', $request->businessId))
            ->where(fn ($q) => $q->whereNull('brand_id')->orWhere('brand_id', $request->brandId))
            ->where(fn ($q) => $q->whereNull('channel')->orWhere('channel', $request->channel?->value))
            ->where(fn ($q) => $q->whereNull('tool_key')->orWhere('tool_key', $request->toolKey()))
            ->where(fn ($q) => $q->whereNull('environment')->orWhere('environment', $request->environment))
            ->where(fn ($q) => $q->whereNull('customer_segment')->orWhere('customer_segment', $request->customerSegment))
            ->where(fn ($q) => $q->whereNull('data_class')->orWhere('data_class', $request->dataClass?->value))
            ->orderBy('id')
            ->get();

        $matching = [];
        foreach ($candidates as $grant) {
            if (! $this->coversMonetaryImpact($grant, $request)) {
                continue;
            }
            if (! $this->withinTimeWindow($grant, $at)) {
                continue;
            }
            if (! $this->hasEvaluationEvidence($grant, $request)) {
                continue;
            }
            $matching[] = $grant;
        }

        return $matching;
    }

    /** A grant covering up to "low" does not apply to a call that moves a "high" amount. */
    private function coversMonetaryImpact(AutonomyGrant $grant, AutonomyRequest $request): bool
    {
        if ($grant->max_monetary_impact === null) {
            return true;
        }

        // The request not saying what it moves is not evidence that it moves nothing.
        if ($request->monetaryImpact === null) {
            return false;
        }

        return $request->monetaryImpact->rank() <= $grant->max_monetary_impact->rank();
    }

    /**
     * Business hours and days, in the grant's own timezone.
     *
     * The same action is a different risk at 03:00, when nobody would notice it for six hours.
     */
    private function withinTimeWindow(AutonomyGrant $grant, Carbon $at): bool
    {
        $window = $grant->time_window;
        if (! is_array($window) || $window === []) {
            return true;
        }

        $local = $at->copy()->setTimezone($window['timezone'] ?? config('app.timezone', 'UTC'));

        $days = $window['days'] ?? null;
        if (is_array($days) && $days !== [] && ! in_array((int) $local->dayOfWeek, array_map('intval', $days), true)) {
            return false;
        }

        $from = $window['from'] ?? null;
        $to = $window['to'] ?? null;
        if (is_string($from) && is_string($to)) {
            $minutes = $local->hour * 60 + $local->minute;

            return $minutes >= $this->minutes($from) && $minutes < $this->minutes($to);
        }

        return true;
    }

    /**
     * ADR-023: a grant may require measured evidence before it applies at all.
     *
     * This is the mechanical link between "we measured" and "it may act". A grant naming 0.95 does
     * not match until the evaluation evidence for that combination reaches 0.95, which is what
     * makes autonomy earned rather than granted on a schedule.
     */
    private function hasEvaluationEvidence(AutonomyGrant $grant, AutonomyRequest $request): bool
    {
        if ($grant->min_agreement_rate === null) {
            return true;
        }

        // ⭐ ADR-030: a grant may name the DIMENSION its evidence must come from. An overall rate
        // averages model reasoning, tool selection, policy, enforcement, execution and postcondition
        // together, and the dimension that matters for the action being unlocked may be the one
        // dragging the average up rather than the one being measured. Null keeps the old meaning.
        $measured = $grant->min_agreement_dimension === null
            ? $request->agreementRate
            : ($request->dimensionRates[$grant->min_agreement_dimension] ?? null);

        // ADR-048: a grant naming a suite is compared with that suite's own figure, never the pooled
        // one. A suite nobody measured does not borrow the rate of the suites somebody did.
        if ($grant->evaluation_suite_key !== null) {
            $suite = $request->suiteRates[$grant->evaluation_suite_key] ?? null;
            $measured = $suite === null ? null : ($grant->min_agreement_dimension === null
                ? ($suite['overall'] ?? null)
                : ($suite['dimensions'][$grant->min_agreement_dimension] ?? null));
        }

        // No measurement is not a passing measurement. That is doubly true here: a grant naming a
        // dimension nothing has ever measured must not fall back to the overall number, because
        // falling back is exactly how an unmeasured dimension gets treated as a measured one.
        if ($measured === null) {
            return false;
        }

        return $measured >= $grant->min_agreement_rate;
    }

    private function minutes(string $time): int
    {
        [$h, $m] = array_pad(array_map('intval', explode(':', $time)), 2, 0);

        return $h * 60 + $m;
    }
}
