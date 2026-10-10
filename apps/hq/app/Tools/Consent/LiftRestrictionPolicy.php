<?php

namespace App\Tools\Consent;

use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\Policy\PolicyDecision;
use App\Gateway\Policy\ToolPolicy;
use App\Gateway\ToolRequest;

/**
 * docs/114 §3 (ruling (k)): every call to `consent.lift_restriction` parks for a human decision.
 *
 * The tool is R3 (it writes HQ's own ledger), and R3's baseline is "allow". A tool policy may only
 * tighten, and this one always does: the lift of a do-not-contact is a two-person act by design,
 * not by tier, so no risk-tier reading and no guardrail row can make it a one-person act. The
 * requester is barred from deciding by the descriptor (`requesterMayDecide: false`), and the
 * approver's own permission is re-checked when the approved call runs under their identity.
 */
final class LiftRestrictionPolicy implements ToolPolicy
{
    public const RULE = 'consent.two_person_lift';

    public function evaluate(ToolDescriptor $descriptor, ToolRequest $request, array $args, ActingIdentity $identity, PolicyDecision $baseline): PolicyDecision
    {
        return PolicyDecision::requireApproval(
            self::RULE,
            'Lifting a do-not-contact needs a second, independent person holding ['.LiftRestrictionTool::PERMISSION.'] to approve it (docs/114 §3).',
        );
    }
}
