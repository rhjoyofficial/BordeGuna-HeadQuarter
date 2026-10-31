<?php

use App\Ads\Draft\AdsDraftGuard;
use App\Ads\Draft\AdsDraftPlatform;
use App\Ads\Draft\AdsDraftResult;
use App\Ads\Draft\AdsDraftSpec;
use App\Ads\Draft\Exceptions\AdsDraftRefused;
use App\Ads\Draft\Exceptions\AdsDraftUnavailable;
use App\Ads\Draft\FakeAdsDraftPlatform;
use App\Ads\Draft\MetaAdsDraftPlatform;
use App\Commerce\Exceptions\Unsupported;
use App\Gateway\Credentials\CredentialVault;
use App\Gateway\OutcomeStatus;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\AdsDraft;
use App\Models\Business;
use App\Models\FeatureFlag;
use App\Models\IntegrationCredential;
use App\Models\PlatformAccount;
use App\Models\UserBusinessScope;
use App\Tools\Connectors\CreateAdsDraftTool;
use App\Tools\ToolContext;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

/*
 | docs/144-meta-ads-drafts and docs/144-meta-readiness: paused drafts only, with the USD 5 a day cap, the Bangla-only rule and the
 | health-claims check enforced in code before the platform is called; the Meta token health; and the connector status screen.
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->owner = $this->hqUser('owner');
    $this->account = PlatformAccount::where('platform', 'meta_ad_account')->firstOrFail();
    $this->business = Business::findOrFail($this->account->brand->business_id);
});

function m3Spec(array $over = []): AdsDraftSpec
{
    $a = array_merge([
        'name' => 'হিলসা আচার', 'objective' => 'OUTCOME_TRAFFIC', 'dailyBudget' => '5.00', 'currency' => 'USD', 'pageId' => '102194475966992',
        'linkUrl' => 'https://laladia.com/product/hilsa-fish-pickle', 'primaryText' => 'ঘরোয়া স্বাদের ইলিশের আচার, অর্ডার করুন আজই।', 'headline' => 'ইলিশের আচার', 'description' => 'LaLaDia থেকে',
    ], $over);

    return new AdsDraftSpec(...$a);
}

function m3AdsArgs(Business $business, PlatformAccount $account, array $over = []): array
{
    return array_merge(['business_slug' => $business->slug, 'ad_account_ref' => (string) $account->account_ref, 'name' => 'হিলসা আচার', 'objective' => 'OUTCOME_TRAFFIC', 'daily_budget' => '5.00',
        'currency' => 'USD', 'page_id' => '102194475966992', 'link_url' => 'https://laladia.com/product/hilsa-fish-pickle',
        'primary_text' => 'ঘরোয়া স্বাদের ইলিশের আচার, অর্ডার করুন আজই।', 'headline' => 'ইলিশের আচার'], $over);
}

// ── the checks, in code ─────────────────────────────────────────────────────────────────────────

it('caps one campaign\'s daily budget at USD 5, in whole minor units, and fails closed on anything it cannot compare', function () {
    $guard = app(AdsDraftGuard::class);

    expect($guard->budget(m3Spec(['dailyBudget' => '5.00']))['ok'])->toBeTrue()
        ->and($guard->budget(m3Spec(['dailyBudget' => '5.01']))['ok'])->toBeFalse()
        ->and($guard->budget(m3Spec(['dailyBudget' => '0.00']))['ok'])->toBeFalse()
        // BDT at the configured rate: USD 5 x BDT 130 = BDT 650.00
        ->and($guard->budget(m3Spec(['dailyBudget' => '650.00', 'currency' => 'BDT']))['ok'])->toBeTrue()
        ->and($guard->budget(m3Spec(['dailyBudget' => '650.01', 'currency' => 'BDT']))['ok'])->toBeFalse();

    // No rate configured: a BDT draft is refused, never guessed.
    config(['hq.ads.draft.usd_bdt_rate' => '']);
    expect($guard->budget(m3Spec(['dailyBudget' => '1.00', 'currency' => 'BDT']))['ok'])->toBeFalse();
    // Another currency: refused.
    expect($guard->budget(m3Spec(['dailyBudget' => '1.00', 'currency' => 'EUR']))['ok'])->toBeFalse();
});

it('wants Bangla and nothing else, except the brand names it allows', function () {
    $guard = app(AdsDraftGuard::class);

    expect($guard->language(m3Spec())['ok'])->toBeTrue()
        ->and($guard->language(m3Spec(['headline' => 'Hilsa Pickle']))['ok'])->toBeFalse()
        ->and($guard->language(m3Spec(['primaryText' => 'ঘরোয়া আচার, order now']))['ok'])->toBeFalse()
        ->and($guard->language(m3Spec(['primaryText' => 'Hello']))['ok'])->toBeFalse()
        ->and($guard->language(m3Spec(['primaryText' => 'ঘরোয়া আচার ১০ জার']))['failures'][0]['why'])->toBe('Use English digits.')
        ->and($guard->language(m3Spec(['primaryText' => 'LaLaDia-র আচার, দেখুন https://laladia.com/x']))['ok'])->toBeTrue();
});

it('refuses health and quality claims unless the evidence for that claim was recorded, and never a cure', function (string $text, string $claim, bool $overridable) {
    $guard = app(AdsDraftGuard::class);
    $spec = m3Spec(['primaryText' => $text]);

    $bare = $guard->claims($spec);
    expect($bare['ok'])->toBeFalse()->and(collect($bare['hits'])->pluck('claim')->all())->toContain($claim);

    $proved = $guard->claims(m3Spec(['primaryText' => $text, 'claimProofs' => [$claim => 'Lab report LR-77, 14 Oct 2026']]));
    expect($proved['ok'])->toBe($overridable);
})->with([
    'organic' => ['১০০টি নয়, শুধু অর্গানিক আচার', 'organic', true],
    'absolute' => ['১০০% খাঁটি আচার', 'absolute', true],
    'fresh' => ['তাজা ইলিশের আচার', 'fresh', true],
    'healthy' => ['নিরাপদ ও স্বাস্থ্যকর আচার', 'healthy', true],
    'certified' => ['বিএসটিআই অনুমোদিত আচার', 'certified', true],
    'boneless' => ['কাঁটাবিহীন ইলিশ', 'boneless', true],
    'chemical free' => ['কেমিক্যাল মুক্ত আচার', 'chemical_free', true],
    'cure' => ['ডায়াবেটিস নিরাময় করে এই আচার', 'cure', false],
    'english cure' => ['A remedy for every illness, দেখুন', 'cure', false],
]);

it('passes plain, honest copy', function () {
    expect(app(AdsDraftGuard::class)->check(m3Spec())['ok'])->toBeTrue();
});

// ── the platform contract ───────────────────────────────────────────────────────────────────────

function m3FakeMetaCreate(string $mode = 'ok'): void
{
    Http::swap(new Factory);
    $seq = ['n' => 0];
    Http::fake(function ($request) use (&$seq, $mode) {
        $url = $request->url();
        if ($request->method() === 'GET') {
            return Http::response(['status' => $mode === 'not paused' && str_contains($url, '/ad') && ! str_contains($url, 'adset') ? 'ACTIVE' : 'PAUSED'], 200);
        }
        if ($mode === 'down') {
            return Http::response('down', 503);
        }
        if ($mode === 'refused-at-adset' && str_contains($url, '/adsets')) {
            return Http::response(['error' => ['message' => 'Invalid targeting']], 400);
        }

        return Http::response(['id' => 'obj'.(++$seq['n'])], 200);
    });
}

dataset('ad platforms', [
    'meta' => [MetaAdsDraftPlatform::class, true],
    'fake' => [FakeAdsDraftPlatform::class, false],
]);

function m3WithAdsToken(bool $real, string $business, Closure $fn): mixed
{
    if (! $real) {
        return $fn();
    }
    IntegrationCredential::firstOrCreate(['provider' => MetaAdsDraftPlatform::CREDENTIAL, 'account_ref' => $business], ['credential_type' => 'token', 'payload' => ['value' => 'ADS-DRAFT-TOKEN-9'], 'status' => 'active']);

    return app(CredentialVault::class)->openForAccounts([[MetaAdsDraftPlatform::CREDENTIAL, $business]], $fn);
}

it('makes a campaign, an ad set, a creative and an ad, every one of them PAUSED (contract)', function (string $class, bool $real) {
    $real && m3FakeMetaCreate();
    $platform = app($class);
    expect($platform)->toBeInstanceOf(AdsDraftPlatform::class);

    $result = m3WithAdsToken($real, 'laladia', fn () => $platform->createPausedDraft('laladia', '530866839523958', m3Spec()));

    expect($result)->toBeInstanceOf(AdsDraftResult::class)->and($result->allPaused())->toBeTrue()
        ->and($result->refs)->toHaveKeys(['campaign', 'adset', 'creative', 'ad'])->and($result->statuses)->each->toBe('PAUSED');
    if ($real) {
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/campaigns') && $r['status'] === 'PAUSED' && $r['special_ad_categories'] === []);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/adsets') && $r['status'] === 'PAUSED' && $r['daily_budget'] === 500 && $r['targeting']['geo_locations']['countries'] === ['BD']);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/ads') && ! str_contains($r->url(), 'adsets') && $r['status'] === 'PAUSED');
        // Never anything but PAUSED goes out, and the token is a header, not a URL.
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && isset($r['status']) && $r['status'] !== 'PAUSED');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'ADS-DRAFT-TOKEN-9'));
    }
})->with('ad platforms');

it('is refused as a refusal with what was already made, and reported down as unavailable (contract)', function (string $class, bool $real) {
    $platform = app($class);
    if (! $real) {
        expect(fn () => $platform->createPausedDraft('laladia', '530866839523958', m3Spec(['name' => 'FAKE-REFUSE'])))->toThrow(AdsDraftRefused::class)
            ->and(fn () => $platform->createPausedDraft('laladia', '530866839523958', m3Spec(['name' => 'FAKE-DOWN'])))->toThrow(AdsDraftUnavailable::class);

        return;
    }
    m3FakeMetaCreate('refused-at-adset');
    try {
        m3WithAdsToken(true, 'laladia', fn () => $platform->createPausedDraft('laladia', '530866839523958', m3Spec()));
        $this->fail('expected a refusal');
    } catch (AdsDraftRefused $e) {
        expect($e->created)->toHaveKey('campaign')->and($e->getMessage())->not->toContain('ADS-DRAFT-TOKEN-9');
    }
    m3FakeMetaCreate('down');
    expect(fn () => m3WithAdsToken(true, 'laladia', fn () => $platform->createPausedDraft('laladia', '530866839523958', m3Spec())))->toThrow(AdsDraftUnavailable::class);
})->with('ad platforms');

it('refuses before any request when no credential scope is open, and an ad account id that is not digits', function () {
    Http::fake();

    expect(fn () => app(MetaAdsDraftPlatform::class)->createPausedDraft('laladia', '530866839523958', m3Spec()))->toThrow(Unsupported::class)
        ->and(fn () => app(MetaAdsDraftPlatform::class)->createPausedDraft('laladia', '../me', m3Spec()))->toThrow(AdsDraftRefused::class);
    Http::assertNothingSent();
});

it('has no way to switch anything on: no activation method, no other status word, no budget change, no deletion', function () {
    expect(array_map(fn ($m) => $m->getName(), (new ReflectionClass(AdsDraftPlatform::class))->getMethods()))->toBe(['key', 'createPausedDraft']);

    foreach (glob(app_path('Ads/Draft/*.php')) as $file) {
        $source = file_get_contents($file);
        expect($source)->not->toMatch("/['\"]ACTIVE['\"]/")->not->toMatch("/['\"]ARCHIVED['\"]|['\"]DELETED['\"]/");
        expect($source)->not->toMatch('/function\s+(activate|resume|publish|enable|delete|archive|changeBudget|setBudget)/i');
    }
    // The only status the Meta driver ever names is the constant.
    expect(preg_match_all('/PAUSED/', file_get_contents(app_path('Ads/Draft/MetaAdsDraftPlatform.php'))))->toBeGreaterThan(3);
});

// ── the tool, through the gateway ───────────────────────────────────────────────────────────────

it('waits for approval, then creates the paused draft and records it for the business', function () {
    m3SandboxConnectors(['connectors.ads_draft']);
    $args = m3AdsArgs($this->business, $this->account);

    $requested = app(ToolGateway::class)->execute(ToolRequest::forOperator($this->owner, 'ads.create_draft', $args, $this->business->id));
    expect($requested->status)->toBe(OutcomeStatus::AwaitingApproval)->and(AdsDraft::count())->toBe(0);

    $result = app(CreateAdsDraftTool::class)->execute($args, new ToolContext('k', 'c', $this->business->id, actorUserId: $this->owner->id));
    expect($result->ok())->toBeTrue();
    $draft = AdsDraft::sole();
    expect($draft->status)->toBe('created_paused')->and($draft->business_id)->toBe($this->business->id)->and($draft->checks['ok'])->toBeTrue()
        ->and($result->output['statuses'])->each->toBe('PAUSED');
});

it('shows the checks in the approval preview, so the approver sees a refused draft before deciding', function () {
    $preview = app(CreateAdsDraftTool::class)->preview(m3AdsArgs($this->business, $this->account, ['daily_budget' => '50.00']), new ToolContext('k', 'c', $this->business->id));

    expect($preview['checks']['ok'])->toBeFalse()->and($preview['checks']['budget']['ok'])->toBeFalse()->and($preview['external_effect'])->toContain('paused');
});

it('creates nothing when a check fails, whoever approved it', function (array $over) {
    m3SandboxConnectors(['connectors.ads_draft']);
    Http::fake();

    $result = app(CreateAdsDraftTool::class)->execute(m3AdsArgs($this->business, $this->account, $over), new ToolContext('k', 'c', $this->business->id));

    expect($result->ok())->toBeFalse()->and($result->output['checks']['ok'] ?? null)->toBeFalse()->and(AdsDraft::count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'over the cap' => [['daily_budget' => '5.50']],
    'english copy' => [['headline' => 'Hilsa Pickle']],
    'a cure claim' => [['primary_text' => 'ডায়াবেটিস নিরাময়ের আচার']],
    'an unproven claim' => [['primary_text' => 'তাজা ইলিশের আচার']],
]);

it('lets a recorded piece of evidence carry its claim, and only that claim', function () {
    m3SandboxConnectors(['connectors.ads_draft']);
    $args = m3AdsArgs($this->business, $this->account, ['primary_text' => 'বিএসটিআই অনুমোদিত আচার, তাজা স্বাদ']);

    $one = app(CreateAdsDraftTool::class)->execute($args + ['claim_proofs' => ['certified' => 'BSTI cert 123']], new ToolContext('k', 'c', $this->business->id));
    expect($one->ok())->toBeFalse(); // "fresh" is still unproven

    $both = app(CreateAdsDraftTool::class)->execute($args + ['claim_proofs' => ['certified' => 'BSTI cert 123', 'fresh' => 'Packed 12 Oct, batch 4']], new ToolContext('k2', 'c2', $this->business->id));
    expect($both->ok())->toBeTrue();
});

it('uses only an ad account that belongs to the business, and only a business the person may use', function () {
    m3SandboxConnectors(['connectors.ads_draft']);
    $other = Business::where('id', '!=', $this->business->id)->firstOrFail();

    $foreign = app(CreateAdsDraftTool::class)->execute(m3AdsArgs($other, $this->account), new ToolContext('k', 'c', $other->id));
    expect($foreign->ok())->toBeFalse()->and((string) $foreign->error)->toContain('not one of this business')->and(AdsDraft::count())->toBe(0);

    $restricted = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $restricted->id, 'business_id' => $other->id]);
    $outcome = app(ToolGateway::class)->execute(ToolRequest::forOperator($restricted, 'ads.create_draft', m3AdsArgs($this->business, $this->account), $this->business->id));
    expect($outcome->ok())->toBeFalse()->and($outcome->status)->not->toBe(OutcomeStatus::AwaitingApproval);
});

it('reports a platform that left something active as a failure, naming the objects', function () {
    m3SandboxConnectors(['connectors.ads_draft']);
    $platform = new class implements AdsDraftPlatform
    {
        public function key(): string
        {
            return 'fake_ads';
        }

        public function createPausedDraft(string $accountRef, string $adAccountId, AdsDraftSpec $spec): AdsDraftResult
        {
            return new AdsDraftResult(['campaign' => 'c1', 'adset' => 's1', 'creative' => 'cr1', 'ad' => 'a1'], ['campaign' => 'PAUSED', 'adset' => 'PAUSED', 'ad' => 'ACTIVE']);
        }
    };
    app()->bind(FakeAdsDraftPlatform::class, fn () => $platform);

    $result = app(CreateAdsDraftTool::class)->execute(m3AdsArgs($this->business, $this->account), new ToolContext('k', 'c', $this->business->id));

    expect($result->ok())->toBeFalse()->and($result->output['objects']['campaign'])->toBe('c1')->and(AdsDraft::sole()->status)->toBe('failed');
});

it('lets the screen run the checks without creating anything', function () {
    $this->actingAsHq($this->owner)->postJson('/ads-drafts/check', m3AdsArgs($this->business, $this->account, ['daily_budget' => '9.00']))
        ->assertOk()->assertJson(['ok' => false, 'budget' => ['ok' => false]]);

    expect(AdsDraft::count())->toBe(0);
});

// ── Meta token health ───────────────────────────────────────────────────────────────────────────

function m3MetaSecrets(): void
{
    config(['hq.meta_health.app_id' => '1446749247331336']);
    IntegrationCredential::create(['provider' => 'meta_app_secret', 'account_ref' => 'meta', 'credential_type' => 'token', 'payload' => ['value' => 'APP-SECRET-xyz'], 'status' => 'active']);
    IntegrationCredential::create(['provider' => 'meta_page_token', 'account_ref' => '102194475966992', 'credential_type' => 'token', 'payload' => ['value' => 'PAGE-TOKEN-abc'], 'status' => 'active']);
}

it('asks Meta about a stored token and records validity, expiry and scopes on its row, never the value', function () {
    m3SandboxConnectors(['connectors.meta_health']);
    m3MetaSecrets();
    Http::fake(['*debug_token*' => Http::response(['data' => ['is_valid' => true, 'expires_at' => now()->addDays(5)->timestamp, 'scopes' => ['pages_messaging', 'pages_manage_posts'], 'type' => 'PAGE']], 200)]);

    $outcome = app(ToolGateway::class)->execute(ToolRequest::forOperator($this->owner, 'meta.check_token_health', ['kind' => 'meta_page_token', 'account_ref' => '102194475966992']));

    expect($outcome->ok())->toBeTrue()->and($outcome->output['valid'])->toBeTrue()->and($outcome->output['scopes'])->toContain('pages_messaging');
    $row = IntegrationCredential::where('provider', 'meta_page_token')->sole();
    expect($row->scopes)->toContain('pages_manage_posts')->and($row->last_verified_at)->not->toBeNull()->and($row->expires_at->isFuture())->toBeTrue()->and($row->status)->toBe('active');
    expect(json_encode([$outcome->toArray(), $row->meta]))->not->toContain('PAGE-TOKEN-abc')->not->toContain('APP-SECRET-xyz');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'debug_token') && $r['access_token'] === '1446749247331336|APP-SECRET-xyz');
});

it('marks a token Meta says is invalid, and says what is missing when it cannot ask', function () {
    m3SandboxConnectors(['connectors.meta_health']);
    m3MetaSecrets();
    Http::fake(['*debug_token*' => Http::response(['data' => ['is_valid' => false, 'scopes' => []]], 200)]);

    app(ToolGateway::class)->execute(ToolRequest::forOperator($this->owner, 'meta.check_token_health', ['kind' => 'meta_page_token', 'account_ref' => '102194475966992']));
    expect(IntegrationCredential::where('provider', 'meta_page_token')->sole()->status)->toBe('invalid');

    config(['hq.meta_health.app_id' => '']);
    $missing = app(ToolGateway::class)->execute(ToolRequest::forOperator($this->owner, 'meta.check_token_health', ['kind' => 'meta_page_token', 'account_ref' => '102194475966992']));
    expect($missing->ok())->toBeFalse();
});

it('is not a tool at all while its switch is off', function () {
    m3SandboxConnectors([]);

    $outcome = app(ToolGateway::class)->execute(ToolRequest::forOperator($this->owner, 'meta.check_token_health', ['kind' => 'meta_page_token', 'account_ref' => '1']));
    expect($outcome->ok())->toBeFalse()->and((string) $outcome->error)->toContain('Unknown tool');
});

// ── the Connectors screen ───────────────────────────────────────────────────────────────────────

it('says honestly that nothing is connected yet, and what each connector needs', function () {
    $page = $this->actingAsHq($this->owner)->get('/connectors')->assertOk()->assertInertia(fn ($p) => $p->component('connectors/index'))->inertiaProps();
    $families = collect($page['families'])->keyBy('key');

    expect($families->keys()->all())->toBe(['courier', 'voice', 'media_upload', 'media_pipeline', 'mail_ingestion', 'erp_draft_bill', 'ads_draft'])
        ->and($families->every(fn ($f) => $f['enabled'] === false && in_array($f['state'], ['off'], true)))->toBeTrue()
        ->and(collect($families['courier']['providers'])->pluck('provider')->all())->toBe(['pathao', 'carrybee', 'steadfast', 'redx'])
        ->and($families['courier']['providers'][0]['needs'][0])->toMatchArray(['kind' => 'pathao_courier_secret', 'stored' => false])
        ->and($families['erp_draft_bill']['providers'][0]['needs'][0]['kind'])->toBe('erp_write_token');
    expect(json_encode($page))->not->toContain('"provider":"sandbox"');
});

it('moves a connector to needs-credentials when its switch is on and to connected when its key is stored', function () {
    FeatureFlag::query()->updateOrCreate(['key' => 'connectors.voice', 'environment' => '*'], ['description' => 't', 'enabled' => true]);
    m3RebuildRegistries();
    $state = fn () => collect($this->actingAsHq($this->owner)->get('/connectors')->inertiaProps()['families'])->firstWhere('key', 'voice')['state'];

    expect($state())->toBe('needs_credentials');

    IntegrationCredential::create(['provider' => 'speech_openai_compatible_key', 'account_ref' => 'hq', 'credential_type' => 'api_key', 'payload' => ['value' => 'k'], 'status' => 'active']);
    expect($state())->toBe('ready');
});

it('shows a restricted person only the tokens of their own businesses', function () {
    $restricted = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $restricted->id, 'business_id' => $this->business->id]);
    $other = Business::where('id', '!=', $this->business->id)->firstOrFail();
    IntegrationCredential::create(['provider' => 'meta_ads_token', 'account_ref' => $this->business->slug, 'business_id' => $this->business->id, 'credential_type' => 'token', 'payload' => ['value' => 'a'], 'status' => 'active']);
    IntegrationCredential::create(['provider' => 'meta_ads_token', 'account_ref' => $other->slug, 'business_id' => $other->id, 'credential_type' => 'token', 'payload' => ['value' => 'b'], 'status' => 'active']);
    IntegrationCredential::create(['provider' => 'meta_page_token', 'account_ref' => 'hq-wide-page', 'credential_type' => 'token', 'payload' => ['value' => 'c'], 'status' => 'active']);

    $mine = collect($this->actingAsHq($restricted)->get('/connectors')->inertiaProps()['meta']['tokens'])->pluck('account_ref')->all();
    $all = collect($this->actingAsHq($this->owner)->get('/connectors')->inertiaProps()['meta']['tokens'])->pluck('account_ref')->all();

    expect($mine)->toBe([$this->business->slug])->and($all)->toContain($other->slug)->toContain('hq-wide-page');
});

it('refuses the screens to a person without the permission', function () {
    $viewer = $this->hqUser('finance_viewer');

    foreach (['/connectors', '/ingestion', '/media-drafts', '/ads-drafts'] as $url) {
        $this->actingAsHq($viewer)->get($url)->assertForbidden();
    }
    $this->actingAsHq($viewer)->post('/ads-drafts/check', [])->assertForbidden();
    $this->actingAsHq($viewer)->post('/parcels/track', [])->assertForbidden();
});
