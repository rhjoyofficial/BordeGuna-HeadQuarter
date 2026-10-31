<?php

namespace App\Models;

use App\Models\Concerns\VisibleByBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One delegation asked for, and the narrowed authority it resolved to (docs/145-c). Append-only. */
class AgentDelegation extends Model
{
    use HasUuids, VisibleByBusiness;

    public const UPDATED_AT = null;

    protected $table = 'agent_delegations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective' => 'array', 'created_at' => 'datetime'];
    }

    /** HasUuids would otherwise treat `id` as the uuid column. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
