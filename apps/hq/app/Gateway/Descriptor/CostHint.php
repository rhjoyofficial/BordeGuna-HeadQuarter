<?php

namespace App\Gateway\Descriptor;

/** What one call is expected to cost, so a budget check can happen before execution rather than after. */
final readonly class CostHint
{
    public function __construct(
        public int $externalCalls = 0,
        public float $estimatedUsd = 0.0,
    ) {}

    public function toArray(): array
    {
        return ['external_calls' => $this->externalCalls, 'estimated_usd' => $this->estimatedUsd];
    }
}
