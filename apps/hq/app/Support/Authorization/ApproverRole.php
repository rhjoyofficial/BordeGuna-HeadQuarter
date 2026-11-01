<?php

namespace App\Support\Authorization;

use App\Models\User;

/**
 * The least-privilege second approver (ChatGPT architecture ruling, 1 Nov 2026; docs/149).
 *
 * A person holding the `approver` role is a CATEGORY-SCOPED decider. On top of every rule that already applies to
 * anybody (separation of duties, delegation, Do plan steps, tool-level `requesterMayDecide`), such a person:
 *
 *   1. decides only with confirmed two-factor authentication (the routes already demand it; this is the same rule at
 *      the decision itself, so a caller that skips the routes meets it too);
 *   2. is limited to named businesses: with no business scope rows an approver decides nothing (no rows would mean
 *      group-wide, which is exactly what this role must not be), and an item of a business outside the rows, or of no
 *      business at all, is not theirs;
 *   3. never decides what they asked for, enabled, proposed or started, whatever a tool otherwise allows;
 *   4. decides an Approval Center item only while holding that item's own domain permission (the tool descriptor's
 *      permission), which is assigned per person and never bundled into the role;
 *   5. never decides an item whose domain permission is one of {@see self::NEVER_ASSIGNABLE}, even if it was granted
 *      to them by mistake.
 *
 * Nothing here applies to anybody without the role, so every existing two-person and independent-approval rule for
 * owner, operator and the other roles is unchanged.
 */
final class ApproverRole
{
    public const NAME = 'approver';

    /**
     * Domain permissions an approver may never decide under, even when granted directly: they are user, credential,
     * flag, tool, autonomy, policy, connector, consent-grant or identity-repair authority (docs/149 section 4).
     */
    public const NEVER_ASSIGNABLE = [
        'hq.users.manage',
        'hq.credentials.view', 'hq.credentials.manage',
        'hq.flags.manage',
        'hq.tools.manage',
        'hq.policies.manage',
        'hq.agents.manage',
        'hq.autonomy.grant',
        'hq.connectors.view',
        'hq.consent.grant', 'hq.consent.lift_restriction',
        'hq.customers.quarantine.repair',
        'hq.customers.identity_conflicts.resolve',
        'hq.outcomes.attribute',
        'hq.media.budget.manage',
        'hq.ai.resume',
    ];

    public function __construct(private readonly BusinessScope $scope) {}

    public static function holds(User $user): bool
    {
        try {
            return $user->hasRole(self::NAME);
        } catch (\Throwable) {
            // The role row does not exist yet (seeder not run): nobody holds it.
            return false;
        }
    }

    /**
     * Why this approver may not decide an item of this business, or null when the role has nothing to say (including
     * for anybody who does not hold the role).
     *
     * @param  list<int|null>  $ownIds  the user ids that asked for, enabled, proposed or started the item
     */
    public function refusal(User $user, ?int $businessId, array $ownIds = [], ?string $domainPermission = null): ?string
    {
        if (! self::holds($user)) {
            return null;
        }
        if (! $user->hasTwoFactorEnabled()) {
            return 'An approver decides only with two-factor authentication confirmed.';
        }
        $businesses = $this->scope->all($user);
        if ($businesses === null) {
            return 'An approver must be limited to named businesses before deciding anything.';
        }
        if ($businessId === null || ! in_array($businessId, $businesses, true)) {
            return 'This item is not of a business you approve for.';
        }
        $own = array_map('intval', array_values(array_filter($ownIds, fn ($id) => $id !== null)));
        if (in_array((int) $user->getKey(), $own, true)) {
            return 'You asked for, proposed or started this, so somebody else decides it.';
        }
        if ($domainPermission !== null) {
            if (in_array($domainPermission, self::NEVER_ASSIGNABLE, true)) {
                return 'This kind of decision is never an approver\'s.';
            }
            if (! $user->can($domainPermission)) {
                return 'You are not assigned this kind of approval.';
            }
        }

        return null;
    }
}
