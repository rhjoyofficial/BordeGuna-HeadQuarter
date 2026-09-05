<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Store extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['business_id', 'name', 'slug', 'adapter', 'base_url', 'capabilities', 'credential_id', 'is_active'];

    protected function casts(): array
    {
        return ['capabilities' => 'array', 'is_active' => 'boolean'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(IntegrationCredential::class, 'credential_id');
    }

    public function productOwners(): BelongsToMany
    {
        return $this->belongsToMany(Business::class, 'store_product_owners')->withTimestamps();
    }

    public function sellers(): HasMany
    {
        return $this->hasMany(Seller::class);
    }
}
