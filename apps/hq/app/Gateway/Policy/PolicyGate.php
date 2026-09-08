<?php

namespace App\Gateway\Policy;

use App\Gateway\Descriptor\ActionClass;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\ToolRequest;
use App\Support\Features\Features;

/**
 * The M1 policy gate: the deterministic checks the gateway genuinely needs today, in the order the
 * full engine will keep, using the vocabulary the full engine will keep.
 *
 * ⚠ THIS IS NOT THE M2 POLICY ENGINE. It has no `policies` table, no per-business guardrail rules,
 * no value thresholds, no budgets, no quiet hours and no per-tool policy classes. Those arrive in
 * M2 with their own table-driven tests. What is here is the part that would be unsafe to leave out
 * of a gateway that already executes tools, written so that M2 extends it rather than replaces it.
 *
 * Two properties are load-bearing and must survive M2:
 *
 *  - It is deterministic and takes no model output. It reads a tool key, typed arguments and an
 *    identity. It cannot be talked to, which is why a prompt injection cannot escalate anything.
 *  - It fails closed. The final branch is a denial, not an approval request (docs/48 A.4): a call
 *    nobody classified must not reach a human as a plausible-looking approval card.
 */
final class PolicyGate
{
    /** The flag that stops every tool in HQ without a deploy. */
    public const FREEZE_FLAG = 'ai.freeze';

    public function __construct(private readonly Features $features) {}

    public function decide(ToolDescriptor $descriptor, ToolRequest $request, ActingIdentity $identity): PolicyDecision
    {
        // 1. Kill switches. Cheapest and most certain refusal first.
        if ($this->features->enabled(self::FREEZE_FLAG, $request->businessId)) {
            return PolicyDecision::deny('kill_switch.freeze', 'The global AI freeze flag is on: every tool is refused until it is cleared.');
        }

        // 2. agent_executable = false. Checked before anything else that could be relaxed, because
        //    no approval and no configuration may ever lift it. A human may run such a tool; an
        //    agent may propose it and must then hand it to a person.
        if ($identity->isAgent() && ! $descriptor->agentExecutable) {
            return PolicyDecision::deny(
                'action_class.human_only',
                "[{$descriptor->key}] is not agent-executable. An agent may propose it; a human must perform it.",
            );
        }

        // 3. Environment guard. A staging HQ must never write to the production systems of record,
        //    and HQ has no staging environment today, so this is a guard being put in place before
        //    it is needed rather than one that is doing work now.
        if ($descriptor->readWrite === ReadWrite::Write && app()->environment('staging')) {
            return PolicyDecision::deny(
                'environment.write_outside_production',
                "[{$descriptor->key}] writes, and write tools are refused outside production.",
            );
        }

        // 4. Risk tier baseline (ADR-017). R0 to R3 proceed; R4 and R5 require a human.
        //    Joy's V.1 decision, 8 September 2026: every R4 and R5 action requires approval in
        //    Phase 2, with no value threshold. M2 is where a guardrail rule may relax an R4, and
        //    R5 is never relaxable to automatic by configuration alone.
        $baseline = $descriptor->riskTier->baseline();

        return match ($baseline) {
            ActionClass::Auto => PolicyDecision::allow(
                'risk_tier.baseline',
                "{$descriptor->riskTier->label()}: no approval required.",
            ),
            ActionClass::ApprovalRequired => PolicyDecision::requireApproval(
                'risk_tier.baseline',
                "{$descriptor->riskTier->label()}: human approval is required for every call at this tier (decision V.1).",
            ),
            // 5. Anything unclassified is refused, never escalated to a person.
            default => PolicyDecision::deny('default.fail_closed', 'No policy classified this call, so it is refused.'),
        };
    }
}
