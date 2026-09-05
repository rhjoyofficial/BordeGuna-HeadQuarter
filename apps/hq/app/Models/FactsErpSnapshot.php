<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FactsErpSnapshot extends Model
{
    protected $table = 'facts_erp_snapshots';

    protected $fillable = ['business_id', 'kind', 'payload', 'as_of', 'polled_at', 'job_run_ref'];

    protected $casts = ['payload' => 'array', 'as_of' => 'datetime', 'polled_at' => 'datetime'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
