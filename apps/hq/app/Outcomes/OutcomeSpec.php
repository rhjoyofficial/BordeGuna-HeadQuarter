<?php

namespace App\Outcomes;

use App\Context\Provenance;
use DateTimeInterface;

/**
 * What a caller wants recorded as one outcome. The idempotency key is the caller's, composed from the
 * SOURCE identity (for example `order.placed:erp:{business}:{order_number}`), never from HQ's clock
 * or row ids, so two workers observing one event collide on one key (docs/107 D12).
 */
final readonly class OutcomeSpec
{
    /**
     * @param  list<ResultRefSpec>  $results
     * @param  array<string, mixed>|null  $evidence  identifiers and digests, never copies of business facts (D5)
     */
    public function __construct(
        public string $type,
        public int $businessId,
        public DateTimeInterface $occurredAt,
        public OutcomeSource $source,
        public string $sourceSystem,
        public Provenance $provenance,
        public string $idempotencyKey,
        public array $results,
        public ?int $brandId = null,
        public ?int $storeId = null,
        public ?DateTimeInterface $sourceAsOf = null,
        public ?string $sourceRef = null,
        public ?array $evidence = null,
        public ?OutcomeActor $actor = null,
        public ?string $correlationId = null,
    ) {}
}
