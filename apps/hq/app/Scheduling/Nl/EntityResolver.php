<?php

namespace App\Scheduling\Nl;

use App\Models\Business;
use App\Models\Store;
use App\Models\User;
use App\Support\Authorization\BusinessScope;

/**
 * Turns the business and store a person named into rows HQ holds (docs/48 L.1): by slug, or by name
 * ignoring case. Never a guess: no match, or more than one, is a question.
 */
final class EntityResolver
{
    public function __construct(private readonly BusinessScope $scope) {}

    /** @return array{id: int|null, slug: string|null, name: string|null, question: string|null} */
    public function business(string $named, User $user): array
    {
        if ($named === '') {
            return ['id' => null, 'slug' => null, 'name' => null, 'question' => null];
        }

        $matches = Business::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('slug', $named)->orWhereRaw('lower(name) = ?', [mb_strtolower($named)]))
            ->get(['id', 'slug', 'name']);

        if ($matches->count() !== 1) {
            return ['id' => null, 'slug' => null, 'name' => null, 'question' => $matches->isEmpty()
                ? "HQ has no business called \"{$named}\". Which business did you mean?"
                : "More than one business matches \"{$named}\". Which one did you mean?"];
        }

        $business = $matches->first();

        if (! $this->scope->allows($user, $business->id)) {
            return ['id' => null, 'slug' => null, 'name' => null, 'question' => "You cannot schedule work for {$business->name}. Which of your businesses did you mean?"];
        }

        return ['id' => $business->id, 'slug' => $business->slug, 'name' => $business->name, 'question' => null];
    }

    /** @return array{id: int|null, slug: string|null, name: string|null, question: string|null} */
    public function store(string $named, ?int $businessId, User $user): array
    {
        if ($named === '') {
            return ['id' => null, 'slug' => null, 'name' => null, 'question' => null];
        }

        $matches = Store::query()
            ->where('is_active', true)
            ->when($businessId !== null, fn ($q) => $q->where('business_id', $businessId))
            ->where(fn ($q) => $q->where('slug', $named)->orWhereRaw('lower(name) = ?', [mb_strtolower($named)]))
            ->get(['id', 'slug', 'name', 'business_id']);

        if ($matches->count() !== 1) {
            return ['id' => null, 'slug' => null, 'name' => null, 'question' => $matches->isEmpty()
                ? "HQ has no store called \"{$named}\"".($businessId !== null ? ' in that business' : '').'. Which store did you mean?'
                : "More than one store matches \"{$named}\". Which one did you mean?"];
        }

        $store = $matches->first();

        if (! $this->scope->allows($user, (int) $store->business_id)) {
            return ['id' => null, 'slug' => null, 'name' => null, 'question' => "You cannot schedule work for {$store->name}. Which store did you mean?"];
        }

        return ['id' => $store->id, 'slug' => $store->slug, 'name' => $store->name, 'question' => null];
    }
}
