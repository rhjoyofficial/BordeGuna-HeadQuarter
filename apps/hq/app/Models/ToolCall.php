<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToolCall extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['ai_run_id', 'tool_key', 'input', 'output', 'policy_result', 'approval_id', 'idempotency_key', 'duration_ms', 'status', 'error'];

    protected function casts(): array
    {
        return ['input' => 'array', 'output' => 'array', 'created_at' => 'datetime'];
    }

    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class);
    }
}
