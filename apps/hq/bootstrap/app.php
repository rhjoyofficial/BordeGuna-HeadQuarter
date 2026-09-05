<?php

use App\Http\Middleware\AssignCorrelationIds;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Http\Middleware\HandleInertiaRequests;
use App\Scheduling\Commands\SchedulerTick;
use App\Support\Events\ProcessDomainEvents;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        SchedulerTick::class,
        ProcessDomainEvents::class,
    ])
    ->withMiddleware(function (Middleware $middleware) {
        // Behind Traefik: trust the proxy so scheme/host/IP are the client's, not the proxy's.
        $middleware->trustProxies(at: '*');

        $middleware->prepend(AssignCorrelationIds::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            '2fa.enabled' => EnsureTwoFactorEnabled::class,
            '2fa.verified' => EnsureTwoFactorVerified::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
