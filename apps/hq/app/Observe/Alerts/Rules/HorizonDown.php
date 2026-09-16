<?php

namespace App\Observe\Alerts\Rules;

use App\Observe\Alerts\AlertCondition;
use App\Observe\Alerts\AlertRule;
use Carbon\CarbonImmutable;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/** No Horizon master supervisor is running, or every one is paused (docs/48 N.5). */
final class HorizonDown implements AlertRule
{
    public function __construct(private readonly MasterSupervisorRepository $supervisors) {}

    public function key(): string
    {
        return 'queue.horizon_down';
    }

    public function pagedBy(): ?string
    {
        return 'hq-monitor.sh';
    }

    public function evaluate(CarbonImmutable $now): ?array
    {
        try {
            $masters = collect($this->supervisors->all());
        } catch (Throwable) {
            return null; // Redis unreachable: the host monitor reports that directly.
        }

        if ($masters->isEmpty()) {
            return [new AlertCondition('horizon', 'critical', 'Horizon is not running',
                'No Horizon master supervisor is running, so queued work (scheduled runs, event handling) is not being processed. Look: docker compose logs --tail 100 horizon.')];
        }

        if ($masters->every(fn ($m) => ($m->status ?? '') === 'paused')) {
            return [new AlertCondition('horizon', 'warning', 'Horizon is paused',
                'Every Horizon supervisor is paused; queued work waits until someone continues it (php artisan horizon:continue).',
                ['supervisors' => $masters->count()])];
        }

        return [];
    }
}
