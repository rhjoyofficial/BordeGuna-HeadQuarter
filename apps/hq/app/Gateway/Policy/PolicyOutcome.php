<?php

namespace App\Gateway\Policy;

/**
 * The five outcomes a policy evaluation can produce (docs/48 F.1).
 *
 * docs/12 listed a sixth, `require_additional_permission`. It is deliberately absent: "you lack a
 * permission" is a denial with a reason, and giving it its own outcome invites a code path that
 * tries to acquire the permission mid-call.
 */
enum PolicyOutcome: string
{
    case Allow = 'allow';
    case RequireApproval = 'require_approval';
    case Deny = 'deny';

    /** Run the tool's preview path, never its effect. */
    case DryRunOnly = 'dry_run_only';

    /** A second click in the same session. Meaningless for an agent, and denied if one triggers it. */
    case RequireConfirmation = 'require_confirmation';
}
