<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiRun extends Model
{
    use HasFactory;

    protected $fillable = ['correlation_id', 'agent_key', 'business_id', 'brand_id', 'workflow_ref', 'task', 'provider', 'model', 'model_tier', 'prompt_version', 'input_tokens', 'output_tokens', 'cached_tokens', 'cache_write_tokens', 'latency_ms', 'estimated_cost_usd', 'status', 'error', 'input_ref', 'output_ref', 'started_at', 'finished_at',
        // M4 (docs/52): a fallback, a downgrade and a reported cost all have to be visible in the
        // data, not only in a log line.
        'actual_cost_usd', 'cost_source', 'degraded_from_tier', 'attempt', 'routing_reason'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime', 'finished_at' => 'datetime',
            'estimated_cost_usd' => 'decimal:6', 'actual_cost_usd' => 'decimal:6',
            'attempt' => 'integer',
        ];
    }

    public function toolCalls(): HasMany
    {
        return $this->hasMany(ToolCall::class);
    }
}
