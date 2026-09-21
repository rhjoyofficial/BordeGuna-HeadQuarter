<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Laravel\Horizon\Http\Middleware\Authenticate as HorizonAuthenticate;
use PragmaRX\Google2FA\Google2FA;

/*
 | docs/81 C.6 (1): 2FA is mandatory on every route that knows who you are, Horizon's included.
 |
 | Horizon mounts its own route group and authenticates by a gate, not by `auth`, so it never met
 | the two 2FA middlewares every HQ page carries: a person past the password step and not past the
 | challenge, or a person who never set 2FA up, opened the queue dashboard and its API if they held
 | `hq.horizon.view`. The enumeration at the end is the guard for the next route somebody adds.
 */

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

function twoFactorOwner(bool $enrolled = true): User
{
    $user = User::factory()->create();
    $user->assignRole('owner');
    if ($enrolled) {
        $user->forceFill(['two_factor_secret' => app(Google2FA::class)->generateSecretKey(), 'two_factor_confirmed_at' => now()])->save();
    }

    return $user->fresh();
}

/**
 * The routes that exist precisely for a person who has not finished 2FA: the challenge, the
 * mandatory setup and its actions, logout, and the starter kit's email verification and password
 * confirmation, which reveal nothing and act only on the person's own session.
 */
const PRE_TWO_FACTOR_URIS = [
    'two-factor/challenge',
    'security/two-factor', 'security/two-factor/enable', 'security/two-factor/confirm', 'security/two-factor/recovery-codes',
    'logout', 'verify-email', 'verify-email/{id}/{hash}', 'email/verification-notification', 'confirm-password',
];

it('sends a person who has not passed the challenge to the challenge, not into Horizon', function () {
    $user = twoFactorOwner();
    expect($user->can('hq.horizon.view'))->toBeTrue();

    // The password step alone: signed in, not verified.
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($user);
    Auth::forgetGuards();

    $this->get('/horizon')->assertRedirect(route('two-factor.challenge'));
    Auth::forgetGuards();
    $this->get('/horizon/api/stats')->assertRedirect(route('two-factor.challenge'));
    Auth::forgetGuards();
    $this->post('/horizon/api/jobs/retry/1')->assertRedirect(route('two-factor.challenge'));
});

it('sends a person who never set 2FA up to the setup, not into Horizon', function () {
    $user = twoFactorOwner(enrolled: false);
    expect($user->can('hq.horizon.view'))->toBeTrue();

    $this->actingAs($user)->get('/horizon')->assertRedirect(route('two-factor.setup'));
    $this->actingAs($user)->get('/horizon/api/stats')->assertRedirect(route('two-factor.setup'));
});

it('still opens Horizon for a verified person who holds the permission, and still refuses a guest with 403', function () {
    $user = twoFactorOwner();

    // The page only: the API reads Redis, which this laptop does not run.
    $this->actingAs($user)->withSession([EnsureTwoFactorVerified::SESSION_KEY => $user->id])->get('/horizon')->assertOk();

    Auth::logout();
    Auth::forgetGuards();
    $this->flushSession();
    $this->get('/horizon')->assertForbidden();
});

it('puts both 2FA checks on every route that authenticates a person, except the ones that exist to finish 2FA', function () {
    app(Kernel::class);
    $router = app('router');
    $checked = 0;
    $uris = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uris[] = $route->uri();
        $sorted = $router->gatherRouteMiddleware($route);
        $label = implode('|', $route->methods()).' '.$route->uri();

        $authenticates = false;
        foreach ($sorted as $middleware) {
            $class = is_string($middleware) ? explode(':', $middleware)[0] : null;
            if ($class !== null && class_exists($class)
                && (is_a($class, AuthenticatesRequests::class, true) || $class === HorizonAuthenticate::class)) {
                $authenticates = true;
                break;
            }
        }
        if (! $authenticates || in_array($route->uri(), PRE_TWO_FACTOR_URIS, true)) {
            continue;
        }

        $checked++;
        $enabled = array_search(EnsureTwoFactorEnabled::class, $sorted, true);
        $verified = array_search(EnsureTwoFactorVerified::class, $sorted, true);
        expect($enabled)->not->toBeFalse("{$label} authenticates a person without requiring 2FA to be set up")
            ->and($verified)->not->toBeFalse("{$label} authenticates a person without requiring the 2FA challenge");

        // The account check still comes first, so a disabled account ends at the login page.
        expect(array_search(EnsureAccountIsActive::class, $sorted, true))->toBeLessThan($enabled, "{$label} checks 2FA before the account");

        // Horizon's gate runs after both, so an unverified person is sent on rather than told 403.
        if (($gate = array_search(HorizonAuthenticate::class, $sorted, true)) !== false) {
            expect($verified)->toBeLessThan($gate, "{$label} asks Horizon's gate before the 2FA challenge");
        }
    }

    // The enumeration saw the application, Horizon's 22 routes included.
    expect($checked)->toBeGreaterThan(80);

    // The exemption list names only routes that exist, so it cannot quietly outlive a rename.
    foreach (PRE_TWO_FACTOR_URIS as $uri) {
        expect($uris)->toContain($uri);
    }
});
