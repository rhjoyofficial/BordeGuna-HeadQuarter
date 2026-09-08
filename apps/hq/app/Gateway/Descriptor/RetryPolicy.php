<?php

namespace App\Gateway\Descriptor;

/**
 * Which failures are worth repeating, and how patiently.
 *
 * The gateway does not itself retry in M1: the scheduler owns retry for scheduled work and does it
 * well already (attempt counter, exponential backoff, dead-lettering). This declares the tool's
 * intent so that the agent runtime and the M2 scheduler migration have one answer to read rather
 * than two implementations to disagree about.
 */
final readonly class RetryPolicy
{
    /** @param list<string> $retryOn failure classes worth another attempt */
    public function __construct(
        public int $max = 3,
        public int $backoffSeconds = 60,
        public array $retryOn = ['unavailable'],
    ) {}

    public function toArray(): array
    {
        return ['max' => $this->max, 'backoff_seconds' => $this->backoffSeconds, 'retry_on' => $this->retryOn];
    }
}
