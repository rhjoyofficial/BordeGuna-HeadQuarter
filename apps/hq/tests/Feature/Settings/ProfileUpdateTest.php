<?php

namespace Tests\Feature\Settings;

use App\Http\Controllers\Settings\ProfileController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = $this->hqUser();

        $response = $this->actingAsHq($user)
            ->get('/settings/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = $this->hqUser();

        $response = $this->actingAsHq($user)
            ->patch('/settings/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = $this->hqUser();

        $response = $this->actingAsHq($user)
            ->patch('/settings/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    /**
     * HQ has one owner. The starter kit's self-service "Delete account" would let that owner lock the
     * whole group out with a password and a click, so the capability was removed at every layer on
     * 12 Sep 2026 (docs/64). This guard holds it absent: no route, no route name, no action, no
     * screen block, and a correct password changes nothing.
     */
    public function test_there_is_no_way_for_a_user_to_delete_their_own_account()
    {
        $user = $this->hqUser();

        $this->assertFalse(Route::has('profile.destroy'), 'the profile.destroy route name has come back');
        $this->assertFalse(method_exists(ProfileController::class, 'destroy'), 'ProfileController::destroy has come back');

        $this->actingAsHq($user)
            ->delete('/settings/profile', ['password' => 'password'])
            ->assertMethodNotAllowed();

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh());

        $this->assertFileDoesNotExist(resource_path('js/components/delete-user.tsx'));
        $screen = file_get_contents(resource_path('js/pages/settings/profile.tsx'));
        $this->assertStringNotContainsString('DeleteUser', $screen);
        $this->assertStringNotContainsString('Delete account', $screen);
    }
}
