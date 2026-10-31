<?php

namespace App\OwnerConsole;

use App\Support\Features\Features;

/**
 * The six switches of docs/143, every one seeded OFF by migration 2026_10_31_100000. A switch that is
 * missing, or whose row cannot be read, is OFF: `Features::enabled` answers false for an unknown key.
 *
 * Changing any of them is a feature-flag change, which already needs `hq.flags.manage` AND group-wide
 * authority at the route (docs/138 ruling 13); nothing in this namespace flips one.
 */
final class Flag
{
    public const DO_MODE = 'console.do_mode';

    public const BATCH_APPROVAL = 'console.batch_approval';

    public const ONE_CLICK_APPROVE = 'console.one_click_approve';

    public const TELEGRAM_BRIEF = 'console.telegram_brief';

    public const REPLY_OPTIONS = 'support.reply_options';

    public const EVENT_WATCHES = 'console.event_watches';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::DO_MODE, self::BATCH_APPROVAL, self::ONE_CLICK_APPROVE, self::TELEGRAM_BRIEF, self::REPLY_OPTIONS, self::EVENT_WATCHES];
    }

    public static function on(string $key): bool
    {
        return app(Features::class)->enabled($key);
    }
}
