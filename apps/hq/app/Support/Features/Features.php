<?php

namespace App\Support\Features;

use App\Models\FeatureFlag;
use Illuminate\Support\Facades\Cache;

/**
 * Feature flags: a flag is enabled for the current environment when a row for that
 * environment says so, else when the wildcard `*` row says so, else it is OFF.
 * Rollout metadata (`{"businesses":[1,2]}`) narrows an enabled flag to some businesses.
 * Later phases ship dark and are switched on here, per business, without a deploy.
 */
final class Features
{
    private const CACHE_KEY = 'hq:feature_flags';

    public function enabled(string $key, ?int $businessId = null): bool
    {
        $flags = $this->all();
        $env = (string) app()->environment();
        $flag = $flags[$key][$env] ?? $flags[$key]['*'] ?? null;
        if ($flag === null || ! $flag['enabled']) {
            return false;
        }
        $allowed = $flag['rollout']['businesses'] ?? null;
        if (is_array($allowed) && $businessId !== null && ! in_array($businessId, $allowed, true)) {
            return false;
        }

        return true;
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, array<string, array{enabled: bool, rollout: ?array}>> */
    private function all(): array
    {
        return Cache::remember(self::CACHE_KEY, 60, function () {
            $out = [];
            foreach (FeatureFlag::query()->get(['key', 'environment', 'enabled', 'rollout']) as $flag) {
                $out[$flag->key][$flag->environment] = ['enabled' => (bool) $flag->enabled, 'rollout' => $flag->rollout];
            }

            return $out;
        });
    }
}
