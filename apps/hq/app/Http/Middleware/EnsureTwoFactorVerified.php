<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After password login, a user with 2FA enabled must pass the TOTP challenge once per
 * session. The session key is bound to the user id so a session cannot carry another
 * user's verification. Login regenerates the session, so every login re-challenges.
 */
final class EnsureTwoFactorVerified
{
    public const SESSION_KEY = 'two_factor.verified_user_id';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->hasTwoFactorEnabled()
            && $request->session()->get(self::SESSION_KEY) !== $user->getKey()
            && ! $request->routeIs('two-factor.challenge*', 'logout')) {
            return redirect()->route('two-factor.challenge');
        }

        return $next($request);
    }
}
