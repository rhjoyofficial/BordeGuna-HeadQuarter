<?php

namespace App\Models;

use App\Scheduling\Enums\RunStatus;
use App\Support\Authorization\BusinessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One logical run of a scheduled job for one slot. Unique on (scheduled_job_id, scheduled_for).
 * Retries re-attempt THIS row (attempt counter + attempts log); they never create a second row
 * for the same slot. Execution is at-least-once (ADR-011).
 */
class JobRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'scheduled_job_id', 'scheduled_for', 'trigger', 'event_id', 'attempt', 'status', 'started_at',
        'finished_at', 'output', 'error', 'attempts', 'correlation_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'scheduled_for' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'output' => 'array',
            'attempts' => 'array',
        ];
    }

    /** A run is visible when its job is (docs/136): `job_runs` carries no business of its own. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! app(BusinessScope::class)->isRestricted($user)) {
            return $query;
        }

        return $query->whereHas('job', fn (Builder $job) => $job->visibleTo($user));
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(ScheduledJob::class, 'scheduled_job_id');
    }

    /** The domain event that started this run; null for a clock slot, a run-now or a retry of one. */
    public function event(): BelongsTo
    {
        return $this->belongsTo(DomainEvent::class, 'event_id', 'event_id');
    }

    public function appendAttempt(array $entry): void
    {
        $log = $this->attempts ?? [];
        $log[] = $entry;
        $this->attempts = $log;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [RunStatus::Succeeded, RunStatus::Dead], true);
    }
}
