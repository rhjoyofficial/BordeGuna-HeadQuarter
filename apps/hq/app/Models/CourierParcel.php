<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** HQ's own record of where one courier consignment is. Written only by `ParcelLedger`; the ERP is never touched. */
class CourierParcel extends Model
{
    protected $fillable = ['uuid', 'business_id', 'provider', 'consignment_ref', 'merchant_order_ref', 'facts_order_id', 'status', 'raw_status', 'last_event_at', 'last_polled_at', 'poll_failures', 'terminal'];

    protected function casts(): array
    {
        return ['last_event_at' => 'datetime', 'last_polled_at' => 'datetime', 'terminal' => 'boolean'];
    }

    public function events(): HasMany
    {
        return $this->hasMany(CourierParcelEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(FactsOrder::class, 'facts_order_id');
    }
}
