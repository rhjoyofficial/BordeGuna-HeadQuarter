<?php

namespace App\Ads\Dto;

/**
 * One day of one object's delivery, as the platform counts it.
 *
 * ⚠ **The platform's own count, never the business's truth** (ADR-024, `docs/84` C.17). A purchase
 * here is Meta's attributed purchase, which overstates cash-on-delivery sales (about a third were
 * cancelled historically, `docs/16`); delivered orders come from the ERP and the shops, never from
 * this row.
 */
final readonly class AdInsightRow
{
    public function __construct(
        /** campaign | adset | ad */
        public string $level,
        public string $objectRef,
        public ?string $objectName,
        public ?string $campaignRef,
        public ?string $adsetRef,
        public ?string $adRef,
        /** Y-m-d, the day in the ACCOUNT's time zone. */
        public string $date,
        public string $currency,
        /** Decimal string, major unit. */
        public string $spend,
        public int $impressions = 0,
        public int $clicks = 0,
        public int $reach = 0,
        /** Meta's attributed purchase count, or null when none was reported. */
        public ?int $purchases = null,
        /** Meta's attributed purchase value (major unit), or null. */
        public ?string $purchaseValue = null,
    ) {}

    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'object_ref' => $this->objectRef,
            'object_name' => $this->objectName,
            'campaign_ref' => $this->campaignRef,
            'adset_ref' => $this->adsetRef,
            'ad_ref' => $this->adRef,
            'date' => $this->date,
            'currency' => $this->currency,
            'spend' => $this->spend,
            'impressions' => $this->impressions,
            'clicks' => $this->clicks,
            'reach' => $this->reach,
            'purchases' => $this->purchases,
            'purchase_value' => $this->purchaseValue,
        ];
    }
}
