<?php

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Scheduling\Commands\SchedulerTick;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * GET /api/health - dependency status for monitoring. Distinguishes application, database,
 * redis, queue/worker (Horizon master supervisors) and scheduler (tick heartbeat).
 * Exposes no configuration values: only ok/degraded/fail per component and coarse timings.
 */
final class HealthController extends Controller
{
    public function __invoke(MasterSupervisorRepository $supervisors): JsonResponse
    {
        $checks = [
            'application' => ['status' => 'ok', 'version' => config('app.version', 'phase-0'), 'environment' => app()->environment()],
            'database' => $this->check(fn () => DB::select('select 1 as ok')[0]->ok === 1),
            'redis' => $this->check(fn () => Redis::connection()->ping() !== false),
            'queue' => $this->queue($supervisors),
            'scheduler' => $this->scheduler(),
        ];

        $statuses = array_column($checks, 'status');
        $overall = in_array('fail', $statuses, true) ? 'fail' : (in_array('degraded', $statuses, true) ? 'degraded' : 'ok');

        return response()->json([
            'status' => $overall,
            'checked_at' => now()->toIso8601String(),
            'checks' => $checks,
        ], $overall === 'fail' ? 503 : 200);
    }

    private function check(callable $probe): array
    {
        $start = microtime(true);
        try {
            $ok = (bool) $probe();

            return ['status' => $ok ? 'ok' : 'fail', 'latency_ms' => (int) ((microtime(true) - $start) * 1000)];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'latency_ms' => (int) ((microtime(true) - $start) * 1000), 'error' => class_basename($e)];
        }
    }

    private function queue(MasterSupervisorRepository $supervisors): array
    {
        try {
            $masters = collect($supervisors->all());
            if ($masters->isEmpty()) {
                return ['status' => 'fail', 'detail' => 'no horizon master supervisor running'];
            }
            $paused = $masters->every(fn ($m) => ($m->status ?? '') === 'paused');

            return ['status' => $paused ? 'degraded' : 'ok', 'supervisors' => $masters->count()];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'error' => class_basename($e)];
        }
    }

    private function scheduler(): array
    {
        try {
            $last = Cache::get(SchedulerTick::HEARTBEAT_KEY);
            if (! $last) {
                return ['status' => 'fail', 'detail' => 'no tick recorded'];
            }
            $age = CarbonImmutable::parse($last)->diffInSeconds(now(), false);
            $stale = (int) config('hq.scheduler.heartbeat_stale_after_seconds', 180);

            return ['status' => $age <= $stale ? 'ok' : 'fail', 'last_tick_age_seconds' => (int) $age];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'error' => class_basename($e)];
        }
    }
}
