<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 2FA is mandatory: an authenticated user without confirmed TOTP can only reach the 2FA
 * setup pages and logout. Everything else redirects to setup.
 */
final class EnsureTwoFactorEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->hasTwoFactorEnabled() && ! $request->routeIs('two-factor.setup*', 'two-factor.enable', 'two-factor.confirm', 'logout')) {
            return redirect()->route('two-factor.setup')->with('status', 'two-factor-required');
        }

        return $next($request);
    }
}
