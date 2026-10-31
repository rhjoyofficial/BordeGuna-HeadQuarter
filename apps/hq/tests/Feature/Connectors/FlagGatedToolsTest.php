<?php

use App\Agents\AgentToolset;
use App\Gateway\OutcomeClass;
use App\Gateway\OutcomeStatus;
use App\Gateway\Registry\ToolAvailability;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\Agent;
use App\Models\AutonomyGrant;
use App\Models\FeatureFlag;
use App\Models\JobRun;
use App\Models\ScheduledJob;
use App\Scheduling\Enums\RunStatus;
use App\Scheduling\Jobs\RunScheduledJob;
use App\Scheduling\ScheduledJobService;
use App\Tools\FlaggedTools;
use App\Tools\ToolRegistry;
use Carbon\CarbonImmutable;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Execution\Contexts;
use Tests\Support\Tools\CountingTool;
use Tests\Support\Tools\WriteTool;

/*
 | Ruling 31 Oct 2026 item 7 (docs/144-connectors-overview "Applied ruling 31 Oct"): ALWAYS-REGISTERED descriptors with FLAG-GATED
 | availability. The flag is read live on every execution, a disabled tool is refused with the typed `feature_disabled` (never
 | "unknown tool"), a disabled tool is never offered to an agent, a long-lived queue worker cannot run a tool whose flag went off
 | after it started, and switching a flag ON grants no permission, no agent tool, no autonomy and no approval.
 |
 | Every flag change here is made the way another process makes it: straight into the table, with no model event.
 */

const R2_TEST_FLAG = 'test.r2_flag';

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    CountingTool::reset();
    // A test-only flag-gated pair: an R1 read and an R4 write, gated by one flag that starts OFF.
    config(['hq.flagged_tools' => [...(array) config('hq.flagged_tools'), R2_TEST_FLAG => [CountingTool::class, WriteTool::class]]]);
    r2RebuildToolStack();
    $this->owner = $this->hqUser('owner');
});

function r2RebuildToolStack(): void
{
    foreach ([ToolRegistry::class, ToolGateway::class, ToolAvailability::class, FlaggedTools::class, AgentToolset::class] as $class) {
        app()->forgetInstance($class);
    }
}

function r2SetFlag(string $key, bool $on): void
{
    // Raw, as a second container or a restore would write it.
    DB::table('feature_flags')->updateOrInsert(['key' => $key, 'environment' => '*'], ['enabled' => $on, 'created_at' => now(), 'updated_at' => now()]);
}

function r2RunJob(ScheduledJob $job): JobRun
{
    $run = JobRun::create([
        'scheduled_job_id' => $job->id, 'scheduled_for' => CarbonImmutable::now()->addSeconds(random_int(1, 100000)),
        'trigger' => 'run_now', 'attempt' => 0, 'status' => RunStatus::Queued, 'correlation_id' => (string) Str::uuid(),
    ]);
    app()->call([new RunScheduledJob($run->id), 'handle']);

    return $run->fresh();
}

it('registers every flag-gated tool descriptor whatever its flag says, and lists each with its flag', function () {
    $registry = app(ToolRegistry::class);
    $flagged = app(FlaggedTools::class);

    foreach ((array) config('hq.flagged_tools') as $flag => $classes) {
        foreach ($classes as $class) {
            $key = app($class)->key();
            expect($registry->has($key))->toBeTrue("[{$key}] is not registered")
                ->and($flagged->flagFor($key))->toBe($flag)
                ->and(app(ToolAvailability::class)->isAvailable($key))->toBe(false, "[{$key}] is available with its flag off");
        }
    }
    // The three named by the ruling's brief are among them.
    foreach (['courier.track_parcel', 'briefing.send_owner_telegram', 'avatar.generate'] as $key) {
        expect($flagged->flagFor($key))->not->toBeNull();
    }
});

it('refuses a disabled tool with the typed feature_disabled outcome, never as an unknown tool, and runs nothing', function () {
    $outcome = app(ToolGateway::class)->execute(ToolRequest::forOperator($this->owner, 'test.counting'));

    expect($outcome->ok())->toBeFalse()
        ->and($outcome->status)->toBe(OutcomeStatus::Unavailable)
        ->and($outcome->class)->toBe(OutcomeClass::FeatureDisabled)
        ->and($outcome->class->isControlPlane())->toBeTrue()
        ->and((string) $outcome->error)->toContain('feature flag ['.R2_TEST_FLAG.']')->not->toContain('Unknown tool')
        ->and(CountingTool::$executions)->toBe(0);

    // A tool that truly does not exist is still "unknown", a different class of answer.
    $unknown = app(ToolGateway::class)->execute(ToolRequest::forOperator($this->owner, 'test.no_such_tool'));
    expect($unknown->class)->toBe(OutcomeClass::CapabilityUnavailable)->and((string) $unknown->error)->toContain('Unknown tool');
});

it('checks the live flag on every execution: a long-lived gateway (a queue worker) stops the moment the flag goes off', function () {
    // The worker boots and resolves its gateway ONCE, while the flag is ON.
    r2SetFlag(R2_TEST_FLAG, true);
    $worker = app(ToolGateway::class);
    expect($worker->execute(ToolRequest::forOperator($this->owner, 'test.counting'))->ok())->toBeTrue()
        ->and(CountingTool::$executions)->toBe(1);

    // Another process switches it off. The same, still-living gateway instance refuses the next call.
    r2SetFlag(R2_TEST_FLAG, false);
    $after = $worker->execute(ToolRequest::forOperator($this->owner, 'test.counting', ['label' => 'second']));
    expect($after->class)->toBe(OutcomeClass::FeatureDisabled)->and(CountingTool::$executions)->toBe(1);

    // And back on: the same instance serves it again, with no restart and no registration step.
    r2SetFlag(R2_TEST_FLAG, true);
    expect($worker->execute(ToolRequest::forOperator($this->owner, 'test.counting', ['label' => 'third']))->ok())->toBeTrue();
});

it('lets no scheduled job run a tool through a queue worker after its flag flipped off', function () {
    r2SetFlag(R2_TEST_FLAG, true);
    $job = app(ScheduledJobService::class)->create(['name' => 'flag-gated job', 'kind' => 'cron', 'cron_expression' => '0 4 * * *',
        'tool_key' => 'test.counting', 'payload' => []], $this->owner);

    // The worker's container is resolved once and reused, as Horizon's is.
    app(ToolGateway::class);
    expect(r2RunJob($job)->status)->toBe(RunStatus::Succeeded)->and(CountingTool::$executions)->toBe(1);

    r2SetFlag(R2_TEST_FLAG, false);
    $run = r2RunJob($job);
    expect($run->status)->not->toBe(RunStatus::Succeeded)->and(CountingTool::$executions)->toBe(1);
    expect(DB::table('tool_calls')->where('tool_key', 'test.counting')->latest('id')->value('outcome_class'))->toBe('feature_disabled');
});

it('never offers a disabled tool to an agent, even one that holds it, and the flag ON grants no agent anything', function () {
    $this->seed(AgentSeeder::class);
    Agent::query()->update(['status' => 'active', 'enabling_user_id' => $this->owner->id]);
    $before = Agent::query()->orderBy('id')->get(['id', 'allowed_tools', 'status'])->toArray();

    // A test agent that HOLDS an agent-executable flag-gated tool: while the flag is off it is not offered, and the
    // refusal names the flag, not "unknown".
    $agent = Agent::query()->firstOrFail();
    $agent->forceFill(['allowed_tools' => [...(array) $agent->allowed_tools, 'test.external_write']])->save();
    expect(app(AgentToolset::class)->keysFor(Contexts::for($agent->fresh())))->not->toContain('test.external_write')
        ->and((string) app(AgentToolset::class)->refusalFor($agent->fresh(), 'test.external_write'))->toContain('feature flag');
    // Control: with the flag on, that same holding agent is offered it, so the flag was the only reason.
    r2SetFlag(R2_TEST_FLAG, true);
    expect(app(AgentToolset::class)->keysFor(Contexts::for($agent->fresh())))->toContain('test.external_write');
    $agent->forceFill(['allowed_tools' => $before[0]['allowed_tools']])->save();

    // Every shipped flag ON: no agent row changed, no autonomy grant appeared, and no shipped flag-gated tool is offered to any
    // agent (none is agent-executable and none is granted).
    foreach (array_keys((array) config('hq.flagged_tools')) as $flag) {
        r2SetFlag($flag, true);
    }
    expect(Agent::query()->orderBy('id')->get(['id', 'allowed_tools', 'status'])->toArray())->toBe($before)
        ->and(AutonomyGrant::query()->count())->toBe(0);
    foreach (Agent::all() as $each) {
        $keys = app(AgentToolset::class)->keysFor(Contexts::for($each));
        foreach ((array) config('hq.flagged_tools') as $flag => $classes) {
            foreach ($classes as $class) {
                if ($flag !== R2_TEST_FLAG) {
                    expect($keys)->not->toContain(app($class)->key());
                    expect(app($class)->descriptor()->agentExecutable)->toBeFalse();
                }
            }
        }
    }
});

it('lets a flag ON bypass nothing: permission, approval and the tool\'s own checks still decide', function () {
    r2SetFlag(R2_TEST_FLAG, true);
    foreach (array_keys((array) config('hq.flagged_tools')) as $flag) {
        r2SetFlag($flag, true);
    }

    // Permission: a person without the tool's permission is refused, flag or no flag.
    $viewer = $this->hqUser('finance_viewer');
    $denied = app(ToolGateway::class)->execute(ToolRequest::forOperator($viewer, 'test.counting'));
    expect($denied->ok())->toBeFalse()->and($denied->class)->toBe(OutcomeClass::PermissionDenied)->and(CountingTool::$executions)->toBe(0);

    // Approval: an R4 tool behind an ON flag still only parks for a person; it does not execute.
    WriteTool::$executions = 0;
    $write = app(ToolGateway::class)->execute(ToolRequest::forOperator($this->owner, 'test.external_write', ['note' => 'x']));
    expect($write->status)->toBe(OutcomeStatus::AwaitingApproval)->and(WriteTool::$executions)->toBe(0);

    foreach (['erp.create_draft_bill', 'ads.create_draft', 'media.draft_reel', 'avatar.generate', 'briefing.send_owner_telegram'] as $key) {
        expect(app(ToolRegistry::class)->get($key)->descriptor()->riskTier->value)->toBe('r4', "[{$key}] lost its approval tier");
    }

    // Permission for the real connector tools: a support agent role holds none of them, flag ON.
    $support = $this->hqUser('support_agent');
    $courier = app(ToolGateway::class)->execute(ToolRequest::forOperator($support, 'courier.track_parcel', ['business_slug' => 'laladia', 'courier' => 'pathao', 'consignment' => 'X1']));
    expect($courier->ok())->toBeFalse()->and($courier->class)->not->toBe(OutcomeClass::FeatureDisabled);
});

it('fails closed when the flags cannot be read', function () {
    r2SetFlag(R2_TEST_FLAG, true);
    $flagged = app(FlaggedTools::class);
    expect($flagged->flagOn('test.counting'))->toBeTrue();

    // Hide the table inside a savepoint (Postgres DDL is transactional), read, then roll the savepoint back.
    $read = null;
    try {
        DB::transaction(function () use (&$read) {
            DB::statement('ALTER TABLE feature_flags RENAME TO feature_flags_hidden');
            $read = app(FlaggedTools::class)->flagOn('test.counting');
            throw new RuntimeException('roll back the rename');
        });
    } catch (RuntimeException) {
    }
    expect($read)->toBeFalse()->and(app(FlaggedTools::class)->flagOn('test.counting'))->toBeTrue();
});

it('keeps no flag state in memory: Features and FlaggedTools read the table on each call', function () {
    // Ruling item 7 asks for versioned cache invalidation IF a cached flag exists. None exists; this pins that.
    foreach ([app_path('Support/Features/Features.php'), app_path('Tools/FlaggedTools.php'), app_path('Gateway/Registry/ToolAvailability.php')] as $file) {
        $source = (string) file_get_contents($file);
        expect($source)->not->toContain('Cache::')->not->toContain('cache(')->not->toContain('static $');
    }
    r2SetFlag(R2_TEST_FLAG, true);
    $f = app(FlaggedTools::class);
    expect($f->flagOn('test.counting'))->toBeTrue();
    FeatureFlag::query()->where('key', R2_TEST_FLAG)->update(['enabled' => false]);
    expect($f->flagOn('test.counting'))->toBeFalse();
});
