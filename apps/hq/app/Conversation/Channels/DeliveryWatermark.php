<?php

namespace App\Conversation\Channels;

use Carbon\CarbonImmutable;

/**
 * Messenger's "everything before this moment" signal (`delivery.watermark`, `read.watermark`).
 *
 * Meta documents the watermark as "a timestamp indicating that all messages with a timestamp before
 * `watermark` were delivered" (the `mids` list "may not be present"), and a read event carries only a
 * watermark. So it names a thread and an instant, not a message: the ledger promotes every delivery HQ
 * sent on that thread at or before the instant.
 */
final readonly class DeliveryWatermark
{
    public function __construct(
        public string $pageRef,
        public string $recipientExternalId,
        public string $status,
        public CarbonImmutable $watermark,
    ) {}

    /** The thread reference `MetaMessengerNormaliser` gives the same person on the same Page. */
    public function threadRef(): string
    {
        return "{$this->pageRef}:{$this->recipientExternalId}";
    }
}
