<?php

namespace App\FollowUps;

/** docs/113 section 3.1. Transitions are the only legal moves; anything else throws (F3). */
enum FollowUpState: string
{
    case Proposed = 'proposed';
    case Refused = 'refused';
    case Approved = 'approved';
    case Scheduled = 'scheduled';
    case Declined = 'declined';
    case Done = 'done';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Proposed => [self::Approved, self::Declined, self::Refused],
            self::Approved => [self::Scheduled, self::Refused],
            self::Scheduled => [self::Done, self::Cancelled],
            self::Refused, self::Declined, self::Done, self::Cancelled => [],
        };
    }

    public function isFinal(): bool
    {
        return $this->allowedNext() === [];
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Proposed, self::Approved, self::Scheduled], true);
    }
}
