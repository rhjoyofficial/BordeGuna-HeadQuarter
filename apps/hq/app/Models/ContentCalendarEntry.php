<?php

namespace App\Models;

use App\Models\Concerns\VisibleByBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A planned post for a brand on a date (docs/145-a). A plan, never a post. */
class ContentCalendarEntry extends Model
{
    use HasUuids, VisibleByBusiness;

    protected $table = 'content_calendar_entries';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['product_lines' => 'array', 'planned_for' => 'date'];
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

    public function draft(): BelongsTo
    {
        return $this->belongsTo(ContentDraft::class, 'draft_id');
    }
}
