<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One object's delivery on one day, as the ad platform counted it (Phase 5 read model, ADR-007).
 * `third_party` provenance always: Meta's figure, never the business's truth (ADR-024).
 */
class FactsAdSpend extends Model
{
    protected $table = 'facts_ad_spend';

    protected $fillable = [
        'platform_account_id', 'business_id', 'brand_id', 'store_id', 'platform', 'account_ref', 'level', 'object_ref',
        'object_name', 'campaign_ref', 'adset_ref', 'ad_ref', 'spend_date', 'currency', 'spend', 'impressions', 'clicks',
        'reach', 'purchases', 'purchase_value', 'provenance', 'source', 'api_version', 'fetched_at', 'sync_ref',
    ];

    protected function casts(): array
    {
        return [
            'spend_date' => 'date:Y-m-d',
            'spend' => 'decimal:2',
            'purchase_value' => 'decimal:2',
            'impressions' => 'integer',
            'clicks' => 'integer',
            'reach' => 'integer',
            'purchases' => 'integer',
            'fetched_at' => 'datetime',
        ];
    }

    public function platformAccount(): BelongsTo
    {
        return $this->belongsTo(PlatformAccount::class);
    }
}
