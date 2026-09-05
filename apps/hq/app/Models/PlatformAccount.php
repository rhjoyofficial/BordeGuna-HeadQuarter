<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformAccount extends Model
{
    use HasFactory;

    protected $fillable = ['brand_id', 'platform', 'account_ref', 'name', 'capabilities', 'scopes', 'approval_status', 'quotas', 'restrictions', 'credential_id', 'is_active'];

    protected function casts(): array
    {
        return ['capabilities' => 'array', 'scopes' => 'array', 'quotas' => 'array', 'restrictions' => 'array', 'is_active' => 'boolean'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(IntegrationCredential::class, 'credential_id');
    }

    /** True only when the capability row says so. Never assume. */
    public function supports(string $operation): bool
    {
        return (bool) data_get($this->capabilities, $operation, false);
    }
}
