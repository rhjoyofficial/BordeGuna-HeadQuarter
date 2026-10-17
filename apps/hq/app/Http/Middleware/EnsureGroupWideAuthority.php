<?php

namespace App\Http\Middleware;

use App\Support\Authorization\GroupWideAuthority;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `group.wide:<permission>` on every route that throws a switch acting on every business
 * (docs/138 ruling 13). A restricted person, or one without the permission, is answered 403 with the
 * reason before the controller runs. The controller and the service it calls check again, so a route
 * that loses this middleware is still refused, and `GlobalSwitchScopeTest` pins the route list.
 */
final class EnsureGroupWideAuthority
{
    public function __construct(private readonly GroupWideAuthority $authority) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $refusal = $this->authority->refusal($request->user(), $permission);
        abort_if($refusal !== null, 403, (string) $refusal);

        return $next($request);
    }
}
