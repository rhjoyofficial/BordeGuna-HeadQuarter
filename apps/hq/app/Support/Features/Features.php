<?php

namespace App\Support\Features;

use App\Models\FeatureFlag;

/**
 * Feature flags: a flag is enabled for the current environment when a row for that
 * environment says so, else when the wildcard `*` row says so, else it is OFF.
 * Rollout metadata (`{"businesses":[1,2]}`) narrows an enabled flag to some businesses.
 * Later phases ship dark and are switched on here, per business, without a deploy.
 *
 * ⚠ The flags are read from the table on every call and never cached (docs/81 C.6). They include
 * `ai.runtime` and `ai.freeze`, and they used to sit in the shared store for 60 seconds, so a flag
 * changed by anything but the screen stayed as it was for that long. The class is a process
 * singleton, so a memo on it would be wrong for as long as a Horizon worker lives.
 */
final class Features
{
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

    /**
     * Nothing to forget: the flags are never cached. Kept because the writers of a flag call it, and
     * the call says what they mean if a cache is ever brought back.
     */
    public function forget(): void {}

    /** @return array<string, array<string, array{enabled: bool, rollout: ?array}>> */
    private function all(): array
    {
        $out = [];
        foreach (FeatureFlag::query()->get(['key', 'environment', 'enabled', 'rollout']) as $flag) {
            $out[$flag->key][$flag->environment] = ['enabled' => (bool) $flag->enabled, 'rollout' => $flag->rollout];
        }

        return $out;
    }
}
