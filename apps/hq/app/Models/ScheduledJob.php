<?php

namespace App\Models;

use App\Scheduling\Enums\ApprovalState;
use App\Scheduling\Enums\JobKind;
use App\Scheduling\Enums\JobState;
use App\Scheduling\Enums\MissedPolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Durable scheduled job definition (doc 21). The scheduler tick reads this table;
 * every execution is recorded as a JobRun (one per logical slot).
 */
class ScheduledJob extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'business_id', 'brand_id', 'name', 'kind', 'cron_expression', 'run_at', 'interval_seconds',
        'event_type', 'timezone', 'tool_key', 'payload', 'owner_user_id', 'approval_state',
        'retry_max', 'retry_backoff_seconds', 'missed_policy', 'state', 'next_run_at', 'last_run_at',
        'last_status', 'created_from', 'nl_source_text',
    ];

    protected function casts(): array
    {
        return [
            'kind' => JobKind::class,
            'state' => JobState::class,
            'approval_state' => ApprovalState::class,
            'missed_policy' => MissedPolicy::class,
            'payload' => 'array',
            'run_at' => 'datetime',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }

    /** HasUuids would otherwise treat `id` as the uuid column. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(JobRun::class)->orderByDesc('scheduled_for');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function isRunnable(): bool
    {
        return $this->state === JobState::Active && $this->approval_state === ApprovalState::Approved;
    }

    public function isRecurring(): bool
    {
        return in_array($this->kind, [JobKind::Cron, JobKind::Interval], true);
    }
}
