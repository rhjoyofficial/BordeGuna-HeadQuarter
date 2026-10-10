<?php

namespace App\Decisions;

use Carbon\CarbonImmutable;

/**
 * The period the decision-corpus reader covers (docs/111 §5).
 *
 * Not `DashboardWindow`: its options (24h / 7d / 30d) are a property the observe dashboards rely on,
 * because they must stay inside the 180-day retention detail window. `decision_records` is permanent
 * (`RetentionPolicy::PERMANENT`), and a recurring edit is only visible over months, so this window
 * goes longer and may cover everything.
 */
final readonly class DecisionCorpusWindow
{
    /** key => days, null = everything ever recorded. */
    public const OPTIONS = ['30d' => 30, '90d' => 90, '365d' => 365, 'all' => null];

    public const DEFAULT = '90d';

    public function __construct(
        public string $key,
        public ?CarbonImmutable $since,
        public CarbonImmutable $until,
    ) {}

    public static function from(?string $key, ?CarbonImmutable $now = null): self
    {
        $key = is_string($key) && array_key_exists($key, self::OPTIONS) ? $key : self::DEFAULT;
        $now = ($now ?? CarbonImmutable::now())->utc();
        $days = self::OPTIONS[$key];

        return new self($key, $days === null ? null : $now->subDays($days), $now);
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'since' => $this->since?->toIso8601String(),
            'until' => $this->until->toIso8601String(),
            'options' => array_keys(self::OPTIONS),
        ];
    }
}
