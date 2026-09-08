<?php

namespace App\Gateway\Approval;

/**
 * The approval lifecycle (docs/48 G.3).
 *
 * Transitions are explicit and one-way. The states that matter most are the two terminal ones a
 * reader might confuse:
 *
 *   Approved  a human said yes, and execution did NOT happen: a re-check refused it, or the tool
 *             failed to start. It cannot be approved again. A new proposal is required.
 *   Consumed  the approval was claimed and executed. Whether the TOOL succeeded is a separate
 *             question recorded on the tool call; the approval was spent either way.
 *
 * Expired is never a synonym for approved. An ignored approval is a refusal.
 */
enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Consumed = 'consumed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Rejected, self::Cancelled, self::Expired],
            // Claimed but not yet executed. Only consumption follows.
            self::Approved => [self::Consumed],
            self::Consumed, self::Rejected, self::Cancelled, self::Expired => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }

    /** Only a pending approval is waiting for a person. */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'waiting for a decision',
            self::Approved => 'approved, not executed',
            self::Consumed => 'approved and executed',
            self::Rejected => 'rejected',
            self::Cancelled => 'cancelled',
            self::Expired => 'expired without a decision',
        };
    }
}
