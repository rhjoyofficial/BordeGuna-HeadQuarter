<?php

namespace App\Ai;

use Illuminate\Support\Facades\Cache;

/**
 * Stops HQ hammering a provider that is down.
 *
 * Three states, the standard ones. **Closed**: calls pass. **Open**: calls are refused immediately
 * and the router moves to the next candidate, which is the whole point, because failing in
 * milliseconds is what leaves time for a fallback to succeed. **Half open**: after a cooldown one
 * call is let through as a probe; success closes the breaker, failure reopens it.
 *
 * ⚠ **Only `ProviderUnavailable` trips it.** A `ProviderRefused` means HQ sent something broken, and
 * counting that as an outage would take a perfectly healthy provider out of rotation because of a
 * bug on this side. That distinction is the one thing a naive breaker gets wrong.
 *
 * ⚠ State lives in the cache, not the database. It is operational, it must be read on every call,
 * and it should evaporate on its own rather than need clearing up. The trade is that it is per-cache
 * rather than per-cluster, which is correct here: HQ runs one Redis.
 */
final class CircuitBreaker
{
    private const PREFIX = 'hq:ai:breaker:';

    /** Consecutive failures before the breaker opens. */
    public const THRESHOLD = 4;

    /** How long it stays open before allowing a probe. */
    public const COOLDOWN_SECONDS = 120;

    /** How long a failure counts toward the threshold. Failures spread thinly are not an outage. */
    public const WINDOW_SECONDS = 300;

    public function allows(string $provider): bool
    {
        return $this->state($provider) !== 'open';
    }

    /** @return 'closed'|'half_open'|'open' */
    public function state(string $provider): string
    {
        $openedAt = Cache::get(self::PREFIX."open:{$provider}");

        if ($openedAt === null) {
            return 'closed';
        }

        // The cooldown has passed, so let exactly one call through to find out.
        return (time() - (int) $openedAt) >= self::COOLDOWN_SECONDS ? 'half_open' : 'open';
    }

    public function recordSuccess(string $provider): void
    {
        Cache::forget(self::PREFIX."fails:{$provider}");
        Cache::forget(self::PREFIX."open:{$provider}");
    }

    /** Call this ONLY for a retryable failure. See the class docblock. */
    public function recordFailure(string $provider): void
    {
        $key = self::PREFIX."fails:{$provider}";

        // `add` first so the TTL is set by the first write; `increment` alone would create a
        // counter with no expiry and the window would never slide.
        Cache::add($key, 0, self::WINDOW_SECONDS);
        $failures = (int) Cache::increment($key);

        if ($failures >= self::THRESHOLD) {
            Cache::put(self::PREFIX."open:{$provider}", time(), self::COOLDOWN_SECONDS * 4);
        }
    }

    public function failures(string $provider): int
    {
        return (int) Cache::get(self::PREFIX."fails:{$provider}", 0);
    }

    /** For an operator: what every provider looks like right now. */
    public function overview(array $providers): array
    {
        $out = [];
        foreach ($providers as $provider) {
            $out[$provider] = ['state' => $this->state($provider), 'consecutive_failures' => $this->failures($provider)];
        }

        return $out;
    }

    public function reset(string $provider): void
    {
        $this->recordSuccess($provider);
    }
}
