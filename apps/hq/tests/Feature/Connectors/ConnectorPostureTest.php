<?php

use App\Connectors\Capabilities;
use App\Connectors\ConnectorRegistry;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\OutcomeClass;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\Agent;
use App\Models\Business;
use App\Models\FeatureFlag;
use App\Tools\Connectors\CreateDraftBillTool;
use App\Tools\ToolContext;
use App\Tools\ToolRegistry;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role;

/*
 | docs/144: what the shipped state of the HQ-completion connectors is, and that it stays so. Every switch is OFF, so no connector tool
 | exists; with a switch on, a tool is a person's tool (never an agent's), behind a permission that exists, with the risk it should have.
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->owner = $this->hqUser('owner');
});

function m3FlaggedTools(): array
{
    // R2: `hq.flagged_tools` now also holds docs/143's Telegram brief and docs/145-b's avatar tool (ruling 31 Oct item 7); this
    // file is about docs/144's connector tools, so it reads the `connectors.*` flags only.
    return array_filter((array) config('hq.flagged_tools'), fn (string $flag) => str_starts_with($flag, 'connectors.'), ARRAY_FILTER_USE_KEY);
}

it('ships every connector switch seeded and OFF', function () {
    foreach ([...array_keys(m3FlaggedTools()), 'connectors.media_upload'] as $flag) {
        $row = FeatureFlag::where('key', $flag)->where('environment', '*')->first();
        expect($row)->not->toBeNull("{$flag} is not seeded")->and($row->enabled)->toBeFalse();
    }
});

it('registers every connector tool descriptor, and with the switches off the gateway refuses each as feature_disabled, not unknown', function () {
    // R2 pin edit (ruling 31 Oct item 7): always-registered descriptors, flag-gated availability.
    $registered = app(ToolRegistry::class)->keys();

    foreach (m3FlaggedTools() as $classes) {
        foreach ($classes as $class) {
            $key = app($class)->key();
            expect($registered)->toContain($key);
            $outcome = app(ToolGateway::class)->execute(ToolRequest::forOperator($this->owner, $key, []));
            expect($outcome->ok())->toBeFalse()->and($outcome->class)->toBe(OutcomeClass::FeatureDisabled)
                ->and((string) $outcome->error)->not->toContain('Unknown tool');
        }
    }
});

it('gives a connector tool to no agent, ever', function () {
    $this->seed(AgentSeeder::class);
    $keys = collect(m3FlaggedTools())->flatten()->map(fn ($c) => app($c)->key())->all();

    foreach (Agent::all() as $agent) {
        expect(array_intersect($agent->allowed_tools ?? [], $keys))->toBe([]);
    }
    foreach (m3FlaggedTools() as $classes) {
        foreach ($classes as $class) {
            expect(app($class)->descriptor()->agentExecutable)->toBeFalse();
        }
    }
});

it('declares each tool with a permission that exists, an audit action, the risk it deserves, and a flag that gates it', function () {
    $permissions = RolesAndPermissionsSeeder::PERMISSIONS;
    $r4 = ['erp.create_draft_bill', 'ads.create_draft', 'media.draft_reel'];

    foreach (m3FlaggedTools() as $flag => $classes) {
        foreach ($classes as $class) {
            $tool = app($class);
            $d = $tool->descriptor();
            expect($permissions)->toContain($d->permission)->and($d->auditAction)->toStartWith('tool.')
                ->and($d->customerFacing)->toBeFalse()->and($d->permanentlyHumanOnly)->toBeFalse();
            // A tool that writes outside HQ is R4 and waits for approval; one that only reads at a provider is R2/R3.
            in_array($tool->key(), $r4, true)
                ? expect($d->riskTier)->toBe(RiskTier::R4)->and($d->readWrite)->toBe(ReadWrite::Write)
                : expect($d->riskTier->writesOutsideHq())->toBeFalse();
            // A tool whose switch is off refuses even when called directly.
            FeatureFlag::where('key', $flag)->update(['enabled' => false]);
            $business = Business::first();
            $direct = $tool->execute(['business_slug' => $business->slug], new ToolContext('k', 'c', $business->id, actorUserId: $this->owner->id));
            expect($direct->ok())->toBeFalse();
        }
    }
    expect(app(CreateDraftBillTool::class)->descriptor()->requesterMayDecide)->toBeFalse();
});

it('holds the new permissions for owner and operator only', function () {
    $new = ['hq.connectors.view', 'hq.tools.courier.read', 'hq.voice.use', 'hq.tools.media.draft', 'hq.tools.mail.ingest', 'hq.ingestion.review', 'hq.tools.erp.draft_bill', 'hq.tools.ads.draft', 'hq.tools.meta.health'];

    foreach (['brand_manager', 'support_agent', 'finance_viewer'] as $role) {
        foreach ($new as $permission) {
            expect(Role::findByName($role)->hasPermissionTo($permission))->toBeFalse("{$role} holds {$permission}");
        }
    }
    foreach (['owner', 'operator'] as $role) {
        foreach ($new as $permission) {
            expect(Role::findByName($role)->hasPermissionTo($permission))->toBeTrue();
        }
    }
});

it('ships the courier webhook open to the couriers but throttled, and every connector row implementing its capability\'s contract', function () {
    $route = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === 'api/webhooks/courier/{provider}/{business}');
    expect($route->methods())->toBe(['POST'])->and($route->gatherMiddleware())->toContain('throttle:300,1')->not->toContain('auth');

    foreach (app(ConnectorRegistry::class)->all() as $row) {
        expect(is_a($row->adapter, Capabilities::contract($row->capability), true))->toBeTrue();
        expect($row->enabledBy)->toStartWith('flag:connectors.');
    }
});

it('holds no secret, token or real contact detail in the files this stream added', function () {
    $roots = [app_path('Courier'), app_path('Ingestion'), app_path('Erp/Write'), app_path('Ads/Draft'), app_path('Media/Pipeline'), app_path('Media/Upload'), app_path('Tools/Connectors'), app_path('Http/Controllers/Connectors'), base_path('tests/Feature/Courier'), base_path('tests/Feature/Ingestion')];
    $offenders = [];
    foreach ($roots as $root) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            $source = (string) file_get_contents($file->getPathname());
            // A live-looking key (OpenAI, Meta, Google, AWS, Slack, a JWT), or a Bangladesh mobile number.
            if (preg_match('/sk-[A-Za-z0-9]{32,}|EAA[A-Za-z0-9]{40,}|AIza[0-9A-Za-z_\-]{30,}|AKIA[0-9A-Z]{16}|xox[bp]-[0-9A-Za-z-]{20,}|eyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}|\b01[3-9]\d{8}\b/', $source, $m)) {
                $offenders[] = $file->getFilename().': '.$m[0];
            }
        }
    }
    expect($offenders)->toBe([]);
});
