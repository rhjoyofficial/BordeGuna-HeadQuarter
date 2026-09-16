<?php

namespace App\Observe;

/**
 * The host as the application container sees it: disk under `storage/` and available memory.
 *
 * Inside the container these are the host's own figures (the storage volume sits on the host's disk,
 * and the container has no memory limit, so `/proc/meminfo` is the host's). The host monitor
 * (`deploy/hq-monitor.sh`) is what pages on them; this is what HQ's own screens and alert record read.
 * Null means the figure is not readable here, which on a developer's Windows machine is normal.
 */
class HostProbe
{
    /** @return array{path: string, total_bytes: int, free_bytes: int, used_percent: int}|null */
    public function disk(): ?array
    {
        $path = storage_path();
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        if (! is_float($total) || ! is_float($free) || $total <= 0) {
            return null;
        }

        return [
            'path' => $path,
            'total_bytes' => (int) $total,
            'free_bytes' => (int) $free,
            'used_percent' => (int) round(100 * (1 - $free / $total)),
        ];
    }

    /** @return array{available_mb: int, total_mb: int}|null */
    public function memory(): ?array
    {
        $info = @file_get_contents('/proc/meminfo');

        if (! is_string($info)
            || ! preg_match('/^MemAvailable:\s+(\d+)\s+kB/m', $info, $available)
            || ! preg_match('/^MemTotal:\s+(\d+)\s+kB/m', $info, $total)) {
            return null;
        }

        return [
            'available_mb' => intdiv((int) $available[1], 1024),
            'total_mb' => intdiv((int) $total[1], 1024),
        ];
    }
}
