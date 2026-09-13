<?php

namespace App\Conversation\Channels;

use Carbon\CarbonImmutable;

/**
 * What a provider said when handed an outbound message (docs/72 §B, ADR-031).
 *
 * ⚠ `accepted` is not `delivered`. A provider accepting a request is a request that succeeded; a
 * message is real when the provider's own delivery signal says so afterwards, which is what
 * `message_deliveries.verified_at` records. This receipt is the first half only.
 */
final readonly class DeliveryReceipt
{
    public const ACCEPTED = 'accepted';

    public const FAILED = 'failed';

    /** Never left the process: the simulated channel used in tests and shadow fixtures. */
    public const SIMULATED = 'simulated';

    public function __construct(
        public string $status,
        public ?string $providerRef,
        public CarbonImmutable $at,
        public ?string $error = null,
    ) {}

    public function accepted(): bool
    {
        return $this->status === self::ACCEPTED || $this->status === self::SIMULATED;
    }
}
