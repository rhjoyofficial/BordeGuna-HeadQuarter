<?php

namespace App\Observe\Dashboards;

use App\Ai\CircuitBreaker;
use App\Ai\Providers\ProviderRegistry;
use App\Gateway\Readiness\CapabilityReadiness;
use App\Gateway\Readiness\ReadinessRow;
use App\Models\Alert;
use App\Models\ConnectorCall;
use App\Models\DomainEvent;
use App\Models\IntegrationCredential;
use App\Models\RetentionRun;
use App\Models\RetentionWatermark;
use App\Models\ToolCall;
use App\Observe\Alerts\Rules\FactIntegrityDiscrepancy;
use App\Observe\HostProbe;
use App\Observe\ReleaseState;
use App\Scheduling\Commands\SchedulerTick;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Throwable;

/**
 * Platform (docs/48 N.3, docs/25): the queues, the scheduler, the event inbox, the circuits and the
 * connectors, credentials and readiness, the integrity check, ERP snapshot freshness, the host, the
 * retention passes, the open alerts, and the release state (docs/80 C.4). Group-wide: the permission that opens it is the operators'.
 */
final class PlatformDashboard
{
    public function __construct(
        private readonly MasterSupervisorRepository $supervisors,
        private readonly WorkloadRepository $workload,
        private readonly ProviderRegistry $providers,
        private readonly CircuitBreaker $breaker,
        private readonly CapabilityReadiness $readiness,
        private readonly HostProbe $host,
        private readonly ReleaseState $release,
    ) {}

    public function build(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $timezone = (string) config('hq.timezone', 'Asia/Dhaka');
        $staleHours = (int) config('hq.alerts.thresholds.snapshot_stale_after_hours', 26);

        return [
            'checked_at' => $now->toIso8601String(),
            // docs/80 C.4: what is released here against what is only merged. Read-only, from the image and the host's files.
            'release' => $this->release->build($now),
            'queues' => $this->queues(),
            'scheduler' => $this->scheduler($now),
            'events' => $this->events($now),
            'circuits' => collect($this->breaker->overview($this->providers->keys()))
                ->map(fn (array $s, string $provider) => ['provider' => $provider] + $s)->values()->all(),
            'connectors_24h' => ConnectorCall::query()
                ->where('created_at', '>=', $now->subDay())
                ->selectRaw("connector, count(*) as n, count(*) filter (where status = 'succeeded') as ok, count(*) filter (where status = 'failed') as failed, count(*) filter (where status = 'uncertain') as uncertain, percentile_cont(0.95) within group (order by duration_ms) as p95")
                ->groupBy('connector')->orderBy('connector')->get()
                ->map(fn ($r) => ['connector' => $r->connector, 'calls' => (int) $r->n, 'succeeded' => (int) $r->ok, 'failed' => (int) $r->failed,
                    'uncertain' => (int) $r->uncertain, 'p95_ms' => $r->p95 === null ? null : (int) round((float) $r->p95)])
                ->all(),
            'credentials_expiring' => IntegrationCredential::query()
                ->where('status', 'active')->whereNotNull('expires_at')->where('expires_at', '<=', $now->addDays(30))
                ->orderBy('expires_at')->get(['id', 'provider', 'account_ref', 'expires_at'])
                ->map(fn (IntegrationCredential $c) => ['id' => $c->id, 'provider' => $c->provider, 'account_ref' => $c->account_ref,
                    'expires_at' => $c->expires_at->toIso8601String(), 'expired' => $c->expires_at->lte($now)])
                ->all(),
            'readiness' => array_map(fn (ReadinessRow $r) => [
                'target' => $r->targetName, 'target_kind' => $r->targetKind, 'provider' => $r->provider,
                'state' => $r->state, 'incident' => $r->incident(), 'supported' => $r->supported,
            ], $this->readiness->inventory()),
            'integrity' => $this->integrity(),
            'snapshots' => DB::table('facts_erp_snapshots as s')
                ->join('businesses as b', 'b.id', '=', 's.business_id')
                ->orderBy('b.slug')->orderBy('s.kind')
                ->get(['b.slug', 'b.is_active', 's.kind', 's.polled_at', 's.as_of'])
                ->map(function ($s) use ($now, $staleHours) {
                    $polled = CarbonImmutable::parse($s->polled_at);
                    $age = (int) $polled->diffInHours($now);

                    return ['business' => $s->slug, 'kind' => $s->kind, 'polled_at' => $polled->toIso8601String(),
                        'as_of' => $s->as_of === null ? null : CarbonImmutable::parse($s->as_of)->toIso8601String(),
                        'age_hours' => $age, 'stale' => (bool) $s->is_active && $age >= $staleHours];
                })->all(),
            'snapshot_stale_after_hours' => $staleHours,
            'host' => ['disk' => $this->host->disk(), 'memory' => $this->host->memory(), 'paged_by' => 'hq-monitor.sh'],
            'retention' => $this->retention(),
            'alerts_open' => Alert::open()->selectRaw('severity, count(*) as n')->groupBy('severity')->pluck('n', 'severity')->map(fn ($n) => (int) $n)->all(),
            'timezone' => $timezone,
        ];
    }

    private function queues(): ?array
    {
        try {
            $masters = collect($this->supervisors->all());
            $workload = collect($this->workload->get())
                ->map(fn (array $q) => ['name' => $q['name'], 'length' => (int) $q['length'], 'wait_seconds' => (int) $q['wait'], 'processes' => (int) $q['processes']])
                ->values()->all();
        } catch (Throwable) {
            return null;
        }

        return [
            'masters' => $masters->count(),
            'paused' => $masters->isNotEmpty() && $masters->every(fn ($m) => ($m->status ?? '') === 'paused'),
            'workload' => $workload,
        ];
    }

    private function scheduler(CarbonImmutable $now): array
    {
        $last = Cache::get(SchedulerTick::HEARTBEAT_KEY);
        $threshold = (int) config('hq.scheduler.heartbeat_stale_after_seconds', 180);
        $age = $last === null ? null : (int) CarbonImmutable::parse($last)->diffInSeconds($now, false);

        return ['last_tick' => $last, 'age_seconds' => $age, 'stale_after_seconds' => $threshold, 'stale' => $age === null || $age > $threshold];
    }

    private function events(CarbonImmutable $now): array
    {
        $oldestWaiting = DomainEvent::query()->whereIn('status', ['pending', 'failed'])->min('created_at');

        return [
            'waiting' => DomainEvent::query()->whereIn('status', ['pending', 'failed'])->count(),
            'oldest_waiting_minutes' => $oldestWaiting === null ? null : (int) CarbonImmutable::parse($oldestWaiting)->diffInMinutes($now),
            'dead' => DomainEvent::query()->where('status', DomainEvent::STATUS_DEAD)->count(),
            'dead_last_7_days' => DomainEvent::query()->where('status', DomainEvent::STATUS_DEAD)->where('created_at', '>=', $now->subDays(7))->count(),
            'last_occurred_at' => DomainEvent::query()->max('occurred_at'),
        ];
    }

    private function integrity(): ?array
    {
        $latest = ToolCall::query()->where('tool_key', FactIntegrityDiscrepancy::TOOL)->where('status', 'succeeded')->latest('id')->first();

        if ($latest === null) {
            return null;
        }

        return [
            'at' => $latest->created_at?->toIso8601String(),
            'checked' => (int) ($latest->output['checked'] ?? 0),
            'discrepancies' => (int) ($latest->output['discrepancies'] ?? 0),
            'without_lines' => (int) ($latest->output['without_lines'] ?? 0),
        ];
    }

    private function retention(): array
    {
        $last = RetentionRun::query()->latest('id')->first();
        $removed = $last === null ? 0 : collect($last->summary['tables'] ?? [])->sum('rows_aggregated_and_deleted');

        return [
            'last_run' => $last === null ? null : [
                'uuid' => $last->uuid,
                'status' => $last->status,
                'policy_version' => $last->policy_version,
                'started_at' => $last->started_at->toIso8601String(),
                'finished_at' => $last->finished_at?->toIso8601String(),
                'rows_removed' => (int) $removed,
                'payloads_compacted' => (int) ($last->summary['event_payloads']['compacted'] ?? 0),
            ],
            'watermarks' => RetentionWatermark::query()->orderBy('source_table')->get()
                ->map(fn (RetentionWatermark $w) => ['table' => $w->source_table, 'through_day' => $w->through_day->toDateString()])->all(),
            'policy' => [
                'detail_keep_days' => config('hq.retention.detail_keep_days'),
                'event_payload_keep_days' => config('hq.retention.event_payload_keep_days'),
            ],
        ];
    }
}
