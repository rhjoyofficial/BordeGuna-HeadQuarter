<?php

namespace App\Http\Middleware;

use App\Auth\ActiveUserProvider;
use App\Models\User;
use App\Support\Audit\Auditor;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * A session that belongs to a disabled account ends on its next request (docs/81 P0-1).
 *
 * `ActiveUserProvider` already refuses to load a disabled user, so such a session is nobody's. This
 * is the part that cleans up and says so: it audits the rejection, kills the remember-me token,
 * invalidates the session and answers as `auth` answers a guest (a redirect to the login page for
 * a page or an Inertia visit, 401 for a request that expects JSON). The answer never says why.
 *
 * ⚠ Where it runs matters. It is in the `web` group, so every web route has it without opting in
 * (Horizon mounts on `web` and has no `auth` middleware at all). It is also in the priority list
 * ahead of `AuthenticatesRequests`: Laravel pulls `auth` ahead of `SubstituteBindings`, which is
 * in the `web` group, and so ahead of anything merely appended to that group. Without the priority
 * entry `auth` would turn the request away first and this would never see it.
 *
 * `is_active` is read from the database, not from the model the guard holds: one primary-key
 * query, and correct for a guard that was handed its user (a long-lived worker, a test).
 *
 * ⛔ No exemption of any kind.
 */
final class EnsureAccountIsActive
{
    public function __construct(private readonly Auditor $auditor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');
        $id = $guard->id();

        if ($id === null || User::query()->whereKey($id)->toBase()->value('is_active') !== false) {
            return $next($request);
        }

        $this->auditor->recordSystem('auth.session_rejected', (new User)->forceFill(['id' => $id]),
            after: ['user_id' => (int) $id, 'path' => '/'.ltrim($request->path(), '/')],
            reason: ActiveUserProvider::DISABLED_REASON);

        // An old cookie must not work again, even if the account is enabled later.
        User::query()->whereKey($id)->toBase()->update(['remember_token' => Str::random(60)]);

        $guard->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        throw new AuthenticationException('Unauthenticated.', ['web']);
    }
}
