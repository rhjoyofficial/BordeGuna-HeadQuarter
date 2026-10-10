<?php

namespace App\Identity\Quarantine;

/**
 * One relationship that crosses a business boundary, as the detector found it (docs/117 §2). A value,
 * never persisted by the detector; `CrossBusinessQuarantine` is the flag the command writes.
 */
final readonly class Finding
{
    /** @param  array<string, mixed>  $detail */
    public function __construct(
        public string $kind,
        public string $subjectTable,
        public int $subjectId,
        public int $customerId,
        public int $customerBusinessId,
        public ?int $rowBusinessId,
        public ?int $mergeId,
        public string $reason,
        public array $detail = [],
    ) {}

    /** The identity of a flag: one per subject row and reason (the table's unique key). */
    public function key(): string
    {
        return "{$this->subjectTable}:{$this->subjectId}:{$this->reason}";
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'subject_table' => $this->subjectTable,
            'subject_id' => $this->subjectId,
            'customer_id' => $this->customerId,
            'customer_business_id' => $this->customerBusinessId,
            'row_business_id' => $this->rowBusinessId,
            'merge_id' => $this->mergeId,
            'reason' => $this->reason,
            'detail' => $this->detail,
        ];
    }
}
