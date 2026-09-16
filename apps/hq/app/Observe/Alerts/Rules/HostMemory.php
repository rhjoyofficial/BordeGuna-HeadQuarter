<?php

namespace App\Observe\Alerts\Rules;

use App\Observe\Alerts\AlertCondition;
use App\Observe\Alerts\AlertRule;
use App\Observe\HostProbe;
use Carbon\CarbonImmutable;

/** Available memory under the floor (docs/25: 1 GB). The host monitor pages it. */
final class HostMemory implements AlertRule
{
    public function __construct(private readonly HostProbe $host) {}

    public function key(): string
    {
        return 'host.memory';
    }

    public function pagedBy(): ?string
    {
        return 'hq-monitor.sh';
    }

    public function evaluate(CarbonImmutable $now): ?array
    {
        $memory = $this->host->memory();

        if ($memory === null) {
            return null;
        }

        $floor = (int) config('hq.alerts.thresholds.memory_min_available_mb', 1024);

        if ($memory['available_mb'] >= $floor) {
            return [];
        }

        return [new AlertCondition('available', 'warning', "Only {$memory['available_mb']} MB of memory available",
            "The floor is {$floor} MB of {$memory['total_mb']} MB. Look: docker stats --no-stream.",
            ['available_mb' => $memory['available_mb'], 'total_mb' => $memory['total_mb'], 'floor_mb' => $floor])];
    }
}
