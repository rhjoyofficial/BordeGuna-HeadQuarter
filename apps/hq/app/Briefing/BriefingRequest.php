<?php

namespace App\Briefing;

use Carbon\CarbonImmutable;

/**
 * The whole input of the Executive Briefing capability: a date and a scope (docs/80 point 2).
 *
 * There is no question text here and nowhere to put one. Whatever words an operator used, by the
 * time a request exists they have been mapped to this capability by name, and what it reads is
 * decided by the contract, not by language.
 */
final readonly class BriefingRequest
{
    /**
     * @param  CarbonImmutable  $date  the day the briefing is for, at its first instant in the group timezone
     * @param  list<int>|null  $permittedBusinessIds  the caller's own restriction from the gateway, null for group-wide
     * @param  array<string, bool>  $mayRead  the sections that need a permission beyond the tool's own
     */
    public function __construct(
        public CarbonImmutable $date,
        public string $timezone,
        public CarbonImmutable $now,
        public ?int $businessId = null,
        public ?string $businessSlug = null,
        public ?array $permittedBusinessIds = null,
        public array $mayRead = [],
    ) {}

    public function isToday(): bool
    {
        return $this->date->equalTo($this->now->setTimezone($this->timezone)->startOfDay());
    }

    /** Whether the caller reads the whole group: no business asked for and no restriction held. */
    public function isGroupWide(): bool
    {
        return $this->businessId === null && $this->permittedBusinessIds === null;
    }

    public function mayRead(string $section): bool
    {
        return (bool) ($this->mayRead[$section] ?? false);
    }

    /** @return array{date: string, from: string, to: string} */
    public function yesterday(): array
    {
        return $this->day($this->date->subDay());
    }

    /** @return array{date: string, from: string, to: string} */
    public function today(): array
    {
        return $this->day($this->date);
    }

    /** Narrows a query to the asked business and to the caller's own businesses. Rows with no business drop out for a restricted caller. */
    public function restrict(mixed $query, string $column): mixed
    {
        if ($this->businessId !== null) {
            $query->where($column, $this->businessId);
        }
        if ($this->permittedBusinessIds !== null) {
            $query->whereIn($column, $this->permittedBusinessIds);
        }

        return $query;
    }

    /**
     * A calendar day in the group timezone, with its bounds in UTC: `from` inclusive, `to` exclusive.
     * The container runs UTC and no shop does, so a day taken in UTC would move a Dhaka evening's
     * orders to the next day (the defect `facts.query_orders` documents).
     *
     * @return array{date: string, from: string, to: string}
     */
    private function day(CarbonImmutable $start): array
    {
        $start = $start->setTimezone($this->timezone)->startOfDay();

        return [
            'date' => $start->toDateString(),
            'from' => $start->utc()->toIso8601String(),
            'to' => $start->addDay()->utc()->toIso8601String(),
        ];
    }
}
