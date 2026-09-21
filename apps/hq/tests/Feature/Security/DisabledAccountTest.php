<?php

use App\Auth\ActiveUserProvider;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Laravel\Horizon\Http\Middleware\Authenticate;
use PragmaRX\Google2FA\Google2FA;

/*
 | docs/81 P0-1: `users.is_active = false` is honoured by authentication.
 |
 | Two layers are proved here. The user provider refuses to turn an id, a remember cookie or an
 | email into a disabled user, so nothing can sign in, stay signed in, come back from a cookie or
 | reset a password. The middleware ends the live session, and a route enumeration proves it sits
 | on every authenticated route, ahead of `auth`, because a guard nothing can reach is not a guard.
 |
 | A real process builds the guard once per request. One test process serves many requests, so
 | `nextRequest()` drops the resolved guards between them, which is what a new request is.
 */

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

function enrolledOwner(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->assignRole('owner');
    $user->forceFill(['two_factor_secret' => app(Google2FA::class)->generateSecretKey(), 'two_factor_confirmed_at' => now()])->save();

    return $user->fresh();
}

function disable(User $user): void
{
    // The only way an account is disabled today: by hand, around the application.
    User::query()->whereKey($user->getKey())->toBase()->update(['is_active' => false]);
}

function nextRequest(): void
{
    Auth::forgetGuards();
}

it('refuses the password step for a disabled user with the same sentence a wrong password gets', function () {
    $user = enrolledOwner();
    disable($user);

    $wrong = $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'not-the-password']);
    $wrongSentence = session('errors')->first('email');
    nextRequest();
    $this->flushSession();

    $response = $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'password']);

    $response->assertRedirect('/login')->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toBe($wrongSentence)->toBe(__('auth.failed'))
        ->and(session('errors')->first('email'))->not->toContain('disabled');
    $this->assertGuest();
    expect($user->fresh()->last_login_at)->toBeNull()
        ->and(AuditLog::where('action', 'auth.login')->count())->toBe(0);

    $row = AuditLog::where('action', 'auth.login_failed')->latest('id')->first();
    expect($row->actor_type)->toBe('system')
        ->and($row->after)->toBe(['email' => $user->email])
        ->and($row->reason)->toBe('the account is disabled');
});

it('keeps the audit of an ordinary failed login exactly as it was', function () {
    $user = enrolledOwner();

    $this->post('/login', ['email' => $user->email, 'password' => 'not-the-password']);

    $row = AuditLog::where('action', 'auth.login_failed')->sole();
    expect($row->reason)->toBeNull()->and($row->after)->toBe(['email' => $user->email])->and($row->actor_type)->toBe('system');
});

it('lets an enabled user sign in, pass the challenge and work, exactly as before', function () {
    $user = enrolledOwner();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($user);
    nextRequest();
    $this->get('/dashboard')->assertRedirect('/two-factor/challenge');
    nextRequest();
    $this->post('/two-factor/challenge', ['code' => app(Google2FA::class)->getCurrentOtp($user->two_factor_secret)])->assertRedirect('/dashboard');
    nextRequest();
    $this->get('/dashboard')->assertOk();

    expect(AuditLog::where('action', 'auth.login')->where('actor_id', $user->id)->count())->toBe(1)
        ->and(AuditLog::where('action', 'auth.two_factor.verified')->count())->toBe(1)
        ->and(AuditLog::where('action', 'auth.session_rejected')->count())->toBe(0);
});

it('does not let a user disabled after the password step complete the 2FA challenge', function () {
    $user = enrolledOwner();
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');

    disable($user);
    nextRequest();

    $this->post('/two-factor/challenge', ['code' => app(Google2FA::class)->getCurrentOtp($user->two_factor_secret)])
        ->assertRedirect('/login');

    $this->assertGuest();
    expect(session(EnsureTwoFactorVerified::SESSION_KEY))->toBeNull()
        ->and(AuditLog::where('action', 'auth.two_factor.verified')->count())->toBe(0)
        ->and(AuditLog::where('action', 'auth.session_rejected')->where('target_id', (string) $user->id)->count())->toBe(1);
});

it('rejects a live, fully verified session on its next request once the account is disabled', function () {
    $user = enrolledOwner();
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    nextRequest();
    $this->withSession([EnsureTwoFactorVerified::SESSION_KEY => $user->id])->get('/dashboard')->assertOk();
    $sessionBefore = session()->getId();
    $rememberBefore = $user->fresh()->remember_token;

    disable($user);
    nextRequest();

    $this->get('/dashboard')->assertRedirect('/login');

    $this->assertGuest();
    expect(session()->getId())->not->toBe($sessionBefore)
        ->and(session(EnsureTwoFactorVerified::SESSION_KEY))->toBeNull()
        ->and($user->fresh()->remember_token)->not->toBe($rememberBefore);

    $row = AuditLog::where('action', 'auth.session_rejected')->sole();
    expect($row->actor_type)->toBe('system')
        ->and($row->target_id)->toBe((string) $user->id)
        ->and($row->reason)->toBe('the account is disabled')
        ->and(json_encode($row->after))->not->toContain('remember');

    // And it stays out: the next request is an ordinary guest.
    nextRequest();
    $this->get('/dashboard')->assertRedirect('/login');
    expect(AuditLog::where('action', 'auth.session_rejected')->count())->toBe(1);
});

it('answers a JSON request from a disabled session with 401 and an Inertia visit with the login redirect', function () {
    $user = enrolledOwner();
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    disable($user);

    nextRequest();
    $this->withSession([EnsureTwoFactorVerified::SESSION_KEY => $user->id])
        ->getJson('/dashboard')->assertStatus(401)->assertExactJson(['message' => 'Unauthenticated.']);

    // A second person, to show the Inertia answer from a session that is still alive.
    $other = enrolledOwner();
    nextRequest();
    $this->flushSession();
    $this->post('/login', ['email' => $other->email, 'password' => 'password']);
    disable($other);
    nextRequest();
    $this->withSession([EnsureTwoFactorVerified::SESSION_KEY => $other->id])
        ->get('/dashboard', ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->assertRedirect('/login');
    $this->assertGuest();
});

it('rejects a disabled account even when the guard was handed the user directly', function () {
    // `actingAs` sets the user on the guard and never asks the provider; the middleware alone stands here.
    $user = enrolledOwner();
    $this->actingAs($user)->withSession([EnsureTwoFactorVerified::SESSION_KEY => $user->id]);
    $this->get('/dashboard')->assertOk();

    disable($user);

    $this->get('/dashboard')->assertRedirect('/login');
    $this->assertGuest();
});

it('closes the Horizon dashboard to a disabled user who holds its permission', function () {
    $user = enrolledOwner();
    expect($user->can('hq.horizon.view'))->toBeTrue();
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    nextRequest();
    $this->withSession([EnsureTwoFactorVerified::SESSION_KEY => $user->id])->get('/horizon')->assertOk();

    disable($user);
    nextRequest();

    $this->get('/horizon')->assertRedirect('/login');
    $this->assertGuest();
    nextRequest();
    $this->get('/horizon/api/stats')->assertForbidden();
});

it('does not revive a disabled user from a remember-me cookie', function () {
    $user = enrolledOwner();
    $response = $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => 'on']);
    $cookieName = Auth::guard('web')->getRecallerName();
    $cookie = $response->getCookie($cookieName, false);
    expect($cookie)->not->toBeNull();

    // An enabled user does come back from it: the cookie itself is good.
    nextRequest();
    $this->flushSession();
    $this->withUnencryptedCookie($cookieName, $cookie->getValue())->get('/two-factor/challenge')->assertOk();
    $this->assertAuthenticatedAs($user);

    disable($user);
    nextRequest();
    $this->flushSession();

    $this->withUnencryptedCookie($cookieName, $cookie->getValue())->get('/two-factor/challenge')->assertRedirect('/login');
    $this->assertGuest();
});

it('sends a disabled user no reset link, with the same answer an unknown address gets', function () {
    Notification::fake();
    $user = enrolledOwner();
    disable($user);

    $known = $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email]);
    $unknown = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'nobody@example.test']);

    Notification::assertNothingSent();
    $known->assertRedirect('/forgot-password')->assertSessionHas('status');
    $unknown->assertRedirect('/forgot-password')->assertSessionHas('status');
    expect(DB::table('password_reset_tokens')->count())->toBe(0)
        ->and(AuditLog::where('action', 'auth.password_reset_refused')->where('reason', 'the account is disabled')->count())->toBe(1);
});

it('does not accept a reset token issued before the account was disabled', function () {
    Notification::fake();
    $user = enrolledOwner();
    $token = Password::broker()->createToken($user);
    $hashBefore = $user->fresh()->password;
    disable($user);
    nextRequest();

    $response = $this->from('/reset-password/'.$token)->post('/reset-password', [
        'token' => $token, 'email' => $user->email, 'password' => 'a-new-long-password-1', 'password_confirmation' => 'a-new-long-password-1',
    ]);

    $response->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toBe(__(Password::INVALID_USER))
        ->and($user->fresh()->password)->toBe($hashBefore);
    $this->assertGuest();

    nextRequest();
    $this->post('/login', ['email' => $user->email, 'password' => 'a-new-long-password-1']);
    $this->assertGuest();
});

it('still resets the password of an enabled user', function () {
    Notification::fake();
    $user = enrolledOwner();
    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
    expect(AuditLog::where('action', 'auth.password_reset_refused')->count())->toBe(0);
});

it('puts the account check on every authenticated route, ahead of auth, and on every web route', function () {
    // The HTTP kernel hands the router its groups and the priority list; a request would have built it.
    app(Kernel::class);
    $router = app('router');
    $authenticated = 0;

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $declared = $route->gatherMiddleware();
        $sorted = $router->gatherRouteMiddleware($route);
        $label = implode('|', $route->methods()).' '.$route->uri();
        $check = array_search(EnsureAccountIsActive::class, $sorted, true);

        if (in_array('web', $declared, true)) {
            expect($check)->not->toBeFalse("{$label} is a web route without the account check");
        }

        $auth = null;
        foreach ($sorted as $index => $middleware) {
            $class = is_string($middleware) ? explode(':', $middleware)[0] : null;
            if ($class !== null && class_exists($class) && is_a($class, AuthenticatesRequests::class, true)) {
                $auth = $index;
                break;
            }
        }
        if ($auth === null) {
            continue;
        }

        $authenticated++;
        expect(in_array('web', $declared, true))->toBeTrue("{$label} authenticates outside the web group; decide how a disabled account is refused there")
            ->and($check)->not->toBeFalse("{$label} authenticates without the account check");
        expect($check)->toBeLessThan($auth, "{$label} runs auth before the account check");
    }

    // The enumeration saw the application, not an empty router.
    expect($authenticated)->toBeGreaterThan(60);

    // Horizon mounts on `web` and authenticates by a gate, not by `auth`: it must be in the count above.
    $horizon = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'horizon'));
    expect($horizon->count())->toBeGreaterThan(10);
    foreach ($horizon as $route) {
        $sorted = $router->gatherRouteMiddleware($route);
        expect($sorted)->toContain(EnsureAccountIsActive::class)->toContain(Authenticate::class)
            ->and(array_search(EnsureAccountIsActive::class, $sorted, true))->toBeLessThan(array_search(Authenticate::class, $sorted, true));
    }
});

it('has one guard, one provider and no token path for people, so there is no second door', function () {
    expect(array_keys(config('auth.guards')))->toBe(['web'])
        ->and(config('auth.guards.web.driver'))->toBe('session')
        ->and(config('auth.providers.users.driver'))->toBe('active-eloquent')
        ->and(Auth::guard('web')->getProvider())->toBeInstanceOf(ActiveUserProvider::class)
        ->and(Password::broker()->getUser(['email' => 'nobody@example.test']))->toBeNull();

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (in_array('api', $route->gatherMiddleware(), true)) {
            expect(collect($route->gatherMiddleware())->filter(fn ($m) => is_string($m) && str_starts_with($m, 'auth'))->all())
                ->toBe([], "{$route->uri()} is an api route that authenticates a person");
        }
    }
});

it('adds no way to disable anybody: the column cannot be mass assigned and a created user is enabled', function () {
    $this->artisan('hq:user:create', ['email' => 'new@example.test', '--generate-password' => true])->assertSuccessful();
    expect(User::where('email', 'new@example.test')->sole()->is_active)->toBeTrue()
        ->and((new User)->isFillable('is_active'))->toBeFalse();

    // No application source assigns the column: it is read, and only ever in a query or a comparison.
    $writers = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        if (preg_match("/['\"]is_active['\"]\s*=>\s*false/", $source) && preg_match('/User::|\$user/', $source)) {
            $writers[] = $file->getFilename();
        }
    }
    expect($writers)->toBe([]);
});
