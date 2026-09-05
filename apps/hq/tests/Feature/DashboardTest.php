<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_authenticated_2fa_verified_users_can_visit_the_dashboard(): void
    {
        $user = $this->hqUser();
        $this->actingAsHq($user)->get('/dashboard')->assertOk();
    }

    public function test_authenticated_users_without_a_verified_2fa_session_are_challenged(): void
    {
        $user = $this->hqUser();
        $this->actingAs($user)->get('/dashboard')->assertRedirect('/two-factor/challenge');
    }
}
