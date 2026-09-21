<?php

namespace App\Execution;

use App\Models\ScheduledJob;
use App\Models\ShadowPeriod;
use App\Models\User;

/**
 * Who is asking for an agent run, stated by the entry point and believed by nobody until the
 * resolver has read it back from the database (docs/80 point 3, ADR-047).
 *
 * ⛔ There is no system requester, and no requester with authority of its own. "Agents borrow a
 * person's authority" applies to the asking side exactly as it does to the enabling side: a
 * scheduled or system invocation asks ON BEHALF OF the accountable person recorded on the job or on
 * the shadow period, and its authority is that person's.
 *
 * ⚠ `asEnabled` is the honest statement for an entry point where no separate person exists: the
 * gate runner, a console with no `--as`, a shadow period opened with no `--by`. It names nobody new.
 * The accountable human is then the agent's enabling user, so the requester term of the
 * intersection equals the enabling term and adds nothing to it. It can never widen a run, because
 * an intersection with itself is itself.
 */
final readonly class Requester
{
    private function __construct(
        public RequesterKind $kind,
        public ?int $userId,
        public bool $asEnabled,
        /** Where the entry point read this from, for the record: `ask_conversations#4.user_id`. */
        public string $basis,
    ) {}

    /** A signed-in person asked. */
    public static function human(?User $user, string $basis): self
    {
        return new self(RequesterKind::Human, $user?->getKey(), false, $basis);
    }

    /**
     * The scheduler or the system, for the accountable person recorded on the thing it serves.
     * A null person is NOT a wildcard: the resolver refuses it.
     */
    public static function onBehalfOf(?int $accountableUserId, string $basis): self
    {
        return new self(RequesterKind::OnBehalfOf, $accountableUserId, false, $basis);
    }

    /** No separate person exists at this entry point; see the class comment. */
    public static function asEnabled(RequesterKind $kind, string $basis): self
    {
        return new self($kind, null, true, $basis);
    }

    /**
     * A Shadow Probe asks for the person who opened the period. Off production a period opened with
     * no `--by` recorded nobody, and runs as enabled, which is what it effectively did before; the
     * record says which of the two it was. In production there is no such period (docs/81).
     */
    public static function forShadowPeriod(ShadowPeriod $period): self
    {
        if ($period->opened_by_user_id !== null) {
            return self::onBehalfOf((int) $period->opened_by_user_id, "shadow_periods#{$period->id}.opened_by_user_id");
        }

        // docs/81 points 11 and 12: in production nobody is an implicit opener. `hq:shadow:open`
        // refuses to write such a period there; one written around it names nobody accountable,
        // and the resolver refuses a requester who names nobody.
        if (app()->isProduction()) {
            return self::onBehalfOf(null, "shadow_periods#{$period->id} recorded no opener, and a production period must name one");
        }

        return self::asEnabled(RequesterKind::OnBehalfOf, "shadow_periods#{$period->id} recorded no opener; the agent's enabling user is the accountable person");
    }

    /**
     * A scheduled job that starts an agent run asks for its owner. A job with no owner names
     * nobody accountable, and the resolver refuses it (docs/48 D.5: anything wider than the narrow
     * system reads must name an owner). An agent run is never one of the narrow system reads.
     */
    public static function forScheduledJob(ScheduledJob $job): self
    {
        return self::onBehalfOf(
            $job->owner_user_id === null ? null : (int) $job->owner_user_id,
            "scheduled_jobs#{$job->id}.owner_user_id",
        );
    }
}
