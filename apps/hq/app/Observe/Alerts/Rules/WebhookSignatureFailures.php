<?php

namespace App\Observe\Alerts\Rules;

use App\Observe\Alerts\AlertCondition;
use App\Observe\Alerts\AlertRule;
use App\Observe\Alerts\WebhookSignatureFailures as Counter;
use Carbon\CarbonImmutable;

/**
 * Webhook deliveries failing their signature check in numbers (docs/25). One is a misconfiguration
 * or a probe; many is either a changed secret on the sending side or somebody trying their luck.
 */
final class WebhookSignatureFailures implements AlertRule
{
    public function __construct(private readonly Counter $counter) {}

    public function key(): string
    {
        return 'webhooks.signature_failures';
    }

    public function pagedBy(): ?string
    {
        return null;
    }

    public function evaluate(CarbonImmutable $now): ?array
    {
        $threshold = max(1, (int) config('hq.alerts.thresholds.webhook_signature_failures.count', 10));
        $hours = max(1, (int) config('hq.alerts.thresholds.webhook_signature_failures.window_hours', 2));
        $counts = $this->counter->recent($hours, $now);
        $total = array_sum($counts);

        if ($total < $threshold) {
            return [];
        }

        return [new AlertCondition('all', 'warning', "{$total} webhook(s) refused for a bad signature in {$hours} hours",
            'By source: '.collect($counts)->filter()->map(fn ($n, $source) => "{$source} {$n}")->implode(', ')
            .'. Either the sender\'s secret changed (check the shop or Meta app settings against HQ\'s) or someone is probing the endpoint. Nothing was accepted.',
            ['counts' => $counts, 'window_hours' => $hours])];
    }
}
