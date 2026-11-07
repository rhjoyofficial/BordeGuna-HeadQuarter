<?php

namespace App\Autonomy\Ladder;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Lapses decided but not yet known to be durably written (docs/150).
 *
 * A marker is set BEFORE any write is tried and cleared only once the lapse is known to be committed. While a
 * marker exists the grant is refused, even when every condition holds again (a pause state that was briefly
 * unreadable and is readable now does not make a decided lapse undecided), and every decision and the
 * minute sweep retry the write.
 *
 * Two layers, both best effort and never throwing: this process's memory (a singleton, so a long-lived
 * worker remembers even when nothing else is reachable) and the cache store (shared across processes and
 * restarts when the store is not the database; a database cache store shares the database's fate, and the
 * grant's own conditions are then the guard, evaluated again at every decision).
 */
final class PendingLapses
{
    private const KEY = 'hq:autonomy:pending-lapses';

    /** @var array<int, array{code: string, reason: string, at: string}> */
    private array $memory = [];

    public function mark(int $grantId, string $code, string $reason): void
    {
        $entry = ['code' => $code, 'reason' => mb_substr($reason, 0, 2000), 'at' => now()->toIso8601String()];
        $this->memory[$grantId] ??= $entry;
        try {
            $all = $this->fromCache();
            $all[$grantId] ??= $entry;
            Cache::forever(self::KEY, $all);
        } catch (Throwable) {
        }
    }

    /** @return array{code: string, reason: string, at: string}|null */
    public function get(int $grantId): ?array
    {
        return $this->memory[$grantId] ?? $this->safeCache()[$grantId] ?? null;
    }

    /** @return array<int, array{code: string, reason: string, at: string}> */
    public function all(): array
    {
        return $this->memory + $this->safeCache();
    }

    public function clear(int $grantId): void
    {
        unset($this->memory[$grantId]);
        try {
            $all = $this->fromCache();
            if (array_key_exists($grantId, $all)) {
                unset($all[$grantId]);
                $all === [] ? Cache::forget(self::KEY) : Cache::forever(self::KEY, $all);
            }
        } catch (Throwable) {
        }
    }

    /** @return array<int, array{code: string, reason: string, at: string}> */
    private function safeCache(): array
    {
        try {
            return $this->fromCache();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<int, array{code: string, reason: string, at: string}> */
    private function fromCache(): array
    {
        $all = Cache::get(self::KEY);

        return is_array($all) ? $all : [];
    }
}
