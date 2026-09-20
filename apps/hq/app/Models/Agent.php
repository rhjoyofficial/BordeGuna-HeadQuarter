<?php

namespace App\Models;

use App\Gateway\Descriptor\AutonomyLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An AI agent as configuration (docs/08: agents are rows, not classes).
 *
 * M1 creates no agent rows and runs no agent. The table and this model exist so that
 * an agent's acting identity has something real to point at and so the intersection rule is written,
 * tested and correct before anything depends on it. The Executive and Analytics agents are M5.
 *
 * Two defaults are deliberately the safe direction: `status` is `paused` and `max_autonomy_level`
 * is A0 shadow. An agent row created by an accident or a bad seed does nothing.
 */
class Agent extends Model
{
    protected $fillable = [
        'key', 'name', 'purpose', 'status', 'enabling_user_id', 'role_key', 'allowed_tools',
        'business_scope', 'brand_scope', 'memory_scope', 'model_tier', 'prompt_version',
        'max_autonomy_level', 'daily_cost_cap_usd', 'daily_call_cap', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'allowed_tools' => 'array',
            'business_scope' => 'array',
            'brand_scope' => 'array',
            'memory_scope' => 'array',
            'max_autonomy_level' => AutonomyLevel::class,
            'daily_cost_cap_usd' => 'decimal:4',
        ];
    }

    public function enablingUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enabling_user_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * The agent's tool list is a MAXIMUM, not an effective set: the gateway still checks the
     * enabling human's permission, the business scope and policy on every call.
     *
     * A null or empty list means no tools. Fail closed: an agent that was never told what it may
     * use may use nothing, rather than everything.
     */
    public function mayUseTool(string $key): bool
    {
        return in_array($key, (array) ($this->allowed_tools ?? []), true);
    }

    public function autonomy(): AutonomyLevel
    {
        return $this->max_autonomy_level ?? AutonomyLevel::A0;
    }
}
