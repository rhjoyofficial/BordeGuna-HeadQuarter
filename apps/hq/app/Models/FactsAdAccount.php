<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A connected ad account as last READ: currency, time zone, status and the owner's spending limit.
 *
 * ⛔ The spending limit here is what the platform SAID the owner set. HQ never writes it at the
 * platform (ADR-050 clarification 3), and room under it is never permission to spend (clarification 9).
 */
class FactsAdAccount extends Model
{
    protected $table = 'facts_ad_accounts';

    protected $fillable = [
        'platform_account_id', 'business_id', 'brand_id', 'platform', 'account_ref', 'name', 'currency', 'timezone', 'status',
        'spend_cap_minor', 'amount_spent_minor', 'spend_cap', 'amount_spent', 'provenance', 'source', 'api_version',
        'fetched_at', 'sync_ref',
    ];

    protected function casts(): array
    {
        return [
            'spend_cap_minor' => 'integer',
            'amount_spent_minor' => 'integer',
            'spend_cap' => 'decimal:2',
            'amount_spent' => 'decimal:2',
            'fetched_at' => 'datetime',
        ];
    }

    public function platformAccount(): BelongsTo
    {
        return $this->belongsTo(PlatformAccount::class);
    }
}
