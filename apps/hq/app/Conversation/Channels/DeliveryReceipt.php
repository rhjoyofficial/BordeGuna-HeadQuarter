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

    /**
     * The request may or may not have reached the provider (a timeout after the bytes left, a dropped
     * connection, an exception mid-call). ADR-031: never "failed", because a retry could then send the
     * same words twice; the attempt stays blocked until a person or the reconciliation settles it.
     */
    public const UNCERTAIN = 'uncertain';

    /**
     * @param  string|null  $category  a failure's class (`MetaSendError` categories), so a caller can tell a
     *                                 refusal it may retry from one it must not
     */
    public function __construct(
        public string $status,
        public ?string $providerRef,
        public CarbonImmutable $at,
        public ?string $error = null,
        public ?string $category = null,
        public bool $retryable = false,
    ) {}

    public function accepted(): bool
    {
        return $this->status === self::ACCEPTED || $this->status === self::SIMULATED;
    }

    public function uncertain(): bool
    {
        return $this->status === self::UNCERTAIN;
    }
}
