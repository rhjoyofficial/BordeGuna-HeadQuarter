<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One way of recognising one customer of ONE business (docs/122, ruling docs/120 A2 Option A).
 *
 * ⛔ `business_id` is part of the identity: the same phone in LaLaDia and in Bionic is two rows under two
 * customers, and the database holds it so (composite key to the customer's business, the store's business,
 * unique (business, channel, external id, store) NULLS NOT DISTINCT over live rows). Every lookup by channel
 * and external id names the business (`scopeKey`); a structural test fails on one that does not.
 *
 * A quarantined row (`quarantined_at`) is evidence the migration or a person could not place without guessing.
 * It keeps its customer and references and is invisible to every lookup (`scopeLive`).
 */
class CustomerIdentity extends Model
{
    use HasFactory;

    public const QUARANTINE_REASONS = ['store_business_mismatch', 'contradicted_by_evidence', 'duplicate_same_customer', 'duplicate_ambiguous_owner'];

    protected $fillable = ['customer_id', 'business_id', 'channel', 'external_id', 'source_store_id', 'confidence', 'verified_at', 'last_seen_at'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'last_seen_at' => 'datetime', 'confidence' => 'decimal:2', 'quarantined_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** Rows a lookup may use: not quarantined. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('quarantined_at'));
    }

    /**
     * The one live row for a key IN ONE BUSINESS. A null store means a store-less identity (phone, e-mail,
     * a channel id); it is matched as NULL, never as "any store".
     */
    public function scopeKey(Builder $query, int $businessId, string $channel, string $externalId, ?int $storeId = null): Builder
    {
        return $query->live()
            ->where($query->qualifyColumn('business_id'), $businessId)
            ->where($query->qualifyColumn('channel'), $channel)
            ->where($query->qualifyColumn('external_id'), $externalId)
            ->when($storeId !== null,
                fn (Builder $q) => $q->where($query->qualifyColumn('source_store_id'), $storeId),
                fn (Builder $q) => $q->whereNull($query->qualifyColumn('source_store_id')));
    }
}
