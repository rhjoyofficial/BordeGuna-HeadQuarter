<?php

namespace App\Models;

use App\Models\Concerns\VisibleByBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One avatar generation request, refused or not, and the consent it rested on (docs/145-b). */
class AvatarGeneration extends Model
{
    use HasUuids, VisibleByBusiness;

    protected $table = 'avatar_generations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [];
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

    public function consent(): BelongsTo
    {
        return $this->belongsTo(AvatarConsent::class, 'consent_id');
    }
}
