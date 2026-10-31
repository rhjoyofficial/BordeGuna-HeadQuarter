<?php

namespace App\Models;

use App\Support\Authorization\BusinessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A PAUSED ad campaign draft HQ prepared at the platform. Never active; a person switches it on in Ads Manager. */
class AdsDraft extends Model
{
    protected $fillable = ['uuid', 'business_id', 'brand_id', 'ad_account_ref', 'name', 'spec', 'checks', 'status', 'platform_refs', 'error', 'created_by'];

    protected function casts(): array
    {
        return ['spec' => 'array', 'checks' => 'array', 'platform_refs' => 'array'];
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return app(BusinessScope::class)->restrict($query, $user, $query->qualifyColumn('business_id'));
    }
}
