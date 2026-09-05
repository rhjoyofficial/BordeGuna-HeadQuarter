<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Brand extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['business_id', 'code', 'name', 'slug', 'persona', 'guidelines', 'is_active'];

    protected function casts(): array
    {
        return ['persona' => 'array', 'guidelines' => 'array', 'is_active' => 'boolean'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function platformAccounts(): HasMany
    {
        return $this->hasMany(PlatformAccount::class);
    }
}
