<?php

namespace App\Models;

use App\Support\Authorization\BusinessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a business's media budget ledger: an estimate reserved BEFORE a paid provider is called, then committed, released or
 * reconciled against the provider's reported cost. The estimate and the actual are kept side by side.
 */
class MediaBudgetEntry extends Model
{
    public const RESERVED = 'reserved';

    public const COMMITTED = 'committed';

    public const RELEASED = 'released';

    public const RECONCILED = 'reconciled';

    protected $table = 'media_budget_ledger';

    protected $fillable = ['uuid', 'business_id', 'period', 'kind', 'provider', 'tool_key', 'reference', 'estimate_amount', 'estimate_currency',
        'fx_rate_to_bdt', 'fx_rate_id', 'estimate_bdt', 'actual_amount', 'actual_currency', 'actual_bdt', 'state', 'basis', 'requested_by_user_id',
        'reconciled_by_user_id', 'reconciled_at'];

    protected function casts(): array
    {
        return ['reconciled_at' => 'datetime'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return app(BusinessScope::class)->restrict($query, $user, $query->qualifyColumn('business_id'));
    }
}
