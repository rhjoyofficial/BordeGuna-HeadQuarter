<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sidebar shows "Queues (Horizon)" to whoever holds `hq.horizon.view`; the same permission
 * must open the page. Production answered every click with a bare 403 until the 12 Sep 2026
 * render check, because the starter-kit gate was an empty list of e-mail addresses.
 */
class HorizonAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_with_the_permission_can_open_the_queue_dashboard(): void
    {
        $user = $this->hqUser('owner');

        $this->actingAsHq($user)->get('/horizon')->assertOk();
    }

    public function test_a_user_without_the_permission_is_refused(): void
    {
        $user = $this->hqUser('support_agent');

        $this->assertFalse($user->can('hq.horizon.view'));
        $this->actingAsHq($user)->get('/horizon')->assertForbidden();
    }

    public function test_a_guest_is_refused(): void
    {
        $this->get('/horizon')->assertForbidden();
    }
}
