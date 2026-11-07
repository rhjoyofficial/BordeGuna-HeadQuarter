<?php

namespace App\Execution;

use RuntimeException;

/**
 * No effective authority could be resolved, so nothing runs (ADR-047).
 *
 * The message is one of HQ's own sentences about ids, agent keys and permission names. It carries
 * nothing a model wrote and nothing secret, so a surface may show it to the person who asked.
 *
 * Ruling 1 Nov 2026 (reason codes): the refusal also carries a stable canonical code, so a Bangla screen
 * shows an approved Bangla sentence for it instead of the English one. A refusal site that names no code
 * carries `execution_context_refused`, which the screens read as "refused, details for operators".
 */
final class ExecutionContextRefused extends RuntimeException
{
    public const GENERIC = 'execution_context_refused';

    public const AGENT_NOT_ACTIVE = 'agent_not_active';

    public const AGENT_NO_ENABLER = 'agent_no_enabler';

    public const AGENT_ENABLER_MISSING = 'agent_enabler_missing';

    public const AGENT_ENABLER_DISABLED = 'agent_enabler_disabled';

    public const REQUESTER_MISSING = 'requester_missing';

    public const REQUESTER_UNACCOUNTABLE = 'requester_unaccountable';

    public const REQUESTER_GONE = 'requester_gone';

    public const REQUESTER_DISABLED = 'requester_disabled';

    public const NO_SHARED_BUSINESS = 'scope_no_shared_business';

    public const BUSINESS_OUTSIDE_SCOPE = 'business_outside_scope';

    public const NO_COMMON_TOOL_PERMISSIONS = 'no_common_tool_permissions';

    public function __construct(string $message = '', public readonly string $reasonCode = self::GENERIC)
    {
        parent::__construct($message);
    }
}
