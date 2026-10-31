<?php

namespace App\Models;

use App\Models\Concerns\VisibleByBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A written consent for a person's face or voice (docs/20, docs/145-b). Revocation is final and immediate. */
class AvatarConsent extends Model
{
    use HasUuids, VisibleByBusiness;

    protected $table = 'avatar_consents';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['modalities' => 'array', 'permitted_uses' => 'array', 'brand_ids' => 'array', 'signed_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** HasUuids would otherwise treat `id` as the uuid column. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
