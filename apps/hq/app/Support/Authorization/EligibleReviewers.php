<?php

namespace App\Support\Authorization;

use App\Models\User;
use Throwable;

/**
 * Who else could review this (ChatGPT ruling 15, 31 Oct 2026).
 *
 * An eligible reviewer is an ACTIVE person, with confirmed two-factor authentication (every review route
 * needs it), who holds the review permission and may act on the business in question (for a group-wide
 * item: a person who is not limited to some businesses). Read fresh every time; nothing is cached.
 *
 * Fail closed in the safe direction: if the question cannot be answered, the answer is "someone else may
 * exist", so a self-decision is refused rather than allowed.
 */
final class EligibleReviewers
{
    public function __construct(private readonly BusinessScope $scope) {}

    /** How many eligible reviewers other than `$actor` exist for this permission and business (null = group-wide). */
    public function othersThan(User $actor, string $permission, ?int $businessId): int
    {
        try {
            $candidates = User::query()->permission($permission)
                ->where('is_active', true)
                ->whereNotNull('two_factor_secret')->whereNotNull('two_factor_confirmed_at')
                ->whereKeyNot($actor->getKey())
                ->get();
        } catch (Throwable) {
            return PHP_INT_MAX;
        }

        return $candidates->filter(fn (User $u): bool => $businessId === null
            ? ! $this->scope->isRestricted($u)
            : $this->scope->allows($u, $businessId))->count();
    }

    public function actorIsSole(User $actor, string $permission, ?int $businessId): bool
    {
        return $this->othersThan($actor, $permission, $businessId) === 0;
    }
}
