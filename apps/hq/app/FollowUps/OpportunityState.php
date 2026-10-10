<?php

namespace App\FollowUps;

/** docs/113 section 3.2. */
enum OpportunityState: string
{
    case Proposed = 'proposed';
    case Open = 'open';
    case Declined = 'declined';
    case Closed = 'closed';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Proposed => [self::Open, self::Declined],
            self::Open => [self::Closed],
            self::Declined, self::Closed => [],
        };
    }

    public function isFinal(): bool
    {
        return $this->allowedNext() === [];
    }
}
