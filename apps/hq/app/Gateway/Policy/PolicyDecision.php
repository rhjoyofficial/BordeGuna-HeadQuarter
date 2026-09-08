<?php

namespace App\Gateway\Policy;

use App\Gateway\Descriptor\ActionClass;
use App\Models\Policy;

/**
 * One decision, with the reason it was reached, the rule that reached it, and the policy version
 * that governed it.
 *
 * The rule key and the policy version matter as much as the outcome. A denial nobody can trace back
 * to a named rule in a known version is a denial nobody can argue with or fix.
 */
final readonly class PolicyDecision
{
    public function __construct(
        public PolicyOutcome $outcome,
        public string $reason,
        public string $rule,
        public ?int $policyId = null,
        public ?int $policyVersion = null,
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

    public static function dryRunOnly(string $rule, string $reason): self
    {
        return new self(PolicyOutcome::DryRunOnly, $reason, $rule);
    }

    public static function requireConfirmation(string $rule, string $reason): self
    {
        return new self(PolicyOutcome::RequireConfirmation, $reason, $rule);
    }

    public function governedBy(?Policy $policy): self
    {
        return new self($this->outcome, $this->reason, $this->rule, $policy?->id, $policy?->version);
    }

    public function withReason(string $rule, string $reason): self
    {
        return new self($this->outcome, $reason, $rule, $this->policyId, $this->policyVersion);
    }

    public function allowed(): bool
    {
        return $this->outcome === PolicyOutcome::Allow;
    }

    /**
     * Ordering used to answer "is this stricter?". A tool policy class may move a decision up this
     * scale and never down it.
     */
    public function strictness(): int
    {
        return match ($this->outcome) {
            PolicyOutcome::Allow => 0,
            PolicyOutcome::RequireConfirmation => 1,
            PolicyOutcome::DryRunOnly => 2,
            PolicyOutcome::RequireApproval => 3,
            PolicyOutcome::Deny => 4,
        };
    }

    public function isStricterThan(self $other): bool
    {
        return $this->strictness() > $other->strictness();
    }

    public function actionClass(): ActionClass
    {
        return match ($this->outcome) {
            PolicyOutcome::Allow => ActionClass::Auto,
            PolicyOutcome::RequireApproval, PolicyOutcome::RequireConfirmation, PolicyOutcome::DryRunOnly => ActionClass::ApprovalRequired,
            PolicyOutcome::Deny => ActionClass::HumanOnly,
        };
    }
}
