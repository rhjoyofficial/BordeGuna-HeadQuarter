<?php

use App\Gateway\Registry\ToolAvailability;
use App\Gateway\Registry\ToolSwitch;
use App\Models\Business;
use App\Models\FeatureFlag;
use App\Models\IntegrationCredential;
use App\Models\JobRun;
use App\Models\ScheduledJob;
use App\Models\ToolCall;
use App\Models\User;
use App\Scheduling\Enums\RunStatus;
use App\Scheduling\Jobs\RunScheduledJob;
use App\Scheduling\ScheduledJobService;
use App\Support\Features\Features;
use App\Tools\ToolRegistry;
use Carbon\CarbonImmutable;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Tools\AlwaysFailingTool;
use Tests\Support\Tools\CountingTool;

/*
 | M2: the scheduler as an ordinary gateway caller (ADR-016, docs/50).
 |
 | The acceptance criterion is EQUIVALENCE, not merely "it still runs". Each live job is executed on
 | the direct path and on the gateway path and the two outputs are compared field by field, with only
 | the fields that are necessarily nondeterministic normalised away.
 |
 | ⚠ The defect this milestone had to fix first: every one of the live jobs names its target in its
 | PAYLOAD (`business_slug`, `store_slug`) and leaves the request's business and store null. M1's
 | scope check only looked at the request, so routing the scheduler through the gateway unchanged
 | would have DENIED four of the six production jobs. Several tests below exist purely to keep that
 | from coming back.
 */

const ERP_BASE = 'https://erp.test';

/** Fields a re-run necessarily changes, and which therefore cannot be part of an equivalence check. */
const NONDETERMINISTIC = ['as_of', 'since', 'executed_at', 'idempotency_key', 'attempt'];

function normalise(array $output): array
{
    foreach (NONDETERMINISTIC as $key) {
        unset($output[$key]);
    }
    ksort($output);

    return $output;
}

function schedulerUser(): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->create();
    $user->assignRole('owner');

    return $user->fresh();
}

function makeJob(string $toolKey, array $payload, ?User $owner = null, ?int $businessId = null): ScheduledJob
{
    return app(ScheduledJobService::class)->create([
        'name' => $toolKey.' job',
        'kind' => 'cron',
        'cron_expression' => '0 4 * * *',
        'tool_key' => $toolKey,
        'business_id' => $businessId,
        'payload' => $payload,
    ], $owner);
}

function runOnce(ScheduledJob $job): JobRun
{
    $run = JobRun::create([
        'scheduled_job_id' => $job->id,
        'scheduled_for' => CarbonImmutable::now()->addSeconds(random_int(1, 100000)),
        'trigger' => 'run_now',
        'attempt' => 0,
        'status' => RunStatus::Queued,
        'correlation_id' => (string) Str::uuid(),
    ]);

    app()->call([new RunScheduledJob($run->id), 'handle']);

    return $run->fresh();
}

function gatewayMode(bool $on): void
{
    FeatureFlag::updateOrCreate(
        ['key' => 'scheduler.gateway', 'environment' => '*'],
        ['enabled' => $on],
    );
    app(Features::class)->forget();
    Cache::forget('hq:feature_flags');
}

beforeEach(function () {
    Cache::flush();
    CountingTool::reset();
    gatewayMode(false);
});

// ---------------------------------------------------------------- the flag itself

it('runs on the direct path by default, so the known-good behaviour is what production keeps', function () {
    app(ToolRegistry::class)->register(CountingTool::class);
    $job = makeJob('test.counting', ['label' => 'x'], schedulerUser());

    $run = runOnce($job);

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and(CountingTool::$executions)->toBe(1)
        // The tell-tale: direct mode never touches the gateway, so there is no tool_calls row.
        ->and(ToolCall::count())->toBe(0);
});

it('runs through the gateway when the flag is on, and records the call', function () {
    app(ToolRegistry::class)->register(CountingTool::class);
    app(ToolAvailability::class)->forget();
    gatewayMode(true);
    $user = schedulerUser();
    $job = makeJob('test.counting', ['label' => 'x'], $user);

    $run = runOnce($job);

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and(CountingTool::$executions)->toBe(1);

    $call = ToolCall::where('tool_key', 'test.counting')->sole();
    expect($call->status)->toBe('succeeded')
        ->and($call->caller_kind)->toBe('scheduler')
        ->and($call->policy_result)->toBe('allow')
        // A scheduled job runs AS its owning human, not as an anonymous process.
        ->and($call->enabling_user_id)->toBe($user->id)
        ->and($call->job_run_id)->toBe($run->id)
        ->and($call->correlation_id)->toBe($run->correlation_id);
});

it('can be switched back to direct mode, which is the rollback for the migration', function () {
    app(ToolRegistry::class)->register(CountingTool::class);
    app(ToolAvailability::class)->forget();
    $user = schedulerUser();
    $job = makeJob('test.counting', ['label' => 'x'], $user);

    gatewayMode(true);
    runOnce($job);
    expect(ToolCall::count())->toBe(1);

    gatewayMode(false);
    runOnce($job);
    expect(ToolCall::count())->toBe(1)->and(CountingTool::$executions)->toBe(2);
});

// ---------------------------------------------------------------- equivalence

it('produces equivalent output on both paths for the ERP poll jobs', function () {
    $this->seed(DomainSeeder::class);
    config(['hq.erp.base_url' => ERP_BASE]);
    $business = Business::where('slug', 'bor-de-guna')->sole();
    IntegrationCredential::create([
        'provider' => 'erp_api_token', 'account_ref' => 'bor-de-guna', 'business_id' => $business->id,
        'credential_type' => 'token', 'payload' => ['value' => 'tok'], 'status' => 'active',
    ]);
    Http::fake([
        ERP_BASE.'/api/hq/v1/4/overview' => Http::response(['data' => [
            'sales' => ['customers' => 1], 'inventory' => ['stock_value' => 0], 'purchasing' => null,
            'approvals' => ['pending' => 1], 'tickets' => ['open' => 1],
        ], 'meta' => ['as_of' => '2026-09-08T10:00:00+06:00']]),
        ERP_BASE.'/api/hq/v1/4/stock/low' => Http::response(['data' => [], 'meta' => ['count' => 0, 'as_of' => '2026-09-08T10:00:00+06:00']]),
    ]);

    // Exactly the shape of the live rows: the business is in the PAYLOAD, and business_id is null.
    foreach (['erp.poll_overview', 'erp.poll_low_stock'] as $key) {
        $job = makeJob($key, ['business_slug' => 'bor-de-guna']);

        gatewayMode(false);
        $direct = runOnce($job)->output['output'];

        gatewayMode(true);
        $viaGateway = runOnce($job)->output['output'];

        expect(normalise($viaGateway))->toBe(normalise($direct), "[{$key}] output differs between the two paths");
    }
});

it('produces equivalent output on both paths for the fact integrity job', function () {
    $this->seed(DomainSeeder::class);
    $job = makeJob('commerce.check_fact_integrity', ['hours' => 168]);

    gatewayMode(false);
    $direct = runOnce($job)->output['output'];

    gatewayMode(true);
    $viaGateway = runOnce($job)->output['output'];

    expect(normalise($viaGateway))->toBe(normalise($direct))
        ->and($viaGateway['discrepancies'])->toBe(0);
});

it('produces equivalent output on both paths for a shop reconcile job', function () {
    $this->seed(DomainSeeder::class);
    IntegrationCredential::create(['provider' => 'shop_admin_token', 'account_ref' => 'laladia-com', 'credential_type' => 'token', 'payload' => ['value' => 'admin'], 'status' => 'active']);
    Http::fake(['*' => Http::response(['data' => [], 'meta' => ['total' => 0]])]);

    $job = makeJob('commerce.reconcile_facts', ['store_slug' => 'laladia-com', 'hours' => 168]);

    gatewayMode(false);
    $direct = runOnce($job)->output['output'];

    gatewayMode(true);
    $viaGateway = runOnce($job)->output['output'];

    expect(normalise($viaGateway))->toBe(normalise($direct))
        // The safety assertion that matters on production: reconciliation inserted nothing, so no
        // fact changed on either path.
        ->and($viaGateway['inserted'])->toBe(0);
});

// ---------------------------------------------------------------- the scope defect

it('resolves a scoped job target from its payload, which is where the live jobs put it', function () {
    $this->seed(DomainSeeder::class);
    config(['hq.erp.base_url' => ERP_BASE]);
    $business = Business::where('slug', 'bor-de-guna')->sole();
    IntegrationCredential::create([
        'provider' => 'erp_api_token', 'account_ref' => 'bor-de-guna', 'business_id' => $business->id,
        'credential_type' => 'token', 'payload' => ['value' => 'tok'], 'status' => 'active',
    ]);
    Http::fake([ERP_BASE.'/api/hq/v1/4/stock/low' => Http::response(['data' => [], 'meta' => ['count' => 0]])]);
    gatewayMode(true);

    // business_id null on the job, exactly like production. Before the ScopeResolver this was a
    // denial, and four of six live jobs would have stopped on their first run.
    $job = makeJob('erp.poll_low_stock', ['business_slug' => 'bor-de-guna']);
    $run = runOnce($job);

    expect($run->status)->toBe(RunStatus::Succeeded);

    $call = ToolCall::where('tool_key', 'erp.poll_low_stock')->sole();
    expect($call->policy_result)->toBe('allow')
        // And the row now says which business it was for, which it could not before.
        ->and($call->business_id)->toBe($business->id);
});

it('refuses a scoped job whose target cannot be resolved anywhere', function () {
    $this->seed(DomainSeeder::class);
    gatewayMode(true);

    $job = makeJob('erp.poll_overview', ['business_slug' => 'no-such-business']);
    $run = runOnce($job);

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->error)->toContain('which HQ does not have');
});

// ---------------------------------------------------------------- identity and controls

it('runs an ownerless job as the narrow system identity, which is what the live jobs are', function () {
    $this->seed(DomainSeeder::class);
    gatewayMode(true);

    // Jobs 2 to 6 in production have no owner.
    $job = makeJob('commerce.check_fact_integrity', ['hours' => 168], null);
    $run = runOnce($job);

    expect($run->status)->toBe(RunStatus::Succeeded);
    $call = ToolCall::where('tool_key', 'commerce.check_fact_integrity')->sole();
    expect($call->actor_type)->toBe('service')
        ->and($call->actor_label)->toBe('system')
        ->and($call->enabling_user_id)->toBeNull();
});

it('lets the runtime kill switch stop a scheduled job, with no exemption for the scheduler', function () {
    app(ToolRegistry::class)->register(CountingTool::class);
    app(ToolAvailability::class)->forget();
    gatewayMode(true);
    $user = schedulerUser();
    $job = makeJob('test.counting', ['label' => 'x'], $user);

    app(ToolSwitch::class)->disable('test.counting', 'stopping it', $user);
    $run = runOnce($job);

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->error)->toContain('disabled at runtime')
        ->and(CountingTool::$executions)->toBe(0);
});

it('keeps the scheduler owning retry, history and dead-lettering in gateway mode', function () {
    app(ToolRegistry::class)->register(AlwaysFailingTool::class);
    app(ToolAvailability::class)->forget();
    gatewayMode(true);
    $job = makeJob('test.always_fail', [], schedulerUser());
    $job->update(['retry_max' => 0]);

    $run = runOnce($job);

    // retry_max 0 means the first failure is terminal: the run is dead and needs a human.
    expect($run->status)->toBe(RunStatus::Dead)
        ->and($run->attempts)->toHaveCount(1)
        ->and($run->error)->not->toBeNull();
});
