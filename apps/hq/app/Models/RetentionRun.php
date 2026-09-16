<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One retention pass (docs/78 B.2): the policy it applied, what it rolled up and removed per table,
 * and how it ended. Every `daily_aggregates` row names the pass that wrote it.
 */
class RetentionRun extends Model
{
    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    /** A pass that was still `running` long after it could have been: the process died. */
    public const ABANDONED = 'abandoned';

    protected $fillable = ['uuid', 'policy_version', 'policy', 'status', 'summary', 'error', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return [
            'policy' => 'array',
            'summary' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function aggregates(): HasMany
    {
        return $this->hasMany(DailyAggregate::class);
    }
}
