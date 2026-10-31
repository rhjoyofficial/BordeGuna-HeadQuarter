<?php

namespace App\Models;

use App\Models\Concerns\VisibleByBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of what may or may not be said about a product line (docs/145-a). Never edited: retired and replaced. */
class ContentClaimsRule extends Model
{
    use HasUuids, VisibleByBusiness;

    protected $table = 'content_claims_rules';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['phrases' => 'array', 'retired_at' => 'datetime'];
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
