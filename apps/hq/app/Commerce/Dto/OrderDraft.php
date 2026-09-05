<?php

namespace App\Commerce\Dto;

final readonly class OrderDraft
{
    public function __construct(
        public DraftCustomer $customer,
        public DraftAddress $address,
        public array $lines,
        public string $paymentMethod = 'cod',
        public ?string $couponCode = null,
        public ?string $notes = null,
        public string $source = 'hq',
        public ?string $conversationRef = null,
        public ?string $landingSlug = null,
    ) {}

    /** The checkout payload the Laravel shop validates (docs/35 section E). */
    public function toShopPayload(): array
    {
        $notes = trim(($this->notes ?? '').($this->conversationRef ? " [hq:{$this->conversationRef}]" : ''));

        return array_filter([
            'items' => array_map(fn (DraftLine $l) => array_filter(['variant_id' => $l->variantId, 'combo_id' => $l->comboId, 'quantity' => $l->quantity], fn ($v) => $v !== null), $this->lines),
            'customer_name' => $this->customer->name,
            'customer_phone' => $this->customer->phone,
            'customer_email' => $this->customer->email,
            'address_line' => $this->address->line,
            'city' => $this->address->city ?? $this->address->district,
            'district' => $this->address->district,
            'thana' => $this->address->thana,
            'area' => $this->address->area,
            'zone_id' => $this->address->zoneId,
            'payment_method' => $this->paymentMethod,
            'coupon_code' => $this->couponCode,
            'notes' => $notes !== '' ? $notes : null,
            'agree_terms' => true,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
