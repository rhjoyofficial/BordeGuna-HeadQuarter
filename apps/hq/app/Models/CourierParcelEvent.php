<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourierParcelEvent extends Model
{
    protected $fillable = ['courier_parcel_id', 'business_id', 'occurred_at', 'status', 'raw_status', 'message', 'source', 'dedupe_key'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function parcel(): BelongsTo
    {
        return $this->belongsTo(CourierParcel::class, 'courier_parcel_id');
    }
}
