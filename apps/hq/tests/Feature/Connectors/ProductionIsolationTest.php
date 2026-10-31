<?php

use App\Agents\AgentToolset;
use App\Connectors\ConnectorRegistry;
use App\Gateway\CallerKind;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\Agent;
use App\Models\AutonomyGrant;
use App\Models\FeatureFlag;
use App\Models\IntegrationCredential;
use App\Models\MediaBudget;
use App\Models\ScheduledJob;
use App\Platform\PlatformRegistry;
use App\Tools\FlaggedTools;
use App\Tools\ToolRegistry;
use App\Voice\SpeechProviders;
use App\Voice\SttBenchmarkGate;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Execution\Contexts;

/*
 | Ruling 31 Oct 2026 item 11 (Meta send connectors wait for P3-G4) and pre-freeze section C (provider and production isolation).
 |
 | The production defaults (every migration plus `DatabaseSeeder`, the only seeder a release runs) seed no external credential,
 | switch nothing on, register no Meta send connector or provider, give no agent a callable send tool, and contact no provider while
 | they run. Flags, grants, AI runtime, scheduler state, connector readiness and credentials are each verified explicitly.
 */

beforeEach(function () {
    // Anything that tries to reach the outside during a migration or a seed fails the test.
    Http::preventStrayRequests();
    Http::fake();
    $this->seed(DatabaseSeeder::class);
});

it('seeds no external credential, switches no flag on, grants no autonomy, gives no media budget and files no scheduled job', function () {
    expect(IntegrationCredential::withTrashed()->count())->toBe(0)
        ->and(FeatureFlag::query()->where('enabled', true)->pluck('key')->all())->toBe([])
        ->and(AutonomyGrant::query()->count())->toBe(0)
        ->and(MediaBudget::query()->count())->toBe(0)
        ->and(ScheduledJob::query()->count())->toBe(0);
    // The AI runtime has no row, so it is off; every flag-gated tool is unavailable.
    expect(FeatureFlag::query()->where('key', 'ai.runtime')->where('enabled', true)->exists())->toBeFalse();
    foreach ((array) config('hq.flagged_tools') as $classes) {
        foreach ($classes as $class) {
            expect(app(FlaggedTools::class)->flagOn(app($class)->key()))->toBeFalse();
        }
    }
    Http::assertNothingSent();
});

it('contacts no provider from any migration or the production seeder (no HTTP client, socket or connector in them)', function () {
    $offenders = [];
    foreach ([database_path('migrations'), database_path('seeders/DatabaseSeeder.php'), database_path('seeders/RolesAndPermissionsSeeder.php'), database_path('seeders/DomainSeeder.php')] as $root) {
        $files = is_dir($root) ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) : [new SplFileInfo($root)];
        foreach ($files as $file) {
            // Code only: comments are stripped, so a sentence saying what is NOT used is not an offender.
            $source = implode('', array_map(fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all((string) file_get_contents($file->getPathname()))));
            if (preg_match('/Http::|curl_|fsockopen|stream_socket_client|GuzzleHttp|ConnectorRegistry|PlatformRegistry|->send\(|file_get_contents\(\s*[\'"]https?:/', $source) === 1) {
                $offenders[] = $file->getFilename();
            }
        }
    }
    expect($offenders)->toBe([]);
});

it('registers no Meta send connector: no ConnectorRegistry row, no messaging provider, no sandbox row, no Meta credential', function () {
    $rows = app(ConnectorRegistry::class)->all();
    foreach ($rows as $row) {
        expect($row->capability)->not->toStartWith('messaging.')
            ->and($row->provider)->not->toMatch('/whatsapp|messenger|instagram|meta_page|meta_send/i')
            ->and($row->provider)->not->toBe('sandbox');
    }
    expect(app(PlatformRegistry::class)->platforms())->toBe([])
        ->and(IntegrationCredential::withTrashed()->where('provider', 'like', '%meta%')->orWhere('provider', 'like', '%whatsapp%')->count())->toBe(0)
        ->and(config('hq-connectors.sandbox'))->not->toBeTrue();
});

it('gives no agent a callable send tool: the one proposal-only reply tool (docs/72) cannot execute with no provider registered', function () {
    $this->seed(AgentSeeder::class);
    $owner = $this->hqUser('owner');
    Agent::query()->update(['status' => 'active', 'enabling_user_id' => $owner->id]);
    $registry = app(ToolRegistry::class);

    $outbound = [];
    foreach ($registry->keys() as $key) {
        $d = $registry->get($key)->descriptor();
        if ($d->agentExecutable && ($d->channel->isOutbound() || $d->customerFacing)) {
            $outbound[] = $key;
        }
    }
    // The pre-existing, proposal-only Phase 3 reply tool (docs/72), named rather than the line moved. Nothing new joins it.
    expect($outbound)->toBe(['messaging.send_reply']);
    foreach ((array) config('hq.flagged_tools') as $classes) {
        foreach ($classes as $class) {
            expect(app($class)->key())->not->toStartWith('messaging.');
        }
    }

    // Called by the support agent directly, it does not execute: no messaging provider exists.
    $support = Agent::where('key', 'support')->first() ?? Agent::query()->firstOrFail();
    $outcome = app(ToolGateway::class)->execute(new ToolRequest(
        toolKey: 'messaging.send_reply', args: ['thread_uuid' => (string) Str::uuid(), 'text' => 'test'], caller: CallerKind::Agent,
        identity: ActingIdentity::forExecution(Contexts::for($support)), correlationId: (string) Str::uuid(),
    ));
    expect($outcome->ok())->toBeFalse();
    Http::assertNothingSent();
});

it('leaves voice with no registered speech provider and no speech-to-text default, and the AI runtime inert', function () {
    expect(app(SpeechProviders::class)->isEmpty())->toBeTrue()
        ->and(app(SttBenchmarkGate::class)->passedProvider())->toBeNull()
        ->and(config('hq.voice.default_voice'))->not->toHaveKey('openai_compatible_tts');
    // No provider row of any connector is ready: each needs its flag and its credential, and neither ships.
    foreach (app(ConnectorRegistry::class)->all() as $row) {
        expect($row->enabledBy)->toStartWith('flag:');
        [, $flag] = explode(':', $row->enabledBy, 2);
        expect(FeatureFlag::query()->where('key', $flag)->where('enabled', true)->exists())->toBeFalse();
    }
});
