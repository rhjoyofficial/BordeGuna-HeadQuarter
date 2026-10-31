<?php

namespace App\Models;

use App\Models\Concerns\VisibleByBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Competitor or market notes assembled only from filed, cited research claims (docs/145-a). Append-only. */
class ResearchDigest extends Model
{
    use HasUuids, VisibleByBusiness;

    protected $table = 'research_digests';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['artifact_ids' => 'array', 'entries' => 'array', 'checks' => 'array'];
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

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
