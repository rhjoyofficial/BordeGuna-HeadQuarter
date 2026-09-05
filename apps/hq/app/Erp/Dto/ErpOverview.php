<?php

namespace App\Erp\Dto;

/**
 * The ERP's overview for one business. A null section means the token lacked the ability for it,
 * which is different from a zero: callers must not render "0 open tickets" for "not allowed to ask".
 */
final readonly class ErpOverview
{
    public function __construct(
        public int $erpBusinessId,
        public ?array $sales,
        public ?array $inventory,
        public ?array $purchasing,
        public ?array $approvals,
        public ?array $tickets,
        public ?string $asOf,
    ) {}

    public static function fromApi(int $erpBusinessId, array $data, ?string $asOf): self
    {
        return new self(
            $erpBusinessId,
            $data['sales'] ?? null,
            $data['inventory'] ?? null,
            $data['purchasing'] ?? null,
            $data['approvals'] ?? null,
            $data['tickets'] ?? null,
            $asOf,
        );
    }

    public function toArray(): array
    {
        return [
            'erp_business_id' => $this->erpBusinessId,
            'sales' => $this->sales,
            'inventory' => $this->inventory,
            'purchasing' => $this->purchasing,
            'approvals' => $this->approvals,
            'tickets' => $this->tickets,
            'as_of' => $this->asOf,
        ];
    }
}
