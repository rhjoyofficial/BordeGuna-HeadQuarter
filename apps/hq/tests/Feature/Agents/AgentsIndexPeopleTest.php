<?php

use App\Models\Agent;
use App\Models\User;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 | docs/81 C.6 item 5: the agents index names the enabling user the way the run page does, by name
 | and user id, and sends no email. It used to send the enabling user's email as `enabling_user`.
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AgentSeeder::class);
    $this->owner = $this->hqUser('owner');
});

it('names the enabling user by name and user id, and sends no email in the agents it lists', function () {
    $enabler = User::factory()->create(['name' => 'Enabling Person', 'email' => 'enabler.unique@example.test']);
    Agent::where('key', 'executive')->update(['enabling_user_id' => $enabler->id]);

    $this->actingAsHq($this->owner)->get('/agents')
        ->assertOk()
        ->assertInertia(function ($page) use ($enabler) {
            $agents = collect($page->toArray()['props']['agents'])->keyBy('key');

            expect($agents['executive']['enabling_user'])->toBe(['user_id' => $enabler->id, 'name' => 'Enabling Person'])
                ->and($agents['analyst']['enabling_user'] ?? null)->toBeNull();

            $flat = mb_strtolower((string) json_encode($page->toArray()['props']['agents']));
            foreach (['enabler.unique@example.test', '@'] as $never) {
                expect($flat)->not->toContain($never);
            }
        });
});
