<?php

use App\Ads\AdsCredential;
use App\Ads\Meta\MetaAdsPlatform;
use App\Gateway\CallerKind;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\ToolGateway;
use App\Gateway\ToolOutcome;
use App\Gateway\ToolRequest;
use App\Models\Agent;
use App\Models\Brand;
use App\Models\ConnectorCall;
use App\Models\FactsAdAccount;
use App\Models\FactsAdSpend;
use App\Models\IntegrationCredential;
use App\Models\PlatformAccount;
use App\Models\ToolCall;
use App\Models\User;
use App\Platform\PlatformRegistry;
use App\Tools\Ads\ReadAdAccountTool;
use App\Tools\Ads\ReadAdCampaignsTool;
use App\Tools\Ads\ReadAdInsightsTool;
use App\Tools\Ads\SyncAdSpendTool;
use App\Tools\ToolRegistry;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Execution\Contexts;

/*
 | The Phase 5 read tools through the ordinary gateway (stream S, docs/88 A point 4).
 |
 | ⚠ Every lock that keeps them inert on production is OPENED HERE, inside the test only: the tools are
 | registered in the test's registry, the Meta provider in the test's PlatformRegistry, a credential and
 | the account's capability grants are written to the test database. `AdsInertPostureTest` holds that
 | none of that is true of the application. What this file proves is that the day they are opened
 | (gate P5-G1), the path is right: scoped, credentialed through the vault, routed to one account, GET
 | only, recorded, with provenance, and writing only HQ's own facts.
 */

const ADS_ACT_PATH = '/v26.0/act_530866839523958';

function openAdsLocksForTest(): void
{
    foreach ([ReadAdAccountTool::class, ReadAdCampaignsTool::class, ReadAdInsightsTool::class, SyncAdSpendTool::class] as $class) {
        app(ToolRegistry::class)->register($class);
    }
    app(PlatformRegistry::class)->register(app(MetaAdsPlatform::class));
    IntegrationCredential::create(['provider' => AdsCredential::PROVIDER, 'account_ref' => 'laladia', 'credential_type' => 'token',
        'payload' => ['value' => 'SYSTEM-USER-TOKEN'], 'status' => 'active']);
    PlatformAccount::where('platform', MetaAdsPlatform::PLATFORM)->get()->each->update(['capabilities' => ['read_insights', 'read_campaigns']]);
}

function adsTool(User $user, string $tool, array $args): ToolOutcome
{
    return app(ToolGateway::class)->execute(ToolRequest::forOperator($user, $tool, $args));
}

function fakeMetaAds(): void
{
    Http::fake(function (Request $request) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
        $name = match (true) {
            $path === ADS_ACT_PATH => 'account-bdt',
            $path === ADS_ACT_PATH.'/campaigns' => isset($q['after']) ? 'campaigns-page2' : 'campaigns-page1',
            $path === ADS_ACT_PATH.'/adsets' => 'adsets',
            $path === ADS_ACT_PATH.'/ads' => 'ads',
            $path === ADS_ACT_PATH.'/insights' => 'insights-'.($q['level'] ?? 'campaign'),
            default => null,
        };

        return $name === null
            ? Http::response(['error' => ['message' => "unfaked {$path}", 'code' => 100]], 400)
            : Http::response(json_decode((string) file_get_contents(base_path("tests/fixtures/meta-ads/{$name}.json")), true));
    });
}

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->owner = User::factory()->create();
    $this->owner->assignRole('owner');
    $this->owner = $this->owner->fresh();
    openAdsLocksForTest();
});

it('reads the ad account through the gateway, token from the vault, provenance on the answer, the call recorded', function () {
    fakeMetaAds();

    $outcome = adsTool($this->owner, 'ads.read_account', ['business_slug' => 'laladia']);

    expect($outcome->ok())->toBeTrue()
        ->and($outcome->output)->toMatchArray(['business' => 'laladia', 'brand' => 'laladia', 'account_ref' => '530866839523958',
            'platform' => 'meta_ad_account', 'provenance' => 'third_party', 'source' => 'meta_ads', 'api_version' => 'v26.0'])
        ->and($outcome->output['account'])->toMatchArray(['currency' => 'BDT', 'spend_cap' => '50000.00', 'remaining_under_cap' => '37654.50']);

    $call = ToolCall::findOrFail($outcome->toolCallId);
    expect($call->tool_key)->toBe('ads.read_account')->and($call->status)->toBe('succeeded');
    $connector = ConnectorCall::where('correlation_id', $call->correlation_id)->sole();
    expect($connector->connector)->toBe('meta_ads:laladia')->and($connector->operation)->toBe('account')->and($connector->response_status)->toBe(200);
    expect(json_encode([$call->toArray(), $connector->toArray()]))->not->toContain('SYSTEM-USER-TOKEN');
    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->hasHeader('Authorization', 'Bearer SYSTEM-USER-TOKEN'));
});

it('reads campaigns and insights with totals summed exactly in the account currency', function () {
    fakeMetaAds();

    $campaigns = adsTool($this->owner, 'ads.read_campaigns', ['business_slug' => 'laladia']);
    expect($campaigns->ok())->toBeTrue()->and($campaigns->output['count'])->toBe(3)->and($campaigns->output['currency'])->toBe('BDT');

    $insights = adsTool($this->owner, 'ads.read_insights', ['business_slug' => 'laladia', 'since' => '2026-09-20', 'until' => '2026-09-21']);
    expect($insights->ok())->toBeTrue()
        ->and($insights->output['totals'])->toBe(['currency' => 'BDT', 'spend' => '999.45', 'impressions' => 23322, 'clicks' => 649, 'rows' => 3]);

    $long = adsTool($this->owner, 'ads.read_insights', ['business_slug' => 'laladia', 'since' => '2026-01-01', 'until' => '2026-09-21']);
    expect($long->ok())->toBeFalse()->and((string) $long->error)->toContain('90 days');
});

it('syncs into the read models with provenance, idempotently, and writes nothing at the platform', function () {
    fakeMetaAds();

    $first = adsTool($this->owner, 'ads.sync_ad_spend', ['business_slug' => 'laladia', 'since' => '2026-09-20', 'until' => '2026-09-21']);
    expect($first->ok())->toBeTrue()->and($first->output['levels'])->toBe(['campaign' => 3, 'adset' => 1, 'ad' => 1]);

    $account = FactsAdAccount::sole();
    $brand = Brand::where('code', 'LLD')->sole();
    expect($account->only(['business_id', 'brand_id', 'currency', 'spend_cap_minor', 'amount_spent_minor', 'provenance', 'source', 'api_version']))
        ->toBe(['business_id' => $brand->business_id, 'brand_id' => $brand->id, 'currency' => 'BDT', 'spend_cap_minor' => 5000000,
            'amount_spent_minor' => 1234550, 'provenance' => 'third_party', 'source' => 'meta_ads', 'api_version' => 'v26.0']);

    $row = FactsAdSpend::where('level', 'campaign')->where('object_ref', '120210000000000001')->where('spend_date', '2026-09-20')->sole();
    expect($row->spend)->toBe('412.35')->and($row->purchases)->toBe(4)->and($row->purchase_value)->toBe('3960.00')
        ->and($row->provenance)->toBe('third_party')->and($row->store_id)->toBeNull()
        ->and($row->sync_ref)->toBe(ToolCall::findOrFail($first->toolCallId)->correlation_id);
    expect(FactsAdSpend::count())->toBe(5);

    // Again: the same rows, never duplicates.
    $second = adsTool($this->owner, 'ads.sync_ad_spend', ['business_slug' => 'laladia', 'since' => '2026-09-20', 'until' => '2026-09-21']);
    expect($second->ok())->toBeTrue()->and(FactsAdSpend::count())->toBe(5)->and(FactsAdAccount::count())->toBe(1);

    Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
});

it('refuses two ad accounts that could both serve a business instead of choosing one', function () {
    Http::fake();
    PlatformAccount::create(['brand_id' => Brand::where('code', 'LLD')->value('id'), 'platform' => MetaAdsPlatform::PLATFORM,
        'account_ref' => '1535878336983845', 'name' => 'second account', 'capabilities' => ['read_insights', 'read_campaigns']]);

    $outcome = adsTool($this->owner, 'ads.read_account', ['business_slug' => 'laladia']);

    expect($outcome->ok())->toBeFalse()->and((string) $outcome->error)->toContain('more than one connected account');
    Http::assertNothingSent();
});

it('reads nothing for an account whose grants were never recorded', function () {
    Http::fake();
    PlatformAccount::where('platform', MetaAdsPlatform::PLATFORM)->get()->each->update(['capabilities' => null]);

    $outcome = adsTool($this->owner, 'ads.read_account', ['business_slug' => 'laladia']);

    expect($outcome->ok())->toBeFalse()->and((string) $outcome->error)->toContain('no recorded capability grants');
    Http::assertNothingSent();
});

it('refuses an agent: no agent holds an ads tool, so even registered and credentialed, the agent is stopped before Meta', function () {
    Http::fake();
    $this->seed(AgentSeeder::class);
    Agent::query()->update(['status' => 'active', 'enabling_user_id' => $this->owner->id]);
    $executive = Agent::where('key', 'executive')->sole();

    $outcome = app(ToolGateway::class)->execute(new ToolRequest(
        toolKey: 'ads.read_insights', args: ['business_slug' => 'laladia'], caller: CallerKind::Agent,
        identity: ActingIdentity::forExecution(Contexts::for($executive)), correlationId: (string) Str::uuid(),
    ));

    expect($outcome->ok())->toBeFalse();
    Http::assertNothingSent();
});

it('reports an expired token as a failure naming the Meta code, never as a silent success and never with the token', function () {
    Http::fake(['*' => Http::response(json_decode((string) file_get_contents(base_path('tests/fixtures/meta-ads/error-token-expired.json')), true), 400)]);

    $outcome = adsTool($this->owner, 'ads.read_account', ['business_slug' => 'laladia']);

    expect($outcome->ok())->toBeFalse()->and((string) $outcome->error)->toContain('code 190')->not->toContain('SYSTEM-USER-TOKEN');
});
