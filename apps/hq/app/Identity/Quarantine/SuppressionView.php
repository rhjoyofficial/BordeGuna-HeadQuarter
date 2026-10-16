<?php

namespace App\Identity\Quarantine;

/**
 * The cross-business relationships of one customer that a read must suppress (docs/117 §4). Computed by
 * `CrossBusinessSuppression::forCustomer()`; carries ids of this customer's own rows and merge ids only,
 * never anything of the other business.
 *
 * docs/126 adds two holds: thread rows (participants, messages) of another business under this customer
 * (item 39), and business-less memories a repair left for a person to assign (ruling A3 point 8).
 */
final readonly class SuppressionView
{
    /**
     * @param  list<array{id: int, at: mixed}>  $keptMerges  cross-business merges where this customer was kept
     * @param  list<array{id: int, at: mixed}>  $absorbedMerges  cross-business merges where this customer was absorbed
     * @param  list<int>  $heldIdentityIds  identities under this customer that are not shown
     * @param  list<int>  $heldMemoryIds  business-less memories a repair withheld until a person assigns them
     */
    public function __construct(
        public int $businessId,
        public array $keptMerges,
        public array $absorbedMerges,
        public bool $pointerCrosses,
        public array $heldIdentityIds,
        public mixed $unscopedMemoryCutoff,
        public bool $withholdPhone,
        public bool $withholdEmail,
        public bool $recomputeAggregates,
        public bool $heldThreadRows = false,
        public array $heldMemoryIds = [],
    ) {}

    public function held(): bool
    {
        return $this->keptMerges !== [] || $this->absorbedMerges !== [] || $this->pointerCrosses
            || $this->heldIdentityIds !== [] || $this->withholdPhone || $this->withholdEmail || $this->recomputeAggregates
            || $this->heldThreadRows || $this->heldMemoryIds !== [];
    }

    /** @return list<int> */
    public function mergeIds(): array
    {
        return array_values(array_unique([...array_column($this->keptMerges, 'id'), ...array_column($this->absorbedMerges, 'id')]));
    }

    /** Which kinds of value are withheld, by name only (no counts, nothing of the other business). @return list<string> */
    public function withheld(): array
    {
        return array_values(array_filter([
            $this->withholdPhone ? 'phone' : null,
            $this->withholdEmail ? 'email' : null,
            $this->recomputeAggregates ? 'aggregates' : null,
            $this->heldIdentityIds !== [] ? 'identities' : null,
            ($this->unscopedMemoryCutoff !== null || $this->heldMemoryIds !== []) ? 'memories' : null,
            ($this->absorbedMerges !== [] || $this->pointerCrosses) ? 'merge_link' : null,
            $this->heldThreadRows ? 'threads' : null,
        ]));
    }
}
