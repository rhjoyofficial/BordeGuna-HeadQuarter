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

    /** The landing screen leads with what needs a person, then whether HQ is running, then the businesses. */
    public function test_the_dashboard_carries_attention_health_and_businesses(): void
    {
        $user = $this->hqUser();
        $this->actingAsHq($user)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->has('attention', 6)
            ->where('attention.0.key', 'approvals_pending')
            ->has('health.scheduler')
            ->has('health.ai_runtime')
            ->has('health.agents')
            ->has('health.events')
            ->has('businesses')
            ->has('counts')
            ->has('recentAudit'));
    }

    /** The sidebar counts are shared on every Inertia page, so the shell can say what is waiting. */
    public function test_navigation_badges_are_shared_with_every_page(): void
    {
        $user = $this->hqUser();
        $this->actingAsHq($user)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->has('nav.badges')
            ->where('nav.badges.approvals', 0)
            ->where('nav.badges.agents', 0)
            ->where('nav.badges.scheduler', 0));
    }

    public function test_authenticated_users_without_a_verified_2fa_session_are_challenged(): void
    {
        $user = $this->hqUser();
        $this->actingAs($user)->get('/dashboard')->assertRedirect('/two-factor/challenge');
    }
}
