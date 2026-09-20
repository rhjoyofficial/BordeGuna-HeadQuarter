<?php

namespace App\Briefing\Sections;

/**
 * How many individual rows a section lists beside its count. The count metric always carries every
 * row in its lineage; the listing is bounded so a bad day cannot make the bundle unbounded, and a
 * bounded listing says that it is one.
 */
final class Listing
{
    public static function limit(): int
    {
        return max(1, (int) config('hq.briefing.max_listed', 20));
    }

    /** @return list<array<string, mixed>> */
    public static function truncation(int $total, int $limit, string $what): array
    {
        return $total <= $limit ? [] : [[
            'status' => 'truncated',
            'reason' => "Only the first {$limit} {$what} are listed one by one; the count covers all of them.",
        ]];
    }
}
