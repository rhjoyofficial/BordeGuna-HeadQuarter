<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** docs/143: one step of a Do-mode plan. */
class DoPlanStep extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['args' => 'array', 'outcome' => 'array', 'effectful' => 'boolean', 'finished_at' => 'datetime'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(DoPlan::class, 'do_plan_id');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }
}
