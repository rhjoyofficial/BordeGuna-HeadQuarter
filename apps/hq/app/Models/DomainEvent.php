<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Inbox/outbox row for the event foundation (doc 07). One row per event, deduplicated by
 * dedupe_key; delivery to handlers is at-least-once with a bounded attempt counter.
 */
class DomainEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'events';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DEAD = 'dead';

    protected $fillable = [
        'event_id', 'type', 'direction', 'source', 'aggregate_type', 'aggregate_id', 'business_id',
        'payload', 'dedupe_key', 'schema_version', 'occurred_at', 'processed_at', 'attempts',
        'max_attempts', 'next_attempt_at', 'status', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'processed_at' => 'datetime',
            'next_attempt_at' => 'datetime',
        ];
    }

    public function isDue(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_FAILED], true)
            && ($this->next_attempt_at === null || $this->next_attempt_at->isPast());
    }
}
