<?php

use App\Ads\AdsCredential;
use App\Ads\AdsHumanOnly;
use App\Ads\AdsReader;
use App\Ads\Meta\MetaAdsPlatform;
use App\Ads\Meta\MetaAdsReader;
use App\Gateway\Descriptor\MonetaryImpact;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\Agent;
use App\Models\IntegrationCredential;
use App\Models\PlatformAccount;
use App\Models\ScheduledJob;
use App\Models\User;
use App\Platform\Contracts\AdsPlatform;
use App\Platform\Contracts\AdsReadPlatform;
use App\Platform\PlatformRegistry;
use App\Scheduling\ScheduledJobService;
use App\Tools\Ads\ReadAdAccountTool;
use App\Tools\Ads\ReadAdCampaignsTool;
use App\Tools\Ads\ReadAdInsightsTool;
use App\Tools\Ads\SyncAdSpendTool;
use App\Tools\Tool;
use App\Tools\ToolRegistry;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;

/*
 | Stream S (docs/88 A points 1 and 4): the Phase 5 read side is built and INERT. Four independent
 | locks, each held here, so removing one is a red test and never a quiet activation:
 |   1. no ads tool is registered (config/hq.php), so the gateway refuses every ads call as unknown;
 |   2. no agent holds any ads tool;
 |   3. no ads credential exists;
 |   4. the Meta provider is not in PlatformRegistry, so the router refuses even a registered,
 |      credentialed call.
 | And: nothing is scheduled, no write tool exists at all, and nothing ADR-050 keeps human has a tool.
 */

const ADS_TOOL_CLASSES = [ReadAdAccountTool::class, ReadAdCampaignsTool::class, ReadAdInsightsTool::class, SyncAdSpendTool::class];

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->owner = User::factory()->create();
    $this->owner->assignRole('owner');
    $this->owner = $this->owner->fresh();
});

it('registers no ads tool: none is in config/hq.php and the gateway refuses one as unknown, sending nothing', function () {
    Http::fake();

    $classes = config('hq.tools');
    foreach (ADS_TOOL_CLASSES as $class) {
        expect($classes)->not->toContain($class);
    }
    expect(array_filter(app(ToolRegistry::class)->keys(), fn ($k) => str_starts_with($k, 'ads.')))->toBe([]);

    $outcome = app(ToolGateway::class)->execute(ToolRequest::forOperator($this->owner, 'ads.read_account', ['business_slug' => 'laladia']));
    expect($outcome->ok())->toBeFalse()->and((string) $outcome->error)->toContain('Unknown tool');
    Http::assertNothingSent();
});

it('gives no agent any ads tool', function () {
    $this->seed(AgentSeeder::class);

    foreach (Agent::all() as $agent) {
        expect(array_filter($agent->allowed_tools ?? [], fn ($t) => str_starts_with((string) $t, 'ads.')))->toBe([]);
    }
});

it('holds no ads credential and registers no ads provider', function () {
    expect(IntegrationCredential::where('provider', AdsCredential::PROVIDER)->count())->toBe(0)
        ->and(app(PlatformRegistry::class)->has(MetaAdsPlatform::PLATFORM))->toBeFalse()
        ->and(app(PlatformRegistry::class)->platforms())->toBe([]);
});

it('refuses at the router even when the tool is registered and a credential stored, because no provider is registered', function () {
    Http::fake();
    app(ToolRegistry::class)->register(ReadAdAccountTool::class);
    IntegrationCredential::create(['provider' => AdsCredential::PROVIDER, 'account_ref' => 'laladia', 'credential_type' => 'token',
        'payload' => ['value' => 'SYSTEM-USER-TOKEN'], 'status' => 'active']);
    PlatformAccount::where('platform', MetaAdsPlatform::PLATFORM)->get()->each->update(['capabilities' => ['read_insights', 'read_campaigns']]);

    $outcome = app(ToolGateway::class)->execute(ToolRequest::forOperator($this->owner, 'ads.read_account', ['business_slug' => 'laladia']));

    expect($outcome->ok())->toBeFalse()->and((string) $outcome->error)->toContain('HQ has no provider implementation for [meta_ad_account]');
    Http::assertNothingSent();
});

it('schedules nothing: no Laravel schedule entry and no scheduled job can name the sync, which cannot even be filed', function () {
    $commands = collect(app(Schedule::class)->events())->map(fn ($e) => (string) $e->command)->implode("\n");
    expect($commands)->not->toContain('ads')->not->toContain('meta');

    expect(ScheduledJob::where('tool_key', 'like', 'ads.%')->count())->toBe(0);
    expect(fn () => app(ScheduledJobService::class)->create([
        'name' => 'hourly ad spend', 'tool_key' => 'ads.sync_ad_spend', 'kind' => 'interval', 'interval_seconds' => 3600,
        'payload' => ['business_slug' => 'laladia'],
    ], $this->owner))->toThrow(InvalidArgumentException::class, 'not registered');
});

it('has no write tool at the platform: every ads tool reads it, the sync writes only HQ facts, none moves money', function () {
    foreach (ADS_TOOL_CLASSES as $class) {
        $d = app($class)->descriptor();
        expect($d->riskTier->value)->toBeIn([RiskTier::R2->value, RiskTier::R3->value])
            ->and($d->monetaryImpact)->toBe(MonetaryImpact::None)
            ->and($d->customerFacing)->toBeFalse()
            ->and($d->permanentlyHumanOnly)->toBeFalse()
            ->and($d->permission)->toBe('hq.tools.ads.read');
        if ($class === SyncAdSpendTool::class) {
            expect($d->riskTier)->toBe(RiskTier::R3)->and($d->readWrite)->toBe(ReadWrite::Write)->and($d->agentExecutable)->toBeFalse();
        } else {
            expect($d->riskTier)->toBe(RiskTier::R2)->and($d->readWrite)->toBe(ReadWrite::Read);
        }
    }

    // The provider's ceiling is the two reads: even registered, it could never be routed a write.
    expect(app(MetaAdsPlatform::class)->ceiling()->granted())->toBe([AdsPlatform::READ_INSIGHTS, AdsPlatform::READ_CAMPAIGNS]);

    // No class anywhere under app/Tools/Ads beyond the four known ones.
    $files = collect(glob(app_path('Tools/Ads/*.php')))->map(fn ($f) => basename($f, '.php'))->sort()->values()->all();
    expect($files)->toBe(['AdsTool', 'ReadAdAccountTool', 'ReadAdCampaignsTool', 'ReadAdInsightsTool', 'SyncAdSpendTool']);
});

it('has no tool at all for anything ADR-050 keeps human: money, legal identity, people, ownership, the ceiling, deletion', function () {
    // Every Tool class in the application, registered or not, by its key.
    $keys = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Tools')));
    foreach ($it as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $class = 'App\\Tools\\'.str_replace(['/', '\\'], '\\', substr($file->getPathname(), strlen(app_path('Tools')) + 1, -4));
        if (! class_exists($class)) {
            continue;
        }
        $ref = new ReflectionClass($class);
        if ($ref->isAbstract() || ! $ref->implementsInterface(Tool::class)) {
            continue;
        }
        $keys[] = $ref->newInstanceWithoutConstructor()->key();
    }
    expect(count($keys))->toBeGreaterThan(20);

    foreach (AdsHumanOnly::OPERATIONS as $operation => $rule) {
        foreach ($keys as $key) {
            foreach ($rule['terms'] as $term) {
                expect(str_contains(strtolower($key), str_replace(' ', '_', strtolower($term))))
                    ->toBeFalse("tool [{$key}] looks like the human-only operation [{$operation}]: {$rule['why']}");
            }
        }
    }

    // And the ads tools' own words never offer one of them as an action.
    foreach (ADS_TOOL_CLASSES as $class) {
        $purpose = strtolower(app($class)->descriptor()->purpose);
        foreach (['payment method', 'billing', 'advertiser', 'payer', 'delete', 'add funds', 'transfer'] as $word) {
            expect($purpose)->not->toContain($word);
        }
    }
});

it('has no write method anywhere in the read contract: the reader and the read platform can only read', function () {
    // The exact public surface, pinned. A write on an ad platform is a separate approval-gated tool
    // (ADR-050), never a method a reader happens to grow; adding one here is a red test on purpose.
    $methods = fn (string $interface) => collect((new ReflectionClass($interface))->getMethods())
        ->map(fn (ReflectionMethod $m) => $m->getName())->sort()->values()->all();

    expect($methods(AdsReader::class))->toBe(['account', 'adSets', 'ads', 'apiVersion', 'campaigns', 'insights'])
        ->and($methods(AdsReadPlatform::class))->toBe(['ceiling', 'platform', 'reader', 'requiresApproval']);

    // And no name in the read chain even sounds like a change at the platform.
    foreach ([AdsReader::class, AdsReadPlatform::class, MetaAdsReader::class, MetaAdsPlatform::class] as $class) {
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            foreach (['create', 'update', 'delete', 'pause', 'resume', 'activate', 'publish', 'set', 'change', 'post', 'write', 'upload', 'spend', 'fund'] as $verb) {
                expect(str_starts_with(strtolower($m->getName()), $verb))->toBeFalse("{$class}::{$m->getName()} reads like a write at the platform");
            }
        }
    }
});
