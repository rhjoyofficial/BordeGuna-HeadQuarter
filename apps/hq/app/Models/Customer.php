<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = ['business_id', 'primary_phone_canonical', 'name', 'email', 'first_seen_at', 'last_seen_at', 'orders_count', 'lifetime_value', 'consent', 'attributes', 'merged_into_id'];

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'consent' => 'array', 'attributes' => 'array', 'lifetime_value' => 'decimal:2'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function identities(): HasMany
    {
        return $this->hasMany(CustomerIdentity::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(FactsOrder::class);
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'merged_into_id');
    }

    /** Follow merges to the surviving record. */
    public function canonical(): Customer
    {
        $c = $this;
        $hops = 0;
        while ($c->merged_into_id && $hops++ < 10) {
            $c = $c->mergedInto;
        }

        return $c;
    }
}
