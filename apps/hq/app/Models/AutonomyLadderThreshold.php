<?php

namespace App\Models;

use App\Models\Concerns\VisibleByBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Joy's numbers for one tool in one business (docs/145-d). Never edited: retired and replaced. */
class AutonomyLadderThreshold extends Model
{
    use HasUuids, VisibleByBusiness;

    protected $table = 'autonomy_ladder_thresholds';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['retired_at' => 'datetime'];
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
