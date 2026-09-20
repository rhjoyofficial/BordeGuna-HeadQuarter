<?php

namespace App\Briefing;

use Carbon\CarbonImmutable;

/**
 * Budget, age and verdict, carried by every source and every item (docs/48 M.3).
 *
 * A stale snapshot once looked exactly like a fresh one (docs/45), and so did a timestamp six hours
 * in the future. So an `as_of` later than the moment of generation is never fresh: it is stale with
 * `in_future` set, because a clock that disagrees is a reason to distrust the figure, not to trust
 * it more.
 */
final readonly class Freshness
{
    /** Clock skew tolerated before an `as_of` counts as being in the future. */
    public const FUTURE_TOLERANCE_MINUTES = 5;

    public function __construct(
        public FreshnessState $state,
        public ?int $budgetMinutes = null,
        public ?int $ageMinutes = null,
        public bool $inFuture = false,
    ) {}

    public static function measure(?CarbonImmutable $asOf, int $budgetMinutes, CarbonImmutable $now): self
    {
        if ($asOf === null) {
            return new self(FreshnessState::Missing, $budgetMinutes);
        }

        $age = (int) floor(($now->getTimestamp() - $asOf->getTimestamp()) / 60);

        if ($age < -self::FUTURE_TOLERANCE_MINUTES) {
            return new self(FreshnessState::Stale, $budgetMinutes, $age, true);
        }

        $age = max(0, $age);

        return new self($age > $budgetMinutes ? FreshnessState::Stale : FreshnessState::Fresh, $budgetMinutes, $age);
    }

    public static function live(): self
    {
        return new self(FreshnessState::Live, null, 0);
    }

    public static function notConnected(): self
    {
        return new self(FreshnessState::NotConnected);
    }

    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'budget_minutes' => $this->budgetMinutes,
            'age_minutes' => $this->ageMinutes,
            'in_future' => $this->inFuture,
        ];
    }
}
