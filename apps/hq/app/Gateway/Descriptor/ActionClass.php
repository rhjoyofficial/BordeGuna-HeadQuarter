<?php

namespace App\Gateway\Descriptor;

/**
 * Who may run this particular call, right now. DERIVED per call by the policy layer from the
 * tool's risk tier, the actor, the business, the current guardrails and the environment (ADR-017).
 *
 * These are the three names docs/11 and docs/13 already use, kept so those documents stay readable.
 * The difference is that they are now an outcome rather than a static label on a tool.
 *
 * HumanOnly is not produced by policy. It is the reading of a tool that declares
 * `agentExecutable: false`: a human may run it, an agent may only propose it, and no approval can
 * lift that. It is checked before everything else for exactly that reason.
 */
enum ActionClass: string
{
    case Auto = 'auto';
    case ApprovalRequired = 'approval_required';
    case HumanOnly = 'human_only';
}
