<?php

namespace App\Erp\Dto;

/**
 * The low-stock read, with the ERP's own `as_of` alongside the rows.
 *
 * The rows used to come back on their own and the polling tool stamped them with `now()`, which
 * made `as_of` mean "when HQ asked" for low stock and "when the ERP answered" for the overview -
 * two different facts wearing one column name. Carrying the ERP's timestamp keeps the column
 * meaning one thing everywhere: the moment the source says this was true.
 *
 * @param  ErpLowStockLine[]  $lines
 */
final readonly class ErpLowStock
{
    public function __construct(
        public array $lines,
        public ?string $asOf,
    ) {}

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(fn (ErpLowStockLine $l) => $l->toArray(), $this->lines);
    }

    public function count(): int
    {
        return count($this->lines);
    }
}
