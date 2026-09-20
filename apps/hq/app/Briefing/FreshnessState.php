<?php

namespace App\Briefing;

/**
 * How current a source or an item is (docs/48 M.3).
 *
 * `not_connected` is not a fault: HQ has no adapter for the store, or no poll is scheduled for the
 * business, and the briefing says so instead of pretending the source is late. `live` is one of
 * HQ's own control tables read at generation time, which has no copy to go stale.
 */
enum FreshnessState: string
{
    case Fresh = 'fresh';
    case Stale = 'stale';
    case Missing = 'missing';
    case NotConnected = 'not_connected';
    case Live = 'live';

    /** Whether a briefing resting on this must say it is degraded. */
    public function degrades(): bool
    {
        return $this === self::Stale || $this === self::Missing;
    }
}
