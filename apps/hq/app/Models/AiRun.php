<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiRun extends Model
{
    use HasFactory;

    protected $fillable = ['correlation_id', 'agent_key', 'business_id', 'brand_id', 'workflow_ref', 'task', 'provider', 'model', 'model_tier', 'prompt_version', 'input_tokens', 'output_tokens', 'cached_tokens', 'latency_ms', 'estimated_cost_usd', 'status', 'error', 'input_ref', 'output_ref', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'estimated_cost_usd' => 'decimal:6'];
    }

    public function toolCalls(): HasMany
    {
        return $this->hasMany(ToolCall::class);
    }
}
