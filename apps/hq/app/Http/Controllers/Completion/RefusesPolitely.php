<?php

namespace App\Http\Controllers\Completion;

use App\Research\ResearchRefused;
use App\Support\Authorization\BusinessScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Shared by the docs/145 screens: a service refusal becomes a form error the person reads, never a 500,
 * and a row outside the person's business scope is a 404 (the screen never confirms it exists).
 */
trait RefusesPolitely
{
    /** @param  callable(): mixed  $act */
    protected function attempt(callable $act, string $status, string $field = 'form'): RedirectResponse
    {
        try {
            $act();
        } catch (ResearchRefused $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }

        return back()->with('status', $status);
    }

    protected function inScope(?Model $row, ?int $businessId): void
    {
        if ($row === null || ! app(BusinessScope::class)->allows(request()->user(), $businessId)) {
            abort(404);
        }
    }
}
