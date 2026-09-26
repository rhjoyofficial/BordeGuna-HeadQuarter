<?php

namespace App\Ads\Dto;

/**
 * A campaign, ad set or ad as its platform describes it, read-only.
 *
 * Budgets are decimal strings in the account currency's major unit (Meta states them in basic
 * units, converted by `AdMoney`), or null when the object has none (an ad has no budget; an ad set
 * under a campaign budget has none of its own).
 */
final readonly class AdObjectRead
{
    public function __construct(
        /** campaign | adset | ad */
        public string $level,
        public string $ref,
        public ?string $name,
        public ?string $status,
        public ?string $effectiveStatus,
        public ?string $campaignRef = null,
        public ?string $adsetRef = null,
        public ?string $objective = null,
        public ?string $dailyBudget = null,
        public ?string $lifetimeBudget = null,
        public ?string $spendCap = null,
        /** @var list<string> */
        public array $specialAdCategories = [],
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'level' => $this->level,
            'ref' => $this->ref,
            'name' => $this->name,
            'status' => $this->status,
            'effective_status' => $this->effectiveStatus,
            'campaign_ref' => $this->campaignRef,
            'adset_ref' => $this->adsetRef,
            'objective' => $this->objective,
            'daily_budget' => $this->dailyBudget,
            'lifetime_budget' => $this->lifetimeBudget,
            'spend_cap' => $this->spendCap,
            'special_ad_categories' => $this->specialAdCategories,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ], static fn ($v) => $v !== null);
    }
}
