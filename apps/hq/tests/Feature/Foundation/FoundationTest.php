<?php

use App\Models\Business;
use App\Models\DomainEvent;
use App\Models\FeatureFlag;
use App\Models\IntegrationCredential;
use App\Models\Store;
use App\Support\Events\EventHandler;
use App\Support\Events\EventRecorder;
use App\Support\Features\Features;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;

it('encrypts integration credential payloads at rest and never exposes them', function () {
    $c = IntegrationCredential::create(['provider' => 'meta', 'account_ref' => 'page:1', 'credential_type' => 'token', 'payload' => ['access_token' => 'EAAB-super-secret'], 'status' => 'active']);

    $raw = DB::table('integration_credentials')->where('id', $c->id)->value('payload');
    expect($raw)->not->toContain('EAAB-super-secret')
        ->and($c->fresh()->payload['access_token'])->toBe('EAAB-super-secret')
        ->and($c->fresh()->hasPayload())->toBeTrue()
        ->and(json_encode($c->fresh()))->not->toContain('EAAB')
        ->and($c->toSafeArray())->not->toHaveKey('payload')
        ->and($c->toSafeArray()['has_payload'])->toBeTrue();
});

it('feature flags default off, honour environment precedence and business rollout', function () {
    $features = app(Features::class);
    expect($features->enabled('phase1.adapters'))->toBeFalse();

    FeatureFlag::create(['key' => 'phase1.adapters', 'environment' => '*', 'enabled' => true, 'rollout' => ['businesses' => [1]]]);
    $features->forget();
    expect($features->enabled('phase1.adapters'))->toBeTrue()
        ->and($features->enabled('phase1.adapters', 1))->toBeTrue()
        ->and($features->enabled('phase1.adapters', 2))->toBeFalse();

    FeatureFlag::create(['key' => 'phase1.adapters', 'environment' => app()->environment(), 'enabled' => false]);
    $features->forget();
    expect($features->enabled('phase1.adapters'))->toBeFalse();
});

it('records events once per dedupe key and processes them with retries and dead-lettering', function () {
    $recorder = app(EventRecorder::class);
    $a = $recorder->record('test.noop', ['ref' => 'LLD-1'], 'laladia', 'inbound', 'order', 'LLD-1', dedupeKey: 'laladia:test.noop:LLD-1');
    $b = $recorder->record('test.noop', ['ref' => 'LLD-1'], 'laladia', 'inbound', 'order', 'LLD-1', dedupeKey: 'laladia:test.noop:LLD-1');
    expect($a->id)->toBe($b->id)->and(DomainEvent::count())->toBe(1)->and($a->status)->toBe('pending');

    // No handler registered: processed (nothing to do is not a failure).
    $this->artisan('hq:events:process')->assertSuccessful();
    expect($a->fresh()->status)->toBe('processed')->and($a->fresh()->processed_at)->not->toBeNull();

    // A failing handler: failed with backoff, then dead after max attempts.
    config(['hq.events.handlers' => ['test.fails' => [FailingHandler::class]], 'hq.events.backoff_seconds' => [0, 0, 0]]);
    $e = $recorder->record('test.fails', [], 'test', 'outbound', dedupeKey: 'test:fails:1');
    $e->forceFill(['max_attempts' => 2])->save();
    $this->artisan('hq:events:process')->assertSuccessful();
    expect($e->fresh()->status)->toBe('failed')->and($e->fresh()->attempts)->toBe(1)->and($e->fresh()->last_error)->toContain('boom');
    $this->artisan('hq:events:process')->assertSuccessful();
    expect($e->fresh()->status)->toBe('dead')->and($e->fresh()->attempts)->toBe(2);
});

it('seeds the domain model idempotently from configuration', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);
    expect(Business::count())->toBe(count(config('hq-seed.businesses')))
        ->and(Store::count())->toBe(count(config('hq-seed.stores')))
        ->and(Business::where('slug', 'laladia')->sole()->erp_business_id)->toBe(1)
        ->and(Store::where('slug', 'laladia-com')->sole()->productOwners()->pluck('slug')->all())->toBe(['bor-de-guna'])
        ->and(Store::where('slug', 'econic-store')->sole()->adapter)->toBe('woocommerce');
});

final class FailingHandler implements EventHandler
{
    public function handle(DomainEvent $event): void
    {
        throw new RuntimeException('boom');
    }
}
