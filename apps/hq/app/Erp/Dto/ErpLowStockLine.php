<?php

namespace App\Erp\Dto;

final readonly class ErpLowStockLine
{
    public function __construct(
        public int $productId,
        public string $sku,
        public string $name,
        public float $onHand,
        public ?float $threshold,
    ) {}

    public static function fromApi(array $row): self
    {
        return new self((int) $row['product_id'], (string) $row['sku'], (string) $row['name'], (float) $row['on_hand'], isset($row['threshold']) ? (float) $row['threshold'] : null);
    }

    public function toArray(): array
    {
        return ['product_id' => $this->productId, 'sku' => $this->sku, 'name' => $this->name, 'on_hand' => $this->onHand, 'threshold' => $this->threshold];
    }
}
