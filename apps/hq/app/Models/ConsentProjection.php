<?php

namespace App\Models;

use App\Consent\ConsentPurpose;
use App\Consent\ConsentState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The current consent position, derived from `consent_events` (ADR-021).
 *
 * ⚠ Nothing here is authoritative. Every row can be rebuilt from the ledger, and
 * `ConsentProjector::rebuild()` does exactly that. If this table and the ledger ever disagree, the
 * ledger is right by definition and this table is a bug.
 */
class ConsentProjection extends Model
{
    protected $table = 'consent_state';

    protected $fillable = [
        'customer_id', 'business_id', 'brand_id', 'identity_id', 'channel', 'purpose', 'state',
        'effective_from', 'expires_at', 'last_event_id',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => ConsentPurpose::class,
            'state' => ConsentState::class,
            'effective_from' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(CustomerIdentity::class, 'identity_id');
    }

    public function lastEvent(): BelongsTo
    {
        return $this->belongsTo(ConsentEvent::class, 'last_event_id');
    }
}
