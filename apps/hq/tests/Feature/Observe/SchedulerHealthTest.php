<?php

use App\Scheduling\Commands\SchedulerTick;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

/*
 | docs/83 C.2: the scheduler incident of 18 to 21 September as a standing invariant, the application's half.
 |
 | Production's scheduler ran out of processes (9,482 zombies against a pids.max of 9,483) and stopped
 | ticking for three days. What made that visible at all is this endpoint: a scheduler that cannot fork
 | cannot write its heartbeat, and /api/health then answers 503 NAMING the scheduler and why. The host
 | monitor, deploy, rollback and verify-only read that body (deploy/tests/deploy-safety-test.sh, sections
 | 12, 16b, 23 and 25, against a stub that answers as this endpoint does). These tests hold the endpoint to
 | the shape the stub assumes, so the two halves cannot drift apart.
 |
 | Redis and Horizon are pinned healthy here, because this laptop has no Redis and no Horizon master: with
 | them failing the endpoint would answer 503 for the wrong reason and prove nothing about the scheduler.
 */

beforeEach(function () {
    $this->mock(MasterSupervisorRepository::class, fn ($m) => $m->shouldReceive('all')->andReturn([(object) ['status' => 'running']]));
    Redis::shouldReceive('connection')->andReturn(new class
    {
        public function ping(): bool
        {
            return true;
        }
    });
});

it('answers 200 with the tick age while the scheduler ticks', function () {
    Cache::put(SchedulerTick::HEARTBEAT_KEY, now()->subSeconds(30)->toIso8601String(), 600);

    $response = $this->getJson('/api/health');

    $response->assertOk();
    expect($response->json('status'))->toBe('ok')
        ->and($response->json('checks.scheduler.status'))->toBe('ok')
        ->and($response->json('checks.scheduler.last_tick_age_seconds'))->toBeGreaterThanOrEqual(30)->toBeLessThan(60);
});

it('answers 503 naming the scheduler and why when no tick was ever recorded', function () {
    Cache::forget(SchedulerTick::HEARTBEAT_KEY);

    $response = $this->getJson('/api/health');

    $response->assertStatus(503);
    expect($response->json('status'))->toBe('fail')
        ->and($response->json('checks.scheduler'))->toBe(['status' => 'fail', 'detail' => 'no tick recorded'])
        // Only the scheduler failed: every reader can name the one component from the body.
        ->and(collect($response->json('checks'))->except('scheduler')->pluck('status')->unique()->all())->toBe(['ok']);
});

it('answers 503 with the age when the last tick is older than the stale budget', function () {
    config(['hq.scheduler.heartbeat_stale_after_seconds' => 180]);
    Cache::put(SchedulerTick::HEARTBEAT_KEY, now()->subMinutes(10)->toIso8601String(), 3600);

    $response = $this->getJson('/api/health');

    $response->assertStatus(503);
    expect($response->json('checks.scheduler.status'))->toBe('fail')
        ->and($response->json('checks.scheduler.last_tick_age_seconds'))->toBeGreaterThanOrEqual(600);
});

it('treats a tick exactly at the stale budget as alive, and one second past it as failed', function () {
    config(['hq.scheduler.heartbeat_stale_after_seconds' => 180]);
    // A whole second: the heartbeat is stored as ISO-8601 without microseconds, so a clock frozen
    // mid-second would make "exactly 180 seconds" read as 180.4 and the boundary would say nothing.
    $this->freezeSecond();

    Cache::put(SchedulerTick::HEARTBEAT_KEY, now()->subSeconds(180)->toIso8601String(), 600);
    $this->getJson('/api/health')->assertOk();

    Cache::put(SchedulerTick::HEARTBEAT_KEY, now()->subSeconds(181)->toIso8601String(), 600);
    $this->getJson('/api/health')->assertStatus(503);
});
