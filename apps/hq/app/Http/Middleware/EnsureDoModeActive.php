<?php

namespace App\Http\Middleware;

use App\OwnerConsole\Do\DoModeActivation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ruling 31 Oct, item 1: while Do mode has no activation (`DoModeActivation`), every Do route is not there: 404, for
 * reading and writing alike, whatever the feature flag row says. The UI never shows it and no request can reach it.
 */
final class EnsureDoModeActive
{
    public function __construct(private readonly DoModeActivation $activation) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->activation->active()) {
            abort(404);
        }

        return $next($request);
    }
}
