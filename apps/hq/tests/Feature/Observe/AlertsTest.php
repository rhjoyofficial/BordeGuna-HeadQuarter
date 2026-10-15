<?php

use App\Ai\CircuitBreaker;
use App\Ai\Providers\ProviderRegistry;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\IntegrationCredential;
use App\Models\ShadowPeriod;
use App\Observe\Alerts\AlertCondition;
use App\Observe\Alerts\AlertEngine;
use App\Observe\Alerts\AlertRule;
use App\Observe\Alerts\Rules;
use App\Observe\Alerts\WebhookSignatureFailures;
use App\Observe\HostProbe;
use App\Scheduling\Commands\SchedulerTick;
use Carbon\CarbonImmutable;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

/*
 | docs/78 B.2, docs/48 N.5: the alert engine and its sixteen rules (docs/116 added `agents.guard_gateway_disagreement`).
 */

final class AlertsTestRule implements AlertRule
{
    /** @var list<AlertCondition>|Throwable|null */
    public static mixed $next = [];

    public static ?string $pagedBy = null;

    public function key(): string
    {
        return 'test.rule';
    }

    public function pagedBy(): ?string
    {
        return self::$pagedBy;
    }

    public function evaluate(CarbonImmutable $now): ?array
    {
        if (self::$next instanceof Throwable) {
            throw self::$next;
        }

        return self::$next;
    }
}

beforeEach(function () {
    config(['hq.alerts.telegram_bot_token' => '', 'hq.alerts.telegram_chat_id' => '']);
    Http::fake();
    AlertsTestRule::$next = [];
    AlertsTestRule::$pagedBy = null;
});

function alrCondition(string $severity = 'warning', string $subject = 'Something is wrong'): AlertCondition
{
    return new AlertCondition('x', $severity, $subject, 'What to do about it.', ['count' => 1]);
}

/** @return list<string> the subjects HQ sent for one fingerprint, oldest first */
function alrSent(string $fingerprint): array
{
    return AuditLog::where('action', 'notification.raised')->orderBy('id')->get()
        ->filter(fn (AuditLog $a) => ($a->after['fingerprint'] ?? null) === $fingerprint)
        ->map(fn (AuditLog $a) => $a->after['subject'])->values()->all();
}

function alrCheck(): array
{
    config(['hq.alerts.rules' => [AlertsTestRule::class]]);

    return app(AlertEngine::class)->check();
}

/** @return list<AlertCondition>|null */
function alrRule(string $class): ?array
{
    return app($class)->evaluate(CarbonImmutable::now());
}

it('opens an alert once, counts it, clears it after two quiet checks, and opens a new row when it recurs', function () {
    AlertsTestRule::$next = [alrCondition()];
    expect(alrCheck()['opened'])->toBe(['test.rule:x']);

    $alert = Alert::sole();
    expect($alert->isOpen())->toBeTrue()
        ->and($alert->occurrences)->toBe(1)
        ->and($alert->notified_at)->not->toBeNull()
        ->and($alert->paged_by)->toBeNull()
        ->and(AuditLog::where('action', 'alert.opened')->count())->toBe(1)
        ->and(alrSent('test.rule:x'))->toBe(['HQ alert (warning): Something is wrong']);

    expect(alrCheck()['still_open'])->toBe(['test.rule:x'])
        ->and($alert->fresh()->occurrences)->toBe(2)
        ->and(alrSent('test.rule:x'))->toHaveCount(1);

    // One quiet check is not enough; a sighting in between starts the count again.
    AlertsTestRule::$next = [];
    expect(alrCheck()['cleared'])->toBe(['test.rule:x'])->and($alert->fresh()->isOpen())->toBeTrue();
    AlertsTestRule::$next = [alrCondition()];
    alrCheck();
    expect($alert->fresh()->clear_checks)->toBe(0);

    AlertsTestRule::$next = [];
    alrCheck();
    expect(alrCheck()['resolved'])->toBe(['test.rule:x'])
        ->and($alert->fresh()->resolved_at)->not->toBeNull()
        ->and(AuditLog::where('action', 'alert.resolved')->count())->toBe(1)
        ->and(alrSent('test.rule:x')[1])->toBe('HQ alert cleared: Something is wrong');

    AlertsTestRule::$next = [alrCondition()];
    expect(alrCheck()['opened'])->toBe(['test.rule:x'])
        ->and(Alert::count())->toBe(2)
        ->and(Alert::open()->count())->toBe(1);
});

it('records an alert something else pages without sending it', function () {
    AlertsTestRule::$pagedBy = 'hq-monitor.sh';
    AlertsTestRule::$next = [alrCondition('critical')];
    alrCheck();

    $alert = Alert::sole();
    expect($alert->paged_by)->toBe('hq-monitor.sh')
        ->and($alert->notified_at)->toBeNull()
        ->and(AuditLog::where('action', 'alert.opened')->count())->toBe(1)
        ->and(alrSent('test.rule:x'))->toBe([]);

    AlertsTestRule::$next = [];
    alrCheck();
    alrCheck();
    expect($alert->fresh()->resolved_at)->not->toBeNull()->and(alrSent('test.rule:x'))->toBe([]);
});

it('leaves open alerts alone when a rule cannot tell or fails', function () {
    AlertsTestRule::$next = [alrCondition()];
    alrCheck();

    AlertsTestRule::$next = null;
    expect(alrCheck()['unknown'])->toBe(['test.rule']);
    Log::spy();
    AlertsTestRule::$next = new RuntimeException('connection refused to 10.0.0.1 with password hunter2');
    expect(alrCheck()['failed'])->toBe(['test.rule']);
    alrCheck();

    // The log names the rule and the exception class, never the message.
    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $message === 'alert rule failed'
        && $context === ['rule' => 'test.rule', 'exception' => RuntimeException::class]);

    $alert = Alert::sole();
    expect($alert->isOpen())->toBeTrue()
        ->and($alert->occurrences)->toBe(1)
        ->and($alert->clear_checks)->toBe(0);
});

it('sends an open alert again when it gets worse, and once a day while it stays open', function () {
    AlertsTestRule::$next = [alrCondition('warning')];
    alrCheck();

    AlertsTestRule::$next = [alrCondition('critical')];
    alrCheck();
    expect(alrSent('test.rule:x'))->toBe(['HQ alert (warning): Something is wrong', 'HQ alert now critical: Something is wrong'])
        ->and(Alert::sole()->severity)->toBe('critical');

    alrCheck();
    expect(alrSent('test.rule:x'))->toHaveCount(2);

    $this->travel(25)->hours();
    alrCheck();
    alrCheck();
    expect(alrSent('test.rule:x'))->toHaveCount(3)
        ->and(alrSent('test.rule:x')[2])->toBe('HQ alert still open: Something is wrong');
});

it('lists the seventeen rules, each resolvable, and the check command runs them all without a failure', function () {
    $this->mock(MasterSupervisorRepository::class, fn ($m) => $m->shouldReceive('all')->andReturn([(object) ['status' => 'running']]));
    $this->mock(HostProbe::class, function ($m) {
        $m->shouldReceive('disk')->andReturn(['path' => '/', 'total_bytes' => 100, 'free_bytes' => 60, 'used_percent' => 40]);
        $m->shouldReceive('memory')->andReturn(['available_mb' => 4000, 'total_mb' => 8000]);
    });
    Cache::put(SchedulerTick::HEARTBEAT_KEY, now()->toIso8601String(), 600);

    $keys = array_map(fn (AlertRule $r) => $r->key(), app(AlertEngine::class)->rules());
    // Seventeen since docs/123: followups.overdue, addressed to the responsible person only.
    expect($keys)->toHaveCount(17)->and(array_unique($keys))->toHaveCount(17);

    expect(Artisan::call('hq:alerts:check'))->toBe(0);
    $output = Artisan::output();
    expect($output)->not->toContain('failed')->not->toContain('unknown');

    // A blank HQ has exactly one thing a person must act on: no model provider key.
    expect(Alert::open()->pluck('fingerprint')->all())->toBe(['readiness.incident:hq:hq:anthropic_api_key']);
});

it('alerts on a stale or missing scheduler tick', function () {
    Cache::forget(SchedulerTick::HEARTBEAT_KEY);
    expect(alrRule(Rules\SchedulerTickStale::class)[0]->severity)->toBe('critical');

    Cache::put(SchedulerTick::HEARTBEAT_KEY, now()->subSeconds(30)->toIso8601String(), 600);
    expect(alrRule(Rules\SchedulerTickStale::class))->toBe([]);

    Cache::put(SchedulerTick::HEARTBEAT_KEY, now()->subMinutes(10)->toIso8601String(), 600);
    expect(alrRule(Rules\SchedulerTickStale::class)[0]->context['age_seconds'])->toBeGreaterThanOrEqual(600);
});

it('alerts when Horizon is down or paused, and cannot tell when Redis is unreachable', function () {
    $masters = [];
    $this->mock(MasterSupervisorRepository::class, function ($m) use (&$masters) {
        $m->shouldReceive('all')->andReturnUsing(function () use (&$masters) {
            if ($masters === null) {
                throw new RuntimeException('redis');
            }

            return $masters;
        });
    });

    expect(alrRule(Rules\HorizonDown::class)[0]->severity)->toBe('critical');
    $masters = [(object) ['status' => 'paused']];
    expect(alrRule(Rules\HorizonDown::class)[0]->subject)->toBe('Horizon is paused');
    $masters = [(object) ['status' => 'running'], (object) ['status' => 'paused']];
    expect(alrRule(Rules\HorizonDown::class))->toBe([]);
    $masters = null;
    expect(alrRule(Rules\HorizonDown::class))->toBeNull();
});

it('alerts on an open provider circuit only', function () {
    $provider = app(ProviderRegistry::class)->keys()[0];
    $breaker = app(CircuitBreaker::class);

    expect(alrRule(Rules\ProviderCircuitOpen::class))->toBe([]);
    foreach (range(1, CircuitBreaker::THRESHOLD) as $i) {
        $breaker->recordFailure($provider);
    }
    expect(alrRule(Rules\ProviderCircuitOpen::class)[0]->subjectKey)->toBe("provider:{$provider}");

    $breaker->reset($provider);
    expect(alrRule(Rules\ProviderCircuitOpen::class))->toBe([]);
});

it('records budget pressure at the notify line and at the cap', function () {
    $spend = fn (string $usd) => DB::table('ai_runs')->insert(['task' => 'probe', 'status' => 'succeeded', 'estimated_cost_usd' => $usd, 'created_at' => now(), 'updated_at' => now()]);

    expect(alrRule(Rules\AiBudgetPressure::class))->toBe([]);
    $spend('45.000000');
    $conditions = alrRule(Rules\AiBudgetPressure::class);
    expect($conditions)->toHaveCount(1)
        ->and($conditions[0]->severity)->toBe('warning')
        ->and($conditions[0]->subject)->toContain('90%');
    $spend('10.000000');
    expect(alrRule(Rules\AiBudgetPressure::class)[0]->severity)->toBe('critical')
        ->and(app(Rules\AiBudgetPressure::class)->pagedBy())->toBe('model runtime');
});

it('counts approvals past their deadline', function () {
    $approval = fn (string $status, ?CarbonImmutable $due) => DB::table('approvals')->insert([
        'uuid' => (string) Str::uuid(), 'tool_key' => 'diagnostics.approval_demo', 'tool_version' => '1.0.0',
        'payload' => '{}', 'payload_hash' => str_repeat('b', 64), 'risk_tier' => 'r4', 'action_class' => 'approval_required',
        'status' => $status, 'sla_due_at' => $due, 'decided_at' => $status === 'approved' ? now() : null,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $approval('pending', now()->addHour()->toImmutable());
    $approval('approved', now()->subHour()->toImmutable());
    expect(alrRule(Rules\ApprovalsPastDeadline::class))->toBe([]);

    $approval('pending', now()->subHours(2)->toImmutable());
    expect(alrRule(Rules\ApprovalsPastDeadline::class)[0]->context['count'])->toBe(1);
});

it('alerts on an agent denied three times in the window, or stopped for repeated denials', function () {
    $deny = fn (string $agent, int $minutesAgo) => DB::table('tool_calls')->insert([
        'tool_key' => 'facts.query_orders', 'agent_key' => $agent, 'status' => 'denied', 'created_at' => now()->subMinutes($minutesAgo),
    ]);

    $deny('executive', 5);
    $deny('executive', 10);
    $deny('executive', 90);
    $deny('support', 5);
    $deny('support', 6);
    expect(alrRule(Rules\RepeatedPolicyDenials::class))->toBe([]);

    $deny('executive', 20);
    AgentRun::create(['uuid' => (string) Str::uuid(), 'agent_key' => 'analytics', 'correlation_id' => (string) Str::uuid(),
        'trigger' => 'console', 'question' => 'q', 'autonomy_level' => 'a0', 'status' => 'stopped', 'stop_reason' => 'repeated_denial']);

    $conditions = alrRule(Rules\RepeatedPolicyDenials::class);
    expect(array_map(fn ($c) => $c->subjectKey, $conditions))->toBe(['agent:analytics', 'agent:executive'])
        ->and($conditions[1]->context['denied'])->toBe(3);
});

it('reads the latest integrity check result', function () {
    $check = fn (int $found) => DB::table('tool_calls')->insert([
        'tool_key' => Rules\FactIntegrityDiscrepancy::TOOL, 'status' => 'succeeded', 'created_at' => now(),
        'output' => json_encode(['store' => 'all', 'checked' => 16, 'discrepancies' => $found]),
    ]);

    expect(alrRule(Rules\FactIntegrityDiscrepancy::class))->toBe([]);
    $check(2);
    expect(alrRule(Rules\FactIntegrityDiscrepancy::class)[0]->context['discrepancies'])->toBe(2);
    $check(0);
    expect(alrRule(Rules\FactIntegrityDiscrepancy::class))->toBe([]);
});

it('alerts on recent dead letters only', function () {
    $event = fn (string $status, int $daysAgo) => DB::table('events')->insert([
        'event_id' => (string) Str::uuid(), 'type' => 'order.status_changed', 'direction' => 'inbound', 'source' => 'laladia',
        'payload' => '{}', 'dedupe_key' => (string) Str::uuid(), 'occurred_at' => now()->subDays($daysAgo),
        'created_at' => now()->subDays($daysAgo), 'status' => $status,
    ]);

    $event('dead', 9);
    $event('processed', 1);
    expect(alrRule(Rules\EventsDeadLettered::class))->toBe([]);

    $event('dead', 1);
    $condition = alrRule(Rules\EventsDeadLettered::class)[0];
    expect($condition->context['count'])->toBe(1)->and($condition->context['by_type'])->toBe(['order.status_changed' => 1]);
});

it('alerts on a credential expiring soon or already expired, never showing its value', function () {
    $credential = fn (string $provider, ?CarbonImmutable $expires, string $status = 'active') => IntegrationCredential::create([
        'provider' => $provider, 'account_ref' => 'acct', 'credential_type' => 'token', 'payload' => ['value' => 'sk-secret-value'],
        'status' => $status, 'expires_at' => $expires,
    ]);

    $credential('far', now()->addDays(30)->toImmutable());
    $credential('never', null);
    $credential('revoked', now()->addDay()->toImmutable(), 'revoked');
    expect(alrRule(Rules\CredentialExpiring::class))->toBe([]);

    $soon = $credential('soon', now()->addDays(3)->toImmutable());
    $gone = $credential('gone', now()->subDay()->toImmutable());
    $conditions = collect(alrRule(Rules\CredentialExpiring::class))->keyBy('subjectKey');

    expect($conditions["credential:{$soon->id}"]->severity)->toBe('warning')
        ->and($conditions["credential:{$gone->id}"]->severity)->toBe('critical')
        ->and($conditions["credential:{$gone->id}"]->subject)->toBe('Credential gone (acct) has expired')
        ->and(json_encode($conditions->all()))->not->toContain('sk-secret-value');
});

it('alerts on an ERP snapshot the daily poll has not refreshed', function () {
    $business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bordeguna', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
    $snapshot = fn (string $kind, int $hoursAgo) => DB::table('facts_erp_snapshots')->insert([
        'business_id' => $business->id, 'kind' => $kind, 'payload' => '{}', 'as_of' => now()->subHours($hoursAgo),
        'polled_at' => now()->subHours($hoursAgo), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $snapshot('overview', 20);
    expect(alrRule(Rules\SnapshotStale::class))->toBe([]);

    $snapshot('low_stock', 30);
    expect(alrRule(Rules\SnapshotStale::class)[0]->subjectKey)->toBe('erp:bordeguna/low_stock');

    $business->update(['is_active' => false]);
    expect(alrRule(Rules\SnapshotStale::class))->toBe([]);
});

it('turns readiness incidents into alerts, and says a credit block is about credit', function () {
    $conditions = alrRule(Rules\ReadinessIncident::class);
    expect($conditions)->toHaveCount(1)
        ->and($conditions[0]->subject)->toBe('HQ model provider cannot be used: credential_missing');

    $this->seed(DomainSeeder::class);
    $stores = collect(alrRule(Rules\ReadinessIncident::class))->pluck('subjectKey')->filter(fn ($k) => str_starts_with($k, 'store:'));
    expect($stores)->not->toBeEmpty();

    $credit = (new ReflectionClassConstant(Rules\ReadinessIncident::class, 'WHAT_TO_DO'))->getValue()['provider_blocked_by_credit'];
    expect($credit)->toContain('no credit')->toContain('not judged');
});

it('alerts on a stopped shadow period until a newer one opens or the window passes', function () {
    $agent = Agent::create(['key' => 'executive', 'name' => 'Executive']);
    $period = fn (string $status, int $openedDaysAgo, ?int $closedDaysAgo) => ShadowPeriod::create([
        'uuid' => (string) Str::uuid(), 'agent_id' => $agent->id, 'agent_key' => 'executive', 'status' => $status,
        'opened_at' => now()->subDays($openedDaysAgo), 'planned_end_at' => now()->subDays($openedDaysAgo)->addDays(7),
        'closed_at' => $closedDaysAgo === null ? null : now()->subDays($closedDaysAgo), 'reason' => 'test',
        'evidence' => [], 'allowed_tools' => [], 'questions' => [], 'end_reason' => 'a safety condition was broken',
        'violations' => [['condition' => 'only_this_agent']],
    ]);

    $period(ShadowPeriod::STATUS_STOPPED, 20, 12);
    expect(alrRule(Rules\ShadowPeriodStopped::class))->toBe([]);

    $stopped = $period(ShadowPeriod::STATUS_STOPPED, 3, 1);
    $conditions = alrRule(Rules\ShadowPeriodStopped::class);
    expect($conditions)->toHaveCount(1)
        ->and($conditions[0]->severity)->toBe('critical')
        ->and($conditions[0]->subjectKey)->toBe("period:{$stopped->uuid}")
        ->and($conditions[0]->body)->toContain('1 violation(s)');

    $period(ShadowPeriod::STATUS_OPEN, 0, null);
    expect(alrRule(Rules\ShadowPeriodStopped::class))->toBe([]);
});

it('reads disk and memory through the host probe, and cannot tell when the probe cannot', function () {
    $disk = null;
    $memory = null;
    $this->mock(HostProbe::class, function ($m) use (&$disk, &$memory) {
        $m->shouldReceive('disk')->andReturnUsing(function () use (&$disk) {
            return $disk;
        });
        $m->shouldReceive('memory')->andReturnUsing(function () use (&$memory) {
            return $memory;
        });
    });

    expect(alrRule(Rules\HostDisk::class))->toBeNull()->and(alrRule(Rules\HostMemory::class))->toBeNull();

    $disk = ['path' => '/', 'total_bytes' => 100 * 1073741824, 'free_bytes' => 15 * 1073741824, 'used_percent' => 85];
    $memory = ['available_mb' => 512, 'total_mb' => 7800];
    expect(alrRule(Rules\HostDisk::class)[0]->severity)->toBe('warning')
        ->and(alrRule(Rules\HostMemory::class)[0]->subject)->toBe('Only 512 MB of memory available');

    $disk['used_percent'] = 95;
    $memory['available_mb'] = 2048;
    expect(alrRule(Rules\HostDisk::class)[0]->severity)->toBe('critical')
        ->and(alrRule(Rules\HostMemory::class))->toBe([]);

    $disk['used_percent'] = 80;
    expect(alrRule(Rules\HostDisk::class))->toBe([]);
});

it('counts webhook signature failures, from the shop webhook itself, and alerts on a burst', function () {
    $this->seed(DomainSeeder::class);
    IntegrationCredential::create(['provider' => 'shop_webhook_secret', 'account_ref' => 'laladia-com', 'credential_type' => 'token', 'payload' => ['value' => 'whsec'], 'status' => 'active']);

    $this->call('POST', '/api/webhooks/shop/laladia-com', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SHOP_SIGNATURE' => 'forged',
    ], '{"event":"order.created"}')->assertStatus(401);

    $counter = app(WebhookSignatureFailures::class);
    expect($counter->recent(1))->toBe(['meta' => 0, 'shop' => 1])
        ->and(alrRule(Rules\WebhookSignatureFailures::class))->toBe([]);

    foreach (range(1, 9) as $i) {
        $counter->record('meta');
    }
    $condition = alrRule(Rules\WebhookSignatureFailures::class)[0];
    expect($condition->subject)->toBe('10 webhook(s) refused for a bad signature in 2 hours')
        ->and($condition->context['counts'])->toBe(['meta' => 9, 'shop' => 1]);

    $this->travel(3)->hours();
    expect(alrRule(Rules\WebhookSignatureFailures::class))->toBe([]);
});
