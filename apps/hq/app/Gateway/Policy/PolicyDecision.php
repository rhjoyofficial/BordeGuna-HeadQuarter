<?php

namespace App\Gateway\Policy;

/**
 * One decision, with the reason it was reached and the rule that reached it.
 *
 * The rule key matters as much as the outcome. A denial nobody can trace back to a named rule is a
 * denial nobody can argue with or fix, and in M2 `tool_calls` will point at the exact policy
 * version that produced it.
 */
final readonly class PolicyDecision
{
    public function __construct(
        public PolicyOutcome $outcome,
        public string $reason,
        public string $rule,
    ) {}

    public static function allow(string $rule, string $reason = 'allowed'): self
    {
        return new self(PolicyOutcome::Allow, $reason, $rule);
    }

    public static function deny(string $rule, string $reason): self
    {
        return new self(PolicyOutcome::Deny, $reason, $rule);
    }

    public static function requireApproval(string $rule, string $reason): self
    {
        return new self(PolicyOutcome::RequireApproval, $reason, $rule);
    }

    public function allowed(): bool
    {
        return $this->outcome === PolicyOutcome::Allow;
    }
}
