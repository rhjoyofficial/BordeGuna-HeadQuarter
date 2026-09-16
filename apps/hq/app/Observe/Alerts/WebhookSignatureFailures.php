<?php

namespace App\Observe\Alerts;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * How many webhook deliveries failed their signature check, per source, per hour (docs/25).
 *
 * A counter in the cache, not a table: a burst of bad signatures is somebody knocking, and writing a
 * row per knock would let them fill the database. Counting can never break the webhook it counts: a
 * cache failure is swallowed, and the request is refused either way.
 */
final class WebhookSignatureFailures
{
    private const PREFIX = 'hq:webhook-signature-failures:';

    public const SOURCES = ['meta', 'shop'];

    /** Kept for a day, far longer than any window a rule reads. */
    private const TTL_SECONDS = 86400;

    public function record(string $source, ?CarbonImmutable $now = null): void
    {
        $key = $this->key($source, $now ?? CarbonImmutable::now());

        try {
            Cache::add($key, 0, self::TTL_SECONDS);
            Cache::increment($key);
        } catch (Throwable) {
            // The request is refused regardless; a missing count is the lesser loss.
        }
    }

    /** @return array<string, int> failures per source over the last `$hours` hours, this one included */
    public function recent(int $hours, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $counts = [];

        foreach (self::SOURCES as $source) {
            $counts[$source] = 0;
            for ($i = 0; $i < max(1, $hours); $i++) {
                $counts[$source] += (int) Cache::get($this->key($source, $now->subHours($i)), 0);
            }
        }

        return $counts;
    }

    private function key(string $source, CarbonImmutable $at): string
    {
        return self::PREFIX.$source.':'.$at->utc()->format('YmdH');
    }
}
