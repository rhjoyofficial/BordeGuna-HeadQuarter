<?php

namespace App\Gateway\Policy;

use App\Gateway\Descriptor\ActionClass;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\ToolRequest;
use App\Models\Business;
use App\Support\Features\Features;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The deterministic policy engine (docs/48 F, ADR-004, ADR-017).
 *
 * This replaces M1's minimal PolicyGate. It is model-free, versioned, reproducible and testable, and
 * it reads a tool key, typed arguments and an identity. **It never reads prose.** That is the whole
 * prompt-injection defence: enforcement lives downstream of the model and cannot be addressed.
 *
 * The evaluation order is not arbitrary and must not be casually reordered. Each step is placed so
 * that the cheapest and most certain refusals happen before anything expensive, and every one of
 * them happens before a credential is resolved:
 *
 *   1. Kill switches            - global freeze, paused agent, inactive business
 *   2. agent_executable = false - unliftable by any approval or configuration
 *   3. Environment guard        - a write tool outside production
 *   4. Permission intersection  - the acting identity's authority
 *   5. Risk tier baseline       - R0-R3 allow, R4-R5 require approval (decision V.1)
 *   6. Tool policy class        - may only make it STRICTER
 *   7. Guardrail rules          - from the versioned policies table
 *   8. Budgets and rate limits
 *   9. Default deny
 */
final class PolicyEngine
{
    /** The flag that stops every tool in HQ without a deploy. */
    public const FREEZE_FLAG = 'ai.freeze';

    public function __construct(
        private readonly Features $features,
        private readonly PolicyRepository $policies,
        private readonly BudgetLedger $budgets,
    ) {}

    /**
     * @param  array<string, mixed>  $args  the VALIDATED arguments
     * @param  int|null  $businessId  the EFFECTIVE business, already resolved from context or arguments
     */
    public function decide(
        ToolDescriptor $descriptor,
        ToolRequest $request,
        ActingIdentity $identity,
        array $args = [],
        ?int $businessId = null,
    ): PolicyDecision {
        $now = CarbonImmutable::now();

        // 1. Kill switches.
        if ($this->features->enabled(self::FREEZE_FLAG, $businessId)) {
            return PolicyDecision::deny('kill_switch.freeze', 'The global AI freeze flag is on: every tool is refused until it is cleared.');
        }
        if ($identity->isAgent() && $identity->agent !== null && ! $identity->agent->isActive()) {
            return PolicyDecision::deny('kill_switch.agent_paused', "Agent [{$identity->agent->key}] is not active.");
        }
        if ($businessId !== null && Business::whereKey($businessId)->where('is_active', false)->exists()) {
            return PolicyDecision::deny('kill_switch.business_inactive', 'That business is marked inactive.');
        }

        // 2. Not agent-executable. Checked before anything that could be relaxed, because no
        //    approval and no configuration may ever lift it (ADR-017).
        //
        //    The refusal names WHICH of the two reasons applies (ADR-025). Both refuse identically;
        //    they differ in what a reader should do next. "Not yet" is answered by evaluation
        //    evidence. "Permanently" is answered by nothing, and a request to lift it is a request
        //    to change what the tool is.
        if ($identity->isAgent() && ($refusal = $descriptor->agentRefusalReason()) !== null) {
            return PolicyDecision::deny(
                $descriptor->permanentlyHumanOnly ? 'action_class.human_only_permanent' : 'action_class.human_only',
                $refusal,
            );
        }

        // 3. Environment guard. HQ has no staging environment today, so this is a guard put in
        //    place before it is needed rather than one doing work now.
        if ($descriptor->readWrite === ReadWrite::Write && app()->environment('staging')) {
            return PolicyDecision::deny(
                'environment.write_outside_production',
                "[{$descriptor->key}] writes, and write tools are refused outside production.",
            );
        }

        // 4. Permission: the intersection, with no fallback branch.
        if (! $identity->holds($descriptor->permission, $descriptor->key)) {
            return PolicyDecision::deny(
                'permission.denied',
                "{$identity->label} does not hold [{$descriptor->permission}] for [{$descriptor->key}].",
            );
        }

        // 5. The intrinsic risk tier baseline. Decision V.1 (8 Sep 2026): every R4 and R5 action
        //    requires human approval in Phase 2, with no value threshold.
        $decision = match ($descriptor->riskTier->baseline()) {
            ActionClass::Auto => PolicyDecision::allow('risk_tier.baseline', "{$descriptor->riskTier->label()}: no approval required."),
            ActionClass::ApprovalRequired => PolicyDecision::requireApproval(
                'risk_tier.baseline',
                "{$descriptor->riskTier->label()}: human approval is required for every call at this tier (decision V.1).",
            ),
            default => PolicyDecision::deny('default.fail_closed', 'No policy classified this call, so it is refused.'),
        };

        // 6. The tool's own policy class. It may only tighten.
        $decision = $this->applyToolPolicy($descriptor, $request, $args, $identity, $decision);

        // 7. Guardrail rules from the versioned policies table.
        $policy = $this->policies->forCall($descriptor->key, $businessId, $now);
        if ($policy !== null) {
            $rules = new PolicyRules($policy->rules ?? []);

            // An unrecognised rule key is a configuration error, and a rule that silently does
            // nothing is worse than no rule: somebody believes it is protecting them.
            if (($unknown = $rules->unknownKeys()) !== []) {
                return PolicyDecision::deny(
                    'policy.unknown_rule',
                    "Policy [{$policy->key}] v{$policy->version} uses rule keys this engine does not implement: ".implode(', ', $unknown).'.',
                )->governedBy($policy);
            }

            $decision = $this->applyGuardrails($decision, $rules, $descriptor, $args, $now)->governedBy($policy);
        }

        if ($decision->outcome !== PolicyOutcome::Allow) {
            return $decision;
        }

        // 8. Budgets and rate limits. Only reached by a call that would otherwise proceed, so a
        //    refused call never consumes somebody's quota.
        if ($policy !== null) {
            $rate = $this->budgets->checkCallRate($descriptor->key, $businessId, new PolicyRules($policy->rules ?? []));
            if (! $rate['allowed']) {
                return PolicyDecision::deny('budget.rate_limit', (string) $rate['reason'])->governedBy($policy);
            }
        }

        $agentBudget = $this->budgets->checkAgentBudget($identity->agent);
        if ($agentBudget !== null && ! $agentBudget['allowed']) {
            return PolicyDecision::deny('budget.agent', (string) $agentBudget['reason'])->governedBy($policy);
        }

        return $decision;
    }

    /**
     * Records a permitted call against its rate counters. Separated from decide() so that a call
     * refused later in the gateway (a missing credential, say) does not consume quota either.
     */
    public function recordPermittedCall(ToolDescriptor $descriptor, ?int $businessId): void
    {
        $this->budgets->recordCall($descriptor->key, $businessId);
    }

    private function applyToolPolicy(
        ToolDescriptor $descriptor,
        ToolRequest $request,
        array $args,
        ActingIdentity $identity,
        PolicyDecision $baseline,
    ): PolicyDecision {
        if ($descriptor->policyClass === null) {
            return $baseline;
        }

        try {
            $policy = app($descriptor->policyClass);
            if (! $policy instanceof ToolPolicy) {
                Log::error('policy: declared class does not implement ToolPolicy', ['tool' => $descriptor->key, 'class' => $descriptor->policyClass]);

                return PolicyDecision::deny('policy.class_invalid', "[{$descriptor->key}] declares a policy class that is not a ToolPolicy.");
            }
            $proposed = $policy->evaluate($descriptor, $request, $args, $identity, $baseline);
        } catch (Throwable $e) {
            // A policy class that throws must not open the gate.
            Log::error('policy: tool policy class threw', ['tool' => $descriptor->key, 'exception' => $e]);

            return PolicyDecision::deny('policy.class_failed', "[{$descriptor->key}]'s policy class failed, so the call is refused.");
        }

        // It may only tighten. An attempt to loosen is ignored and recorded rather than obeyed.
        if ($proposed->isStricterThan($baseline)) {
            return $proposed;
        }
        if ($proposed->outcome !== $baseline->outcome) {
            Log::warning('policy: tool policy class tried to loosen the baseline and was ignored', [
                'tool' => $descriptor->key,
                'baseline' => $baseline->outcome->value,
                'proposed' => $proposed->outcome->value,
            ]);
        }

        return $baseline;
    }

    private function applyGuardrails(
        PolicyDecision $decision,
        PolicyRules $rules,
        ToolDescriptor $descriptor,
        array $args,
        CarbonImmutable $now,
    ): PolicyDecision {
        // Tightening first: a guardrail that refuses wins over everything below it.
        if ($rules->denies()) {
            return PolicyDecision::deny('guardrail.deny', $rules->denyReason());
        }

        if (($problem = $rules->argumentProblem($args)) !== null) {
            return PolicyDecision::deny('guardrail.arguments', $problem);
        }

        if ($rules->inQuietHours($now->setTimezone(config('hq.timezone', 'Asia/Dhaka')))) {
            return PolicyDecision::deny('guardrail.quiet_hours', 'This policy forbids that tool during its quiet hours.');
        }

        if ($rules->requiresApproval() && $decision->outcome === PolicyOutcome::Allow) {
            return PolicyDecision::requireApproval('guardrail.require_approval', $rules->reason());
        }

        if ($rules->dryRunOnly() && $decision->strictness() < 2) {
            return PolicyDecision::dryRunOnly('guardrail.dry_run_only', $rules->reason());
        }

        // Loosening, the one narrow path: explicit, reasoned, audited, and never for R5.
        if ($decision->outcome === PolicyOutcome::RequireApproval && $rules->relaxesTo($descriptor->riskTier) === 'allow') {
            return PolicyDecision::allow(
                'guardrail.relaxed',
                "{$descriptor->riskTier->label()} relaxed to automatic by an explicit guardrail: {$rules->relaxReason()}",
            );
        }

        return $decision;
    }

    /**
     * Used by the completeness test and the registry screen: the decision a tool would receive under
     * a default valid context, so an unclassified tool is caught in CI rather than by production.
     */
    public function baselineFor(ToolDescriptor $descriptor): PolicyDecision
    {
        return match ($descriptor->riskTier->baseline()) {
            ActionClass::Auto => PolicyDecision::allow('risk_tier.baseline', $descriptor->riskTier->label()),
            ActionClass::ApprovalRequired => PolicyDecision::requireApproval('risk_tier.baseline', $descriptor->riskTier->label()),
            default => PolicyDecision::deny('default.fail_closed', 'unclassified'),
        };
    }

    /** True when the tier is one M2 cannot execute at all, because approvals arrive in M3. */
    public function needsApprovalSystem(RiskTier $tier): bool
    {
        return $tier->writesOutsideHq();
    }
}
