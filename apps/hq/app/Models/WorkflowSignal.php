<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A signal delivered to a run, buffered until a wait takes it; unique per (instance, dedupe key). */
class WorkflowSignal extends Model
{
    protected $fillable = ['workflow_instance_id', 'name', 'payload', 'dedupe_key', 'consumed_by_step', 'consumed_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'consumed_at' => 'immutable_datetime'];
    }
}
