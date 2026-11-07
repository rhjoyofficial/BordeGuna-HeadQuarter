<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Support\Authorization\BusinessScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * `->visibleTo($user)`: narrows a query to the businesses the person may see (docs/48 E.4), through the
 * one `BusinessScope` every screen uses. A row with a null business_id is group-wide and only visible to a
 * person who is not limited to some businesses.
 */
trait VisibleByBusiness
{
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $scope = app(BusinessScope::class)->all($user);

        return $scope === null ? $query : $query->whereIn($query->qualifyColumn('business_id'), $scope);
    }
}
