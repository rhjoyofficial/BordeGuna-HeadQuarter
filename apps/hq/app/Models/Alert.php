<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One condition HQ found wrong, from the first check that saw it to the check that found it gone
 * (docs/78 B.2, docs/48 N.5). Open while `resolved_at` is null; at most one open row per fingerprint.
 */
class Alert extends Model
{
    public const SEVERITIES = ['info', 'warning', 'critical'];

    protected $fillable = [
        'rule', 'fingerprint', 'severity', 'subject', 'body', 'context', 'paged_by', 'occurrences',
        'clear_checks', 'first_seen_at', 'last_seen_at', 'notified_at', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurrences' => 'integer',
            'clear_checks' => 'integer',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'notified_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }

    /** HQ's own engine sends this one; a `paged_by` alert is recorded here and paged elsewhere. */
    public function pagedByHq(): bool
    {
        return $this->paged_by === null;
    }
}
