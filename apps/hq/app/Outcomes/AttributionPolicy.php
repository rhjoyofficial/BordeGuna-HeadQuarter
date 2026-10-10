<?php

namespace App\Outcomes;

/**
 * Which attribution methods the running contract version admits (docs/110 §3 Q3 and C6, docs/115 §5).
 *
 * v1 admits the three methods whose claims rest on something a person can check without a rule or a
 * model: key arithmetic, a shared correlation, or a named human with a reason. `rule_window` and
 * `model_inferred` stay in `AttributionMethod` with their confidence rules (the vocabulary is complete)
 * and are refused here (`AttributionMethodInactive`) until a rule or a model is admitted.
 *
 * ⭐ EXTENSION POINT, deliberately not built (Q3: "attribution rules belong in a versioned, human-governed
 *    policy registry"; "do not activate rule_window in v1"). When the first window rule or model
 *    judgement is admitted, this constant list becomes a read of the active policy:
 *
 *      - a registry of attribution policies, each row versioned (`attribution_policy.v2`, ...), naming
 *        the method it admits, the rule it defines (for a window: the role it grants, the window length,
 *        the action kinds it joins), a confidence ceiling, who approved it and when it is active, in the
 *        shape of the autonomy evidence policy HQ already governs by hand;
 *      - `AttributionSpec::methodVersion` names the policy version that made the claim, so every row
 *        can be re-read under the rule it was written by (D11 for rules);
 *      - `isActive()` answers from the registry for the claim's business and the time of the claim;
 *      - a policy is admitted by a reviewed change plus a human decision, never by configuration alone.
 *
 * Until then the policy is this class, versioned like the contract, and a change to it is a reviewed
 * change to code (the same altitude as `OutcomeTypes`).
 */
final class AttributionPolicy
{
    public const VERSION = 'attribution_policy.v1';

    /** @return list<AttributionMethod> the methods a v1 claim may be made by, in vocabulary order */
    public static function activeMethods(): array
    {
        return [AttributionMethod::DeterministicKey, AttributionMethod::Correlation, AttributionMethod::OperatorAsserted];
    }

    public static function isActive(AttributionMethod $method): bool
    {
        return in_array($method, self::activeMethods(), true);
    }

    /** Refuses a claim by a method the running policy does not admit; nothing is written after it. */
    public static function requireActive(AttributionMethod $method): void
    {
        if (! self::isActive($method)) {
            throw new AttributionMethodInactive(
                "Attribution refused: [{$method->value}] is not active under ".self::VERSION
                .' (v1 admits deterministic_key, correlation and operator_asserted; a window rule or a model judgement is admitted only by a versioned, human-governed attribution policy).'
            );
        }
    }
}
