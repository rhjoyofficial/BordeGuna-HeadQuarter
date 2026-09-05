<?php

namespace Tests;

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PragmaRX\Google2FA\Google2FA;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** A user who has completed the mandatory 2FA enrolment, with a role. */
    protected function hqUser(string $role = 'owner', array $attributes = []): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create($attributes);
        $user->assignRole($role);
        $user->forceFill([
            'two_factor_secret' => app(Google2FA::class)->generateSecretKey(),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user->fresh();
    }

    /** Act as a 2FA-enrolled user whose session has already passed the challenge. */
    protected function actingAsHq(User $user): static
    {
        $this->actingAs($user);
        $this->withSession([EnsureTwoFactorVerified::SESSION_KEY => $user->getKey()]);

        return $this;
    }
}
