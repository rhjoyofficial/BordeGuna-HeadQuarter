<?php

namespace App\Courier;

/**
 * What a courier said about one consignment, in HQ's vocabulary. Built by a driver from a tracking
 * call or an incoming webhook; written to HQ's own read model by `ParcelLedger`, never to the ERP.
 */
final readonly class ParcelSnapshot
{
    /** @param list<ParcelEvent> $events oldest first; at least one when the courier knows the parcel */
    public function __construct(
        public string $provider,
        public string $consignmentRef,
        public ParcelStatus $status,
        public string $rawStatus,
        public array $events = [],
        /** The merchant's own reference the courier echoes (the order number), used only to link an order. */
        public ?string $merchantOrderRef = null,
    ) {}
}
