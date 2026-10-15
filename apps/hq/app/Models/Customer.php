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

    /**
     * Follow merges to the surviving record, never across a business (docs/122, docs/117): a merge pointer
     * into another business (only pre-guard data can hold one) stops at the last row of this business.
     */
    public function canonical(): Customer
    {
        $c = $this;
        $hops = 0;
        while ($c->merged_into_id && $hops++ < 10) {
            $next = $c->mergedInto;
            if ($next === null || (int) $next->business_id !== (int) $c->business_id) {
                break;
            }
            $c = $next;
        }

        return $c;
    }
}
