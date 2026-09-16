<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a group of removed detail rows became (docs/78 B.2): their count and sums for one Dhaka day,
 * one source table and one grouping, as written by one retention pass. The day's total for a grouping
 * is the sum over passes; the id range says which detail rows the row stands for.
 */
class DailyAggregate extends Model
{
    protected $fillable = [
        'day', 'source_table', 'dimension', 'dimension_hash', 'rows', 'sum_cost_usd', 'sum_duration_ms',
        'sum_tokens_in', 'sum_tokens_out', 'measures', 'min_source_id', 'max_source_id', 'retention_run_id',
    ];

    protected function casts(): array
    {
        return [
            'day' => 'immutable_date',
            'dimension' => 'array',
            'measures' => 'array',
            'rows' => 'integer',
            'sum_cost_usd' => 'decimal:6',
            'sum_duration_ms' => 'integer',
            'sum_tokens_in' => 'integer',
            'sum_tokens_out' => 'integer',
            'min_source_id' => 'integer',
            'max_source_id' => 'integer',
        ];
    }

    public function retentionRun(): BelongsTo
    {
        return $this->belongsTo(RetentionRun::class);
    }
}
