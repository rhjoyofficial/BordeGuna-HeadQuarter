<?php

namespace App\Gateway\Descriptor;

/**
 * Who may run this particular call, right now. DERIVED per call by the policy layer from the
 * tool's risk tier, the actor, the business, the current guardrails and the environment (ADR-017).
 *
 * These are the three names docs/11 and docs/13 already use, kept so those documents stay readable.
 * The difference is that they are now an outcome rather than a static label on a tool.
 *
 * HumanOnly is not produced by policy. It is the reading of a tool an agent may not execute: a human
 * may run it, an agent may only propose it, and no approval can lift that. It is checked before
 * everything else for exactly that reason.
 *
 * ⛔ HumanOnly has TWO causes and they must stay distinguishable (ADR-025). A tool declaring
 * `agentExecutable: false` is not yet cleared, which evaluation evidence can one day end. A tool
 * declaring `permanentlyHumanOnly: true` may never be run by an agent at all. They refuse
 * identically and they are not the same statement, and the day somebody widens autonomy across
 * fifty tools is the day that difference has to already be written down.
 */
enum ActionClass: string
{
    case Auto = 'auto';
    case ApprovalRequired = 'approval_required';
    case HumanOnly = 'human_only';
}
