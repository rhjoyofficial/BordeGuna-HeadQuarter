<?php

namespace App\Models;

use App\Models\Concerns\VisibleByBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A registered skill (docs/10, docs/145-c): a named procedure limited to the tools it lists. Status moves only by people. */
class Skill extends Model
{
    use HasUuids, VisibleByBusiness;

    protected $table = 'skills';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['required_tools' => 'array', 'approved_at' => 'datetime', 'activated_at' => 'datetime', 'retired_at' => 'datetime'];
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
