<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One complete day of a shadow period, as it was read the morning after (docs/77 C.2.4).
 *
 * ⚠ Written once. The numbers are derived from the ledgers (`agent_runs`, `tool_calls`, `ai_runs`),
 * which stay the source; this row is the snapshot a later autonomy decision can cite without the
 * risk that a re-run of the query tells a different story about the same day.
 */
class ShadowEvidenceDay extends Model
{
    protected $fillable = ['shadow_period_id', 'day', 'window_start', 'window_end', 'metrics', 'violations', 'computed_at'];

    protected function casts(): array
    {
        return [
            'day' => 'date:Y-m-d',
            'window_start' => 'immutable_datetime',
            'window_end' => 'immutable_datetime',
            'computed_at' => 'immutable_datetime',
            'metrics' => 'array',
            'violations' => 'array',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(ShadowPeriod::class, 'shadow_period_id');
    }
}
