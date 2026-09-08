<?php

namespace App\Models;

use App\Ai\ModelTier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One model, registered under one tier, with its price and its limits.
 *
 * Maps to `model_tiers` (docs/48 J.1). The class is named for what a row IS, while the table is
 * named for what the collection is FOR, which is choosing within a tier.
 *
 * ⚠ **This is the only place a model id may live outside the driver layer**, and a grep test keeps
 * it that way. Everything else names a `ModelTier`.
 *
 * @property string $tier
 * @property string $provider
 * @property string $model_id
 */
class AiModel extends Model
{
    protected $table = 'model_tiers';

    protected $fillable = [
        'tier', 'provider', 'model_id', 'display_name',
        'context_window', 'max_output_tokens', 'capabilities',
        'input_price_per_mtok', 'output_price_per_mtok',
        'cache_read_price_per_mtok', 'cache_write_price_per_mtok',
        'priority', 'is_active', 'notes', 'pricing_verified_on',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'context_window' => 'integer',
            'max_output_tokens' => 'integer',
            'priority' => 'integer',
            'is_active' => 'boolean',
            'pricing_verified_on' => 'date',
            // Money is decimal all the way through. A float price per million tokens accumulates
            // error across thousands of calls in exactly the direction nobody notices.
            'input_price_per_mtok' => 'decimal:4',
            'output_price_per_mtok' => 'decimal:4',
            'cache_read_price_per_mtok' => 'decimal:4',
            'cache_write_price_per_mtok' => 'decimal:4',
        ];
    }

    public function tierEnum(): ModelTier
    {
        return ModelTier::from($this->tier);
    }

    /**
     * Candidates for a tier, best first.
     *
     * Ordering is priority, then cheaper input price. The tie-break matters: a row added without a
     * priority must not be able to become the expensive default by accident.
     */
    public function scopeForTier(Builder $query, ModelTier $tier): Builder
    {
        return $query->where('tier', $tier->value)
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('input_price_per_mtok');
    }

    /** @param list<string> $required */
    public function satisfies(array $required, int $minContextTokens = 0): bool
    {
        if ($this->context_window < $minContextTokens) {
            return false;
        }

        $has = $this->capabilities ?? [];

        foreach ($required as $capability) {
            if (! in_array($capability, $has, true)) {
                return false;
            }
        }

        return true;
    }
}
