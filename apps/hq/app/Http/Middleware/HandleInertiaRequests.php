<?php

namespace App\Http\Middleware;

use App\Support\Navigation\NavBadges;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'permissions' => $request->user()?->getAllPermissions()->pluck('name')->values() ?? [],
                'roles' => $request->user()?->getRoleNames()->values() ?? [],
                'twoFactorEnabled' => $request->user()?->hasTwoFactorEnabled() ?? false,
            ],
            // `outcome` carries what a tool call actually did. Without it a refused approval reached
            // the browser as silence: the controller flashed the refusal, this middleware dropped it,
            // and the screen looked identical to one where nothing had been clicked.
            'flash' => [
                'status' => $request->session()->get('status'),
                'outcome' => $request->session()->get('outcome'),
            ],
            // What is waiting, as counts next to the sidebar items. A closure, so a request that
            // does not render the shell does not pay for three counts it will not show.
            'nav' => fn () => ['badges' => app(NavBadges::class)->for($request->user())],
        ]);
    }
}
