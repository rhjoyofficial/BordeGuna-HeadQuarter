<?php

use App\Agents\AgentResponseSchema;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Ai\ModelRuntime;
use App\Ai\ModelTier;
use App\Ai\Providers\AnthropicProvider;
use App\Ai\Providers\OpenAiProvider;
use App\Ai\ProviderSelection;
use App\Ai\ServingProvider;
use App\Ai\TaskType;
use App\Models\Agent;
use App\Models\AiModel;
use App\Models\AuditLog;
use App\Models\IntegrationCredential;
use App\Models\ShadowPeriod;
use App\Models\User;
use Database\Seeders\ModelTierSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\Support\Ai\FixtureProvider;

/*
 | One provider serves a measurement (docs/81 C.7, the owner's decision of 21 September).
 |
 | ⚠ The router falls back to a DIFFERENT provider first on any outage (docs/48 J.2), so two
 | providers with active rows would share a gate's calls, and a shadow week could be about two
 | models without anybody deciding it. What decides which provider serves is therefore which rows
 | are active, and the only thing that moves them is a person, by name, through a lever that shows
 | what it will do before it does it. Seeding the new provider's rows switches nothing on.
 */

function selStore(string $provider): IntegrationCredential
{
    return IntegrationCredential::create([
        'provider' => $provider, 'account_ref' => 'hq', 'credential_type' => 'api_key',
        'payload' => ['value' => 'sk-never-shown'], 'status' => 'active',
    ]);
}

function selActive(): array
{
    return AiModel::query()->where('is_active', true)->orderBy('provider')->orderBy('tier')->orderBy('priority')
        ->get()->map(fn (AiModel $m) => "{$m->provider}:{$m->tier}:{$m->model_id}")->all();
}

function selPerson(array $attributes = []): User
{
    $user = User::factory()->create($attributes + ['email' => 'joy@example.test']);
    // docs/138 ruling 13: selecting the provider is AI activation for every business, so the person
    // holds the `ai.runtime` switch's permission and has no business restriction.
    $user->givePermissionTo(Permission::findOrCreate(ProviderSelection::PERMISSION, 'web'));

    return $user;
}

it('names the provider that serves: one, none, or more than one', function () {
    expect(app(ServingProvider::class)->current())->toBeNull()
        ->and(app(ServingProvider::class)->state())->toBe(ServingProvider::NONE);

    aiModel('anthropic', 'a-low', ModelTier::Low);
    expect(app(ServingProvider::class)->current())->toBe('anthropic')
        ->and(app(ServingProvider::class)->state())->toBe(ServingProvider::SINGLE);

    // An inactive row does not serve; neither does a row at the specialised tier.
    aiModel('openai', 'o-low', ModelTier::Low)->update(['is_active' => false]);
    aiModel('openai', 'o-embed', ModelTier::Specialised);
    expect(app(ServingProvider::class)->current())->toBe('anthropic');

    aiModel('openai', 'o-medium', ModelTier::Medium);
    expect(app(ServingProvider::class)->current())->toBeNull()
        ->and(app(ServingProvider::class)->state())->toBe(ServingProvider::MIXED)
        ->and(app(ServingProvider::class)->active())->toBe(['anthropic', 'openai']);
});

it('seeds the OpenAI rows from the pricing page, cache writes included, and switches none of them on', function () {
    (new ModelTierSeeder)->run();

    $rows = AiModel::query()->where('provider', OpenAiProvider::PROVIDER)->orderBy('tier')->orderBy('priority')->get()
        ->map(fn (AiModel $m) => [$m->tier, $m->model_id, $m->priority, $m->input_price_per_mtok, $m->cache_read_price_per_mtok, $m->cache_write_price_per_mtok, $m->output_price_per_mtok, $m->is_active])
        ->all();

    expect($rows)->toBe([
        ['high', 'gpt-5.6-sol', 10, '4.0000', '0.4000', '5.0000', '20.0000', false],
        ['low', 'gpt-5.6-luna', 10, '0.2000', '0.0200', '0.2500', '1.2000', false],
        ['medium', 'gpt-5.6-terra', 10, '2.0000', '0.2000', '2.5000', '12.0000', false],
        ['medium', 'gpt-5.6-sol', 90, '4.0000', '0.4000', '5.0000', '20.0000', false],
    ]);

    foreach (AiModel::query()->where('provider', OpenAiProvider::PROVIDER)->get() as $row) {
        expect($row->pricing_verified_on->toDateString())->toBe('2026-09-21')
            // The short-context price band: above 272K OpenAI bills the whole request at 2x input,
            // which CostCalculator does not model, so HQ refuses a bigger prompt instead.
            ->and($row->context_window)->toBe(272_000)
            ->and($row->max_output_tokens)->toBe(128_000)
            ->and($row->capabilities)->toContain('json_schema');
    }

    expect(AiModel::query()->where('provider', OpenAiProvider::PROVIDER)->where('model_id', 'gpt-5.6-sol')->where('tier', 'high')->value('notes'))
        ->toContain('Promotional price')->toContain('November 21, 2026');

    // A fresh install serves exactly what it served before: the Anthropic rows, alone.
    expect(app(ServingProvider::class)->current())->toBe(AnthropicProvider::PROVIDER)
        ->and(AiModel::query()->where('provider', AnthropicProvider::PROVIDER)->where('is_active', false)->count())->toBe(0);
});

it('creates a new row active only for the provider that already serves, and never re-activates a row', function () {
    (new ModelTierSeeder)->run();
    selStore(OpenAiProvider::CREDENTIAL_PROVIDER);
    $by = selPerson();
    $this->artisan('hq:ai:provider', ['provider' => 'openai', '--apply' => true, '--by' => $by->email, '--reason' => 'Owner decision 21 Sep'])->assertSuccessful();

    // A row missing on each side, as a later seeder version adding a model would find it.
    AiModel::query()->where('provider', 'openai')->where('model_id', 'gpt-5.6-luna')->delete();
    AiModel::query()->where('provider', 'anthropic')->where('tier', 'low')->delete();

    (new ModelTierSeeder)->run();

    expect(AiModel::query()->where('provider', 'openai')->where('model_id', 'gpt-5.6-luna')->value('is_active'))->toBeTrue()
        ->and(AiModel::query()->where('provider', 'anthropic')->where('tier', 'low')->value('is_active'))->toBeFalse()
        ->and(AiModel::query()->where('provider', 'anthropic')->where('is_active', true)->count())->toBe(0)
        ->and(app(ServingProvider::class)->current())->toBe('openai');
});

it('shows the state and a dry run, and changes nothing without --apply', function () {
    (new ModelTierSeeder)->run();
    selStore(OpenAiProvider::CREDENTIAL_PROVIDER);
    $before = selActive();

    $this->artisan('hq:ai:provider')
        ->expectsOutputToContain('serving: anthropic')
        ->assertSuccessful();

    $this->artisan('hq:ai:provider', ['provider' => 'openai'])
        ->expectsOutputToContain('Dry run')
        ->expectsOutputToContain('on   openai medium gpt-5.6-terra')
        ->expectsOutputToContain('off  anthropic medium claude-sonnet-5')
        ->assertSuccessful();

    expect(selActive())->toBe($before)
        ->and(AuditLog::where('action', 'ai.provider_selected')->count())->toBe(0);
});

it('refuses, changing nothing, without a named enabled person, a reason, a driver, a key, every tier, or while a shadow is open', function (Closure $arrange, array $options, string $says) {
    (new ModelTierSeeder)->run();
    selStore(OpenAiProvider::CREDENTIAL_PROVIDER);
    selPerson();
    selPerson(['email' => 'off@example.test', 'is_active' => false]);
    $arrange();
    $before = selActive();

    $this->artisan('hq:ai:provider', $options + ['provider' => 'openai', '--apply' => true])
        ->expectsOutputToContain($says)
        ->assertFailed();

    expect(selActive())->toBe($before)
        ->and(AuditLog::where('action', 'ai.provider_selected')->count())->toBe(0);
})->with([
    'no --by' => [fn () => null, ['--reason' => 'r'], 'named person'],
    'unknown --by' => [fn () => null, ['--by' => 'nobody@example.test', '--reason' => 'r'], 'no HQ user'],
    'disabled --by' => [fn () => null, ['--by' => 'off@example.test', '--reason' => 'r'], '[off@example.test] is disabled'],
    'no reason' => [fn () => null, ['--by' => 'joy@example.test', '--reason' => '  '], 'for a stated reason'],
    'no key' => [fn () => IntegrationCredential::where('provider', OpenAiProvider::CREDENTIAL_PROVIDER)->update(['status' => 'revoked']), ['--by' => 'joy@example.test', '--reason' => 'r'], 'openai has no credential stored'],
    'a tier missing' => [fn () => AiModel::where('provider', 'openai')->where('tier', 'high')->delete(), ['--by' => 'joy@example.test', '--reason' => 'r'], 'no row at the high tier'],
    'a shadow open' => [function () {
        $agent = Agent::create(['key' => 'executive', 'name' => 'Executive']);
        ShadowPeriod::create([
            'uuid' => (string) Str::uuid(), 'agent_id' => $agent->id, 'agent_key' => 'executive', 'autonomy_level' => 'a0',
            'status' => ShadowPeriod::STATUS_OPEN, 'opened_at' => now(), 'planned_end_at' => now()->addDays(7),
            'opened_by_label' => 'test', 'reason' => 'test', 'evidence' => [], 'allowed_tools' => [], 'questions' => [], 'probes_required' => false,
        ]);
    }, ['--by' => 'joy@example.test', '--reason' => 'r'], 'shadow period is open'],
]);

it('refuses a provider HQ has no driver for', function () {
    selPerson();

    $this->artisan('hq:ai:provider', ['provider' => 'mistral', '--apply' => true, '--by' => 'joy@example.test', '--reason' => 'r'])
        ->expectsOutputToContain('no registered driver')
        ->assertFailed();
});

it('moves every chat tier to the chosen provider in one audited step under the named person', function () {
    (new ModelTierSeeder)->run();
    selStore(OpenAiProvider::CREDENTIAL_PROVIDER);
    $by = selPerson();

    $this->artisan('hq:ai:provider', ['provider' => 'openai', '--apply' => true, '--by' => $by->email, '--reason' => 'Owner decision 21 Sep: OpenAI while Anthropic credit is blocked'])
        ->expectsOutputToContain('openai now serves')
        ->assertSuccessful();

    expect(selActive())->toBe([
        'openai:high:gpt-5.6-sol',
        'openai:low:gpt-5.6-luna',
        'openai:medium:gpt-5.6-terra',
        'openai:medium:gpt-5.6-sol',
    ])->and(app(ServingProvider::class)->current())->toBe('openai');

    $audit = AuditLog::where('action', 'ai.provider_selected')->sole();
    expect($audit->actor_type)->toBe('human')
        ->and((int) $audit->actor_id)->toBe($by->id)
        ->and($audit->reason)->toContain('Owner decision')
        ->and($audit->before['serving'])->toBe('anthropic')
        ->and($audit->after['serving'])->toBe('openai')
        ->and($audit->after['active'])->toHaveCount(4)
        ->and(json_encode($audit->toArray()))->not->toContain('sk-never-shown');

    // Asking again for the provider that already serves alone changes and audits nothing.
    $this->artisan('hq:ai:provider', ['provider' => 'openai', '--apply' => true, '--by' => $by->email, '--reason' => 'again'])
        ->expectsOutputToContain('already the only provider')
        ->assertSuccessful();
    expect(AuditLog::where('action', 'ai.provider_selected')->count())->toBe(1);

    // And the way back is the same lever.
    selStore(AnthropicProvider::CREDENTIAL_PROVIDER);
    $this->artisan('hq:ai:provider', ['provider' => 'anthropic', '--apply' => true, '--by' => $by->email, '--reason' => 'Anthropic credit restored'])->assertSuccessful();
    expect(app(ServingProvider::class)->current())->toBe('anthropic')
        ->and(AiModel::query()->where('provider', 'openai')->where('is_active', true)->count())->toBe(0);
});

it('never falls back to the other provider once one serves', function () {
    (new ModelTierSeeder)->run();
    selStore(OpenAiProvider::CREDENTIAL_PROVIDER);
    selStore(AnthropicProvider::CREDENTIAL_PROVIDER);
    $this->artisan('hq:ai:provider', ['provider' => 'openai', '--apply' => true, '--by' => selPerson()->email, '--reason' => 'r'])->assertSuccessful();
    enableAiRuntime();

    Http::fake([
        'api.openai.com/*' => Http::response(['error' => ['type' => 'server_error', 'message' => 'down']], 503),
        'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'should never be asked']], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1], 'stop_reason' => 'end_turn']),
    ]);

    expect(fn () => app(ModelRuntime::class)->run(TaskType::OperatorQuestion, 'sys', [['role' => 'user', 'content' => 'hi']]))
        ->toThrow(ProviderUnavailable::class);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.anthropic.com'));
    // Terra first, then the medium-tier fallback of the SAME provider, and nothing else.
    expect(Http::recorded()->map(fn ($pair) => $pair[0]->data()['model'])->all())->toBe(['gpt-5.6-terra', 'gpt-5.6-sol']);
});

it('prints the serving provider and its reasoning effort in the dry probe, which is what the gate log carries', function () {
    (new ModelTierSeeder)->run();
    selStore(OpenAiProvider::CREDENTIAL_PROVIDER);
    $this->artisan('hq:ai:provider', ['provider' => 'openai', '--apply' => true, '--by' => selPerson()->email, '--reason' => 'r'])->assertSuccessful();
    Http::fake();

    $this->artisan('hq:ai:probe')
        ->expectsOutputToContain('serving provider: openai')
        ->expectsOutputToContain('reasoning effort: low')
        ->expectsOutputToContain('Dry run: nothing was sent')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('says so in the dry probe when more than one provider would serve', function () {
    aiModel('anthropic', 'a-low', ModelTier::Low);
    aiModel('openai', 'o-low', ModelTier::Low);

    $this->artisan('hq:ai:probe')->expectsOutputToContain('MORE THAN ONE provider serves')->run();
});

it('proves the structured path with --schema: the agent schema itself, with room to answer', function () {
    FixtureProvider::reset();
    aiProviders();
    aiModel('fixture', 'f-low', ModelTier::Low);
    enableAiRuntime();
    FixtureProvider::script('fixture', [FixtureProvider::reply((string) json_encode([
        'status' => 'answer', 'task' => 'probe', 'authority_request' => 'none', 'answer' => 'HQ model router is working.',
        'refusal_reason' => '', 'facts' => [], 'inferences' => [], 'tool_calls' => [],
    ]))]);

    $this->artisan('hq:ai:probe', ['--live' => true, '--schema' => true])
        ->expectsOutputToContain('structured reply valid')
        ->assertSuccessful();

    $request = FixtureProvider::requestsTo('fixture')[0];
    expect($request->jsonSchema)->toBe(AgentResponseSchema::schema())
        ->and($request->maxOutputTokens)->toBe(512);
});

it('refuses --schema without --live, because it is only worth anything as a real call', function () {
    $this->artisan('hq:ai:probe', ['--schema' => true])->expectsOutputToContain('--schema needs --live')->assertFailed();
});
