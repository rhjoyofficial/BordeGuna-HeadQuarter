<?php

namespace App\Support\Authorization;

use App\Models\User;
use App\Models\UserBusinessScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one place that answers "which businesses may this human see" (docs/48 E.4).
 *
 * ⚠ NO ROWS MEANS GROUP-WIDE. That is what preserves every account that exists today: Joy has no
 * scope rows, so nothing about his access changes when this ships. A user gains a restriction by
 * gaining rows, never by the absence of them, so a failure to seed something cannot silently lock
 * an operator out of his own business.
 *
 * Deliberately NOT spatie's teams feature. That would re-key every permission check in the
 * application for a requirement that today has one user (V.11), and re-keying a working
 * authorization system is a large risk taken for an imagined case.
 *
 * The rule the gateway enforces on top of this: a tool whose scope is `business` or `store` must
 * name a business, and a restricted user may not name one outside their rows. A tool whose scope is
 * `hq` needs no business, and if it reads across businesses it is that tool's job to call `all()`.
 *
 * ⛔ **Nothing here is cached, and nothing may be** (docs/81 P0-2). The rows were once kept for 60
 * seconds in the shared cache store. Nothing in HQ writes `user_business_scopes` (rows are made by
 * hand), so no invalidation could ever be hung on a write; the store is shared by every container;
 * and because "no rows" was cached as an empty list, a person given their FIRST row stayed
 * group-wide for a minute, at the gateway too. Every answer is now read from the table: one indexed
 * query. A memo on this object would be wrong as well, because a queue worker and an agent run
 * keep one object across many decisions.
 */
final class BusinessScope
{
    /**
     * The business ids a user is limited to, or null for group-wide.
     *
     * @return list<int>|null
     */
    public function all(User $user): ?array
    {
        $ids = UserBusinessScope::query()->where('user_id', $user->getKey())->orderBy('business_id')->pluck('business_id')->all();

        return $ids === [] ? null : array_map('intval', $ids);
    }

    public function isRestricted(User $user): bool
    {
        return $this->all($user) !== null;
    }

    /**
     * May this user act on this business?
     *
     * A null business means the call is not business-specific, which an unrestricted and a
     * restricted user may both make; whether the tool NEEDS a business is a separate question the
     * gateway asks from the descriptor's scope.
     */
    public function allows(User $user, ?int $businessId): bool
    {
        $scope = $this->all($user);
        if ($scope === null) {
            return true;
        }
        if ($businessId === null) {
            return true;
        }

        return in_array($businessId, $scope, true);
    }

    /** Narrows a query to the user's businesses. A no-op for an unrestricted user. */
    public function restrict(Builder $query, User $user, string $column = 'business_id'): Builder
    {
        $scope = $this->all($user);

        return $scope === null ? $query : $query->whereIn($column, $scope);
    }
}
