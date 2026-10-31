<?php

use App\Avatar\AvatarGenerations;
use App\Avatar\AvatarProvider;
use App\Avatar\FakeAvatarProvider;
use App\Avatar\PhaseEightTools;
use App\Models\Approval;
use App\Models\AvatarConsent;
use App\Models\AvatarGeneration;
use App\Models\Brand;
use App\Models\Business;
use App\Models\MediaAsset;
use App\Research\ResearchRefused;
use App\Tools\Avatar\GenerateAvatarTool;
use App\Tools\ToolRegistry;
use Database\Seeders\DomainSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Completion\M4;

/*
 | docs/145-b (Phase 8): no consent, no generation. A real person's consent points at a signed document;
 | consent always ends; revocation is final, immediate and withdraws unfinished work, even a call already
 | at the provider; the generation tool is declared and registered nowhere; business scope everywhere.
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->owner = $this->hqUser('owner');
    $this->laladia = Business::where('slug', 'laladia')->sole();
    $this->bionic = Business::where('slug', 'bionic')->sole();
    $this->lld = Brand::where('code', 'LLD')->sole();
});

function acDocument(Business $business): MediaAsset
{
    return MediaAsset::query()->create(['business_id' => $business->id, 'kind' => 'document', 'mime' => 'application/pdf', 'bytes' => 10, 'checksum_sha256' => str_repeat('b', 64),
        'storage_driver' => 'local', 'storage_key' => 'consents/x.pdf', 'provenance' => 'business']);
}

function acRecord(array $over = []): array
{
    return $over + [
        'business_id' => test()->laladia->id, 'person_label' => 'Presenter A', 'kind' => 'synthetic_persona', 'modalities' => ['face'],
        'permitted_uses' => ['product_promo'], 'brand_ids' => [test()->lld->id], 'expires_at' => now()->addMonths(6)->toDateString(),
    ];
}

it('registers the avatar routes behind auth, 2FA and their permissions, and declares the tool without registering it', function () {
    foreach (['avatar.index' => 'permission:hq.avatar.view', 'avatar.consents.store' => 'permission:hq.avatar.consent.manage',
        'avatar.consents.revoke' => 'permission:hq.avatar.consent.manage', 'avatar.generate' => 'permission:hq.avatar.generate'] as $name => $permission) {
        expect(Route::getRoutes()->getByName($name)->gatherMiddleware())->toContain('auth', '2fa.enabled', '2fa.verified', $permission);
    }
    foreach (PhaseEightTools::DECLARED as $class) {
        expect(in_array($class, config('hq.tools'), true))->toBeFalse();
    }
    expect(app(ToolRegistry::class)->has(GenerateAvatarTool::KEY))->toBeFalse()
        ->and(app()->bound(AvatarProvider::class))->toBeFalse();
    $d = app(GenerateAvatarTool::class)->descriptor();
    expect($d->permanentlyHumanOnly)->toBeTrue()->and($d->riskTier->value)->toBe('r4')->and($d->agentExecutable)->toBeFalse();
});

it('refuses a real person\'s consent without a signed document of this business, and any consent without an end', function () {
    $this->actingAsHq($this->owner)->post('/avatar/consents', acRecord(['kind' => 'real_person']))->assertSessionHasErrors('form');
    $this->actingAsHq($this->owner)->post('/avatar/consents', acRecord(['kind' => 'real_person', 'consent_document_asset_id' => acDocument($this->bionic)->id, 'signed_at' => now()->subDay()->toDateString()]))
        ->assertSessionHasErrors('form');
    $this->actingAsHq($this->owner)->post('/avatar/consents', acRecord(['expires_at' => now()->addYears(5)->toDateString()]))->assertSessionHasErrors('form');
    expect(AvatarConsent::query()->count())->toBe(0);

    $this->actingAsHq($this->owner)->post('/avatar/consents', acRecord(['kind' => 'real_person', 'consent_document_asset_id' => acDocument($this->laladia)->id, 'signed_at' => now()->subDay()->toDateString()]))
        ->assertSessionHasNoErrors();
    expect(AvatarConsent::query()->sole()->kind)->toBe('real_person');
});

it('generates only on a valid consent, with the flag on, a provider bound and AI not paused, and records every refusal', function () {
    $this->actingAsHq($this->owner)->post('/avatar/consents', acRecord())->assertSessionHasNoErrors();
    $consent = AvatarConsent::query()->sole();
    $service = app(AvatarGenerations::class);
    $go = fn (array $o = []) => $service->generate($this->owner, $consent->id, $o['brand'] ?? $this->lld->id, $o['use'] ?? 'product_promo', $o['modality'] ?? 'face', 'হ্যালো');

    expect(fn () => $go())->toThrow(ResearchRefused::class, 'flag avatar.generation');
    M4::on('avatar.generation');
    expect(fn () => $go())->toThrow(ResearchRefused::class, 'No avatar provider');

    $provider = new FakeAvatarProvider;
    app()->instance(AvatarProvider::class, $provider);
    expect(fn () => $go(['modality' => 'voice']))->toThrow(ResearchRefused::class, 'voice')
        ->and(fn () => $go(['use' => 'internal']))->toThrow(ResearchRefused::class, 'internal')
        ->and(fn () => $go(['brand' => Brand::where('code', 'BNC')->value('id')]))->toThrow(ResearchRefused::class, 'brand');
    M4::pause();
    expect(fn () => $go())->toThrow(ResearchRefused::class, 'Pause All AI');
    M4::pause(false);

    $row = $go();
    expect($row->status)->toBe('generated')->and($row->provider_job_ref)->toStartWith('sandbox-job-')->and($provider->requests)->toHaveCount(1)
        ->and(AvatarGeneration::query()->where('status', 'refused')->count())->toBe(4);
});

it('revokes at once: a revocation landing while the provider works withdraws the result, and nothing more is generated', function () {
    M4::on('avatar.generation');
    $this->actingAsHq($this->owner)->post('/avatar/consents', acRecord())->assertSessionHasNoErrors();
    $consent = AvatarConsent::query()->sole();
    $provider = new FakeAvatarProvider;
    $provider->during = fn () => DB::table('avatar_consents')->where('id', $consent->id)->update(['revoked_at' => now(), 'revocation_reason' => 'asked to stop']);
    app()->instance(AvatarProvider::class, $provider);

    $row = app(AvatarGenerations::class)->generate($this->owner, $consent->id, $this->lld->id, 'product_promo', 'face', 'হ্যালো');
    expect($row->status)->toBe('withdrawn');

    expect(fn () => app(AvatarGenerations::class)->generate($this->owner, $consent->id, $this->lld->id, 'product_promo', 'face', 'আবার'))->toThrow(ResearchRefused::class, 'revoked');
});

it('withdraws unfinished generations on revocation through the screen, and a revoked consent can never be revived or widened', function () {
    $this->actingAsHq($this->owner)->post('/avatar/consents', acRecord())->assertSessionHasNoErrors();
    $consent = AvatarConsent::query()->sole();
    $pending = AvatarGeneration::query()->create(['consent_id' => $consent->id, 'business_id' => $consent->business_id, 'brand_id' => $this->lld->id, 'use' => 'product_promo', 'modality' => 'face', 'script' => 'x', 'status' => 'requested']);

    expect(fn () => DB::transaction(fn () => AvatarConsent::query()->whereKey($consent->id)->update(['permitted_uses' => json_encode(['product_promo', 'internal'])])))->toThrow(QueryException::class);

    $this->actingAsHq($this->owner)->post("/avatar/consents/{$consent->uuid}/revoke", ['reason' => 'contract ended'])->assertSessionHasNoErrors();
    expect($consent->fresh()->revoked_at)->not->toBeNull()->and($pending->fresh()->status)->toBe('withdrawn');

    expect(fn () => DB::transaction(fn () => AvatarConsent::query()->whereKey($consent->id)->update(['revoked_at' => null])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => AvatarConsent::query()->whereKey($consent->id)->delete()))->toThrow(QueryException::class);
});

it('asks the gateway for a clip as the signed-in person, and the unregistered tool is refused with nothing generated', function () {
    M4::on('avatar.generation');
    app()->instance(AvatarProvider::class, $provider = new FakeAvatarProvider);
    $this->actingAsHq($this->owner)->post('/avatar/consents', acRecord())->assertSessionHasNoErrors();
    $consent = AvatarConsent::query()->sole();

    $this->actingAsHq($this->owner)->post('/avatar/generate', ['consent_uuid' => $consent->uuid, 'brand_id' => $this->lld->id, 'use' => 'product_promo', 'modality' => 'face', 'script' => 'হ্যালো'])
        ->assertSessionHasErrors('generate');
    expect($provider->requests)->toBe([])->and(AvatarGeneration::query()->count())->toBe(0);
});

it('keeps another business\'s consents out of sight and out of reach for a person limited to one business', function () {
    $this->actingAsHq($this->owner)->post('/avatar/consents', acRecord(['business_id' => $this->bionic->id, 'brand_ids' => [Brand::where('code', 'BNC')->value('id')]]))->assertSessionHasNoErrors();
    $theirs = AvatarConsent::query()->sole();
    $limited = M4::limit($this->hqUser('operator'), 'laladia');

    $this->actingAsHq($limited)->get('/avatar')->assertOk()->assertInertia(fn (Assert $p) => $p->component('avatar/index')->has('consents', 0));
    $this->actingAsHq($limited)->post("/avatar/consents/{$theirs->uuid}/revoke", ['reason' => 'x'])->assertNotFound();
    $this->actingAsHq($limited)->post('/avatar/generate', ['consent_uuid' => $theirs->uuid, 'brand_id' => 1, 'use' => 'product_promo', 'modality' => 'face', 'script' => 'x'])->assertNotFound();
    expect($theirs->fresh()->revoked_at)->toBeNull();
});

it('when a release registers the tool, a clip waits for a person\'s approval and nothing is generated before it', function () {
    config(['hq.tools' => [...array_values(config('hq.tools', [])), GenerateAvatarTool::class]]);
    app()->forgetInstance(ToolRegistry::class);
    M4::on('avatar.generation');
    app()->instance(AvatarProvider::class, $provider = new FakeAvatarProvider);
    $this->actingAsHq($this->owner)->post('/avatar/consents', acRecord())->assertSessionHasNoErrors();
    $consent = AvatarConsent::query()->sole();

    $this->actingAsHq($this->owner)->post('/avatar/generate', ['consent_uuid' => $consent->uuid, 'brand_id' => $this->lld->id, 'use' => 'product_promo', 'modality' => 'face', 'script' => 'হ্যালো'])
        ->assertSessionHasNoErrors()->assertSessionHas('status', 'avatar-generation-awaiting-approval');

    $approval = Approval::query()->where('tool_key', GenerateAvatarTool::KEY)->sole();
    expect($approval->status->value)->toBe('pending')->and($provider->requests)->toBe([])->and(AvatarGeneration::query()->count())->toBe(0)
        // The person who asked may not decide it (requesterMayDecide: false).
        ->and($approval->separationOfDutiesReason($this->owner))->not->toBeNull();
});
