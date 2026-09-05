<?php

use Illuminate\Support\Facades\Route;

/*
 | Laravel keys routes by method+URI, so a duplicate registration silently REPLACES the earlier
 | one, gate and all (this once shadowed a LaLaDia admin endpoint). Guard: every named route is
 | unique, every method+URI pair is unique, and the Phase 0 routes exist with their middleware.
 */
it('has no duplicate route names or method+uri pairs', function () {
    $routes = collect(Route::getRoutes()->getRoutes());
    $names = $routes->map->getName()->filter();
    expect($names->duplicates()->values()->all())->toBe([]);

    $pairs = $routes->flatMap(fn ($r) => collect($r->methods())->reject(fn ($m) => $m === 'HEAD')->map(fn ($m) => $m.' '.$r->uri()));
    expect($pairs->duplicates()->values()->all())->toBe([]);
});

it('protects every Phase 0 screen with auth and both 2FA gates', function () {
    foreach (['dashboard', 'scheduler.index', 'audit.index', 'flags.index', 'credentials.index', 'businesses.index', 'sessions.index', 'profile.edit'] as $name) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull("route {$name} missing");
        $mw = $route->gatherMiddleware();
        expect($mw)->toContain('auth')->toContain('2fa.enabled')->toContain('2fa.verified');
    }
    expect(Route::getRoutes()->getByName('api.health'))->not->toBeNull();
});
