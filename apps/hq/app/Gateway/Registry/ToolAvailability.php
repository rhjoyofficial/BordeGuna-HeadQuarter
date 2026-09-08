<?php

namespace App\Gateway\Registry;

use App\Models\ToolRegistration;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\Cache;

/**
 * The two-switch model (docs/48 D.2), and the reason it has no ordering problem.
 *
 *   registered in code (config('hq.tools'))   AND   not disabled at runtime (the `tools` table)
 *
 * They compose by AND and the config list always wins. The important asymmetry: **the absence of a
 * database row means ENABLED**. The table can only ever record a decision to turn something OFF.
 *
 * That is what makes "a runtime database flag must never create a capability that is absent from
 * the deployed code" true by construction rather than by a check somebody has to remember. It also
 * removes the startup-ordering trap: a newly deployed tool works immediately, a sync is never
 * load-bearing, and a `tools` table wiped by a bad restore fails OPEN to exactly the set of
 * capabilities the deployed code contains, which is a set a human already reviewed.
 */
final class ToolAvailability
{
    private const CACHE_KEY = 'hq:tool_switches';

    private const CACHE_TTL_SECONDS = 30;

    public function __construct(private readonly ToolRegistry $registry) {}

    public function registered(string $key): bool
    {
        return $this->registry->has($key);
    }

    /** @return array{available: bool, reason: ?string} */
    public function check(string $key, ?int $businessId = null): array
    {
        if (! $this->registry->has($key)) {
            return ['available' => false, 'reason' => "Unknown tool [{$key}]: it is not registered in config/hq.php."];
        }

        $switch = $this->switches()[$key] ?? null;
        if ($switch === null) {
            return ['available' => true, 'reason' => null];
        }

        if (! $switch['enabled']) {
            $why = $switch['disabled_reason'] ?: 'no reason recorded';

            return ['available' => false, 'reason' => "Tool [{$key}] is disabled at runtime: {$why}."];
        }

        $businesses = $switch['enabled_businesses'];
        if (is_array($businesses) && $businesses !== [] && $businessId !== null && ! in_array($businessId, $businesses, true)) {
            return ['available' => false, 'reason' => "Tool [{$key}] is not enabled for this business."];
        }

        return ['available' => true, 'reason' => null];
    }

    public function isAvailable(string $key, ?int $businessId = null): bool
    {
        return $this->check($key, $businessId)['available'];
    }

    /** Every registered key, with its runtime state, for a listing screen. */
    public function overview(): array
    {
        $switches = $this->switches();
        $rows = [];
        foreach ($this->registry->keys() as $key) {
            $switch = $switches[$key] ?? null;
            $rows[$key] = [
                'enabled' => $switch === null ? true : (bool) $switch['enabled'],
                'enabled_businesses' => $switch['enabled_businesses'] ?? null,
                'disabled_reason' => $switch['disabled_reason'] ?? null,
                'disabled_at' => $switch['disabled_at'] ?? null,
                'has_row' => $switch !== null,
            ];
        }

        return $rows;
    }

    /**
     * Rows in the table whose tool is no longer registered in code.
     *
     * They are reported, never deleted: a row saying "Joy disabled this on 8 September because it
     * double-charged" is a record worth more than the tidiness of removing it, and if the class
     * comes back the disablement should come back with it.
     *
     * @return list<string>
     */
    public function orphans(): array
    {
        return array_values(array_diff(array_keys($this->switches()), $this->registry->keys()));
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, array{enabled: bool, enabled_businesses: ?array, disabled_reason: ?string, disabled_at: ?string}> */
    private function switches(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, static function () {
            $out = [];
            foreach (ToolRegistration::query()->get(['key', 'enabled', 'enabled_businesses', 'disabled_reason', 'disabled_at']) as $row) {
                $out[$row->key] = [
                    'enabled' => (bool) $row->enabled,
                    'enabled_businesses' => $row->enabled_businesses,
                    'disabled_reason' => $row->disabled_reason,
                    'disabled_at' => $row->disabled_at?->toIso8601String(),
                ];
            }

            return $out;
        });
    }
}
