<?php

namespace App\Observe\Dashboards;

use Carbon\CarbonImmutable;

/**
 * The period a dashboard reads: the last day, week or month.
 *
 * Every option sits well inside the 180-day detail window, so the dashboards always read detail rows
 * and never need the retention aggregates; that is a property of this list, and a longer option would
 * have to read both.
 */
final readonly class DashboardWindow
{
    public const OPTIONS = ['24h' => 1, '7d' => 7, '30d' => 30];

    public const DEFAULT = '7d';

    public function __construct(
        public string $key,
        public CarbonImmutable $since,
        public CarbonImmutable $until,
    ) {}

    public static function from(?string $key, ?CarbonImmutable $now = null): self
    {
        $key = is_string($key) && array_key_exists($key, self::OPTIONS) ? $key : self::DEFAULT;
        // UTC, because a bound is bound as a zone-less string and `ai_runs` keeps zone-less UTC times.
        $now = ($now ?? CarbonImmutable::now())->utc();

        return new self($key, $now->subDays(self::OPTIONS[$key]), $now);
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'since' => $this->since->toIso8601String(),
            'until' => $this->until->toIso8601String(),
            'options' => array_keys(self::OPTIONS),
        ];
    }
}
