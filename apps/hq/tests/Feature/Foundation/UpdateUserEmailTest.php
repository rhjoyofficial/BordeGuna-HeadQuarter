<?php

use App\Models\AuditLog;
use App\Models\User;

/*
 | hq:user:email changes the address a user signs in with, and nothing else.
 */

it('changes the email, keeps everything else, and records the change', function () {
    $user = $this->hqUser('owner', ['email' => 'old@example.com']);
    $passwordBefore = $user->password;
    $secretBefore = $user->two_factor_secret;

    $this->artisan('hq:user:email', ['from' => 'old@example.com', 'to' => 'New@Example.com'])
        ->expectsOutputToContain('now signs in as new@example.com')
        ->assertSuccessful();

    $user->refresh();
    expect($user->email)->toBe('new@example.com')
        ->and($user->password)->toBe($passwordBefore)
        ->and($user->two_factor_secret)->toBe($secretBefore)
        ->and($user->hasRole('owner'))->toBeTrue();

    $log = AuditLog::where('action', 'user.email_changed')->sole();
    expect($log->before['email'])->toBe('old@example.com')
        ->and($log->after['email'])->toBe('new@example.com');
});

it('refuses when nobody signs in as the old address', function () {
    $this->artisan('hq:user:email', ['from' => 'nobody@example.com', 'to' => 'new@example.com'])
        ->expectsOutputToContain('No user signs in as nobody@example.com')
        ->assertFailed();

    expect(AuditLog::where('action', 'user.email_changed')->count())->toBe(0);
});

it('refuses when the new address already belongs to someone', function () {
    $this->hqUser('owner', ['email' => 'a@example.com']);
    User::factory()->create(['email' => 'b@example.com']);

    $this->artisan('hq:user:email', ['from' => 'a@example.com', 'to' => 'b@example.com'])
        ->expectsOutputToContain('already signs in as b@example.com')
        ->assertFailed();

    expect(User::where('email', 'a@example.com')->exists())->toBeTrue();
});

it('refuses an address that is not an email', function () {
    $this->hqUser('owner', ['email' => 'a@example.com']);

    $this->artisan('hq:user:email', ['from' => 'a@example.com', 'to' => 'not-an-email'])
        ->expectsOutputToContain('not a valid email address')
        ->assertFailed();
});
