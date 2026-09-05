<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerIdentity extends Model
{
    use HasFactory;

    protected $fillable = ['customer_id', 'channel', 'external_id', 'source_store_id', 'confidence', 'verified_at', 'last_seen_at'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'last_seen_at' => 'datetime', 'confidence' => 'decimal:2'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
