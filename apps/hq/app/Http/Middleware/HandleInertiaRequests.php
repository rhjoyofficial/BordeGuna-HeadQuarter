<?php

namespace App\Http\Middleware;

use App\Ai\Pause\AiPauseScreen;
use App\OwnerConsole\Do\DoModeActivation;
use App\Support\Features\Features;
use App\Support\Money\UsdBdtRate;
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
            // docs/138 ruling 7, docs/141: Pause All AI, for the banner on every page and the controls on the
            // console and the posture screen. Read fresh on every page (never cached); whether this person
            // may pause or turn AI back on is the server's answer, never the permission list alone.
            'aiPause' => fn () => $request->user() === null ? null : app(AiPauseScreen::class)->for($request->user()),
            // Joy's rule, amounts always in BDT: the one rate every screen uses to show a USD model cost
            // in BDT (display only; stored values, caps and checks stay in USD). Null shows USD alone.
            'money' => fn () => ['usd_bdt_rate' => UsdBdtRate::current()],
            // What is waiting, as counts next to the sidebar items. A closure, so a request that
            // does not render the shell does not pay for three counts it will not show.
            // `features` carries the switches that decide whether a navigation item exists at all.
            'nav' => fn () => [
                'badges' => app(NavBadges::class)->for($request->user()),
                'features' => [
                    'ask_hq' => app(Features::class)->enabled('ask_hq'),
                    'scheduler_nl' => app(Features::class)->enabled('scheduler.nl'),
                    // docs/143: the three owner-console screens exist in the sidebar only while their flag is on.
                    // Ruling 31 Oct item 1: the activation gate, not the flag alone. Without an activation record this is false.
                    'console_do_mode' => app(DoModeActivation::class)->active(),
                    'console_batch_approval' => app(Features::class)->enabled('console.batch_approval'),
                    'console_event_watches' => app(Features::class)->enabled('console.event_watches'),
                ],
            ],
        ]);
    }
}
