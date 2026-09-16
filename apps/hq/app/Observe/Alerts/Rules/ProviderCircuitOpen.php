<?php

namespace App\Observe\Alerts\Rules;

use App\Ai\CircuitBreaker;
use App\Ai\Providers\ProviderRegistry;
use App\Observe\Alerts\AlertCondition;
use App\Observe\Alerts\AlertRule;
use Carbon\CarbonImmutable;

/**
 * A model provider's circuit breaker is open: HQ stopped calling it after repeated outages (docs/48
 * N.5). Half open is not an alert; it is the breaker letting one call through to find out.
 */
final class ProviderCircuitOpen implements AlertRule
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly CircuitBreaker $breaker,
    ) {}

    public function key(): string
    {
        return 'ai.circuit_open';
    }

    public function pagedBy(): ?string
    {
        return null;
    }

    public function evaluate(CarbonImmutable $now): ?array
    {
        $conditions = [];

        foreach ($this->breaker->overview($this->providers->keys()) as $provider => $state) {
            if ($state['state'] !== 'open') {
                continue;
            }

            $conditions[] = new AlertCondition("provider:{$provider}", 'warning', "Model provider {$provider} is failing; HQ has paused calls to it",
                "The circuit breaker opened after {$state['consecutive_failures']} consecutive outage failures. HQ moves to another provider where one is configured and tries this one again after "
                .CircuitBreaker::COOLDOWN_SECONDS.' seconds. See docs/runbooks/provider-blocked.md.',
                ['provider' => $provider, 'consecutive_failures' => $state['consecutive_failures']]);
        }

        return $conditions;
    }
}
