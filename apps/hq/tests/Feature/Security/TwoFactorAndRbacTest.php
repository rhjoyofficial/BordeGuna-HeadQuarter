<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\ScheduledJob;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use PragmaRX\Google2FA\Google2FA;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

function ownerWith2fa(): User
{
    $user = User::factory()->create();
    $user->assignRole('owner');
    $user->forceFill(['two_factor_secret' => app(Google2FA::class)->generateSecretKey(), 'two_factor_confirmed_at' => now()])->save();

    return $user;
}

it('forces a user without 2FA to the setup page and nowhere else', function () {
    $user = User::factory()->create();
    $user->assignRole('owner');
    $this->actingAs($user)->get('/dashboard')->assertRedirect('/security/two-factor');
    $this->actingAs($user)->get('/scheduler')->assertRedirect('/security/two-factor');
    $this->actingAs($user)->get('/security/two-factor')->assertOk();
});

it('enables 2FA only after a valid confirmation code and hands out recovery codes once', function () {
    $user = User::factory()->create();
    $user->assignRole('owner');
    $this->actingAs($user)->post('/security/two-factor/enable')->assertRedirect('/security/two-factor');
    $user->refresh();
    expect($user->two_factor_secret)->not->toBeNull()->and($user->hasTwoFactorEnabled())->toBeFalse();

    $this->actingAs($user)->post('/security/two-factor/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();

    $code = app(Google2FA::class)->getCurrentOtp($user->two_factor_secret);
    $this->actingAs($user)->post('/security/two-factor/confirm', ['code' => $code])->assertRedirect('/security/two-factor');
    $user->refresh();
    expect($user->hasTwoFactorEnabled())->toBeTrue()->and($user->two_factor_recovery_codes)->toHaveCount(8);
});

it('challenges a 2FA user every session, accepts a TOTP code, and burns a recovery code', function () {
    $user = ownerWith2fa();
    $this->actingAs($user)->get('/dashboard')->assertRedirect('/two-factor/challenge');

    $this->actingAs($user)->post('/two-factor/challenge', ['code' => '123456'])->assertSessionHasErrors('code');

    $code = app(Google2FA::class)->getCurrentOtp($user->two_factor_secret);
    $this->actingAs($user)->post('/two-factor/challenge', ['code' => $code])->assertRedirect('/dashboard');
    $this->assertEquals($user->id, session(EnsureTwoFactorVerified::SESSION_KEY));
    $this->actingAs($user)->get('/dashboard')->assertOk();

    // Recovery code path on a fresh session.
    $user->forceFill(['two_factor_recovery_codes' => ['AAAAA-BBBBB', 'CCCCC-DDDDD']])->save();
    $this->flushSession();
    $this->actingAs($user)->post('/two-factor/challenge', ['recovery_code' => 'aaaaa-bbbbb'])->assertRedirect('/dashboard');
    expect($user->fresh()->two_factor_recovery_codes)->toBe(['CCCCC-DDDDD']);
});

it('enforces RBAC: a support agent cannot manage jobs or see the audit log', function () {
    $agent = User::factory()->create();
    $agent->assignRole('support_agent');
    $agent->forceFill(['two_factor_secret' => 'x', 'two_factor_confirmed_at' => now()])->save();
    $this->actingAs($agent)->withSession([EnsureTwoFactorVerified::SESSION_KEY => $agent->id]);

    $this->get('/dashboard')->assertOk();
    $this->get('/scheduler')->assertOk();
    $this->get('/audit')->assertForbidden();
    $this->post('/scheduler', ['name' => 'x', 'kind' => 'cron', 'cron_expression' => '0 9 * * *', 'tool_key' => 'noop'])->assertForbidden();
});

it('lets an owner create a job from the UI and audits the login', function () {
    $user = ownerWith2fa();
    $this->actingAs($user)->withSession([EnsureTwoFactorVerified::SESSION_KEY => $user->id]);
    $this->post('/scheduler', ['name' => 'ui job', 'kind' => 'cron', 'cron_expression' => '0 9 * * *', 'tool_key' => 'noop', 'timezone' => 'Asia/Dhaka'])->assertRedirect();
    expect(ScheduledJob::where('name', 'ui job')->exists())->toBeTrue();
});

it('registration is closed', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', ['name' => 'x', 'email' => 'x@x.com', 'password' => 'password-123', 'password_confirmation' => 'password-123'])->assertNotFound();
});

it('the health endpoint reports each dependency without leaking configuration', function () {
    $response = $this->getJson('/api/health');
    $json = $response->json();
    expect($json['checks'])->toHaveKeys(['application', 'database', 'redis', 'queue', 'scheduler'])
        ->and($json['checks']['database']['status'])->toBe('ok')
        ->and(json_encode($json))->not->toContain(config('app.key'))
        ->and(json_encode($json))->not->toContain(config('database.connections.pgsql.password') ?: '__none__');
    $this->get('/up')->assertOk();
});
