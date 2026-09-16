<?php

namespace App\Observe\Alerts\Rules;

use App\Observe\Alerts\AlertCondition;
use App\Observe\Alerts\AlertRule;
use App\Observe\HostProbe;
use Carbon\CarbonImmutable;

/** The disk HQ's storage sits on is filling (docs/25: over 80 percent). The host monitor pages it. */
final class HostDisk implements AlertRule
{
    public function __construct(private readonly HostProbe $host) {}

    public function key(): string
    {
        return 'host.disk';
    }

    public function pagedBy(): ?string
    {
        return 'hq-monitor.sh';
    }

    public function evaluate(CarbonImmutable $now): ?array
    {
        $disk = $this->host->disk();

        if ($disk === null) {
            return null;
        }

        $warning = (int) config('hq.alerts.thresholds.disk_warning_percent', 80);
        $critical = (int) config('hq.alerts.thresholds.disk_critical_percent', 90);
        $used = $disk['used_percent'];

        if ($used <= $warning) {
            return [];
        }

        return [new AlertCondition('storage', $used > $critical ? 'critical' : 'warning', "Disk {$used}% used",
            'Free: '.round($disk['free_bytes'] / 1073741824, 1).' GB. Warning above '.$warning.'%, critical above '.$critical.'%. Look: docker system df, and the backup directory sizes.',
            ['used_percent' => $used, 'free_bytes' => $disk['free_bytes'], 'total_bytes' => $disk['total_bytes']])];
    }
}
