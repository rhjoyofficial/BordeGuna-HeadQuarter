<?php

use App\Ai\ModelTier;
use App\Ai\Providers\ProviderRegistry;
use App\Gateway\Credentials\CredentialVault;
use App\Models\AiModel;
use App\Models\FeatureFlag;
use App\Support\Features\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Ai\FixtureProvider;
use Tests\Support\Ai\SecondFixtureProvider;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * The payload the shop's DispatchOrderCreatedWebhook listener actually sends, key for key, as
 * verified against laladia.com order LLD-20260906-JRQXXJLN on 6 September 2026. It is thinner
 * than orderPayload() above: `price` instead of `unit_price`, no line total, no `source`, and a
 * flat `address` string instead of a `shipping_address` object. The invented fixture agreed with
 * itself and the suite stayed green while every live line was stored at 0.00.
 */
function liveWebhookOrderPayload(string $ref = 'LLD-20260906-JRQXXJLN'): array
{
    return ['order_number' => $ref, 'customer_name' => 'Karim Ahmed', 'customer_phone' => '01711000000',
        'customer_email' => null, 'grand_total' => 1089.0, 'subtotal' => 999.0, 'discount_total' => 0.0,
        'shipping_cost' => 90.0, 'coupon_code' => null, 'payment_method' => 'cod', 'payment_status' => 'unpaid',
        'order_status' => 'pending', 'shipping_zone' => 'Outside Dhaka', 'address' => 'House 1, Road 2',
        'items_count' => 1, 'placed_at' => '2026-09-06T14:49:14.000000Z',
        'items' => [['sku' => 'PIC-HIL-V1', 'name' => 'Hilsa Fish Pickle', 'quantity' => 3, 'price' => 333.0]],
    ];
}

/**
 * Guards the fixture above against being "fixed" toward whatever HQ currently reads. If HQ ever
 * starts recording 0.00 again, the failing test must be repaired by correcting the mapper or by
 * re-capturing the listener's real payload - never by adding keys the shop does not send.
 */
function assertLiveWebhookContractUnchanged(array $payload): void
{
    $line = $payload['items'][0];
    expect($line)->toHaveKey('price')
        ->and($line)->not->toHaveKey('unit_price')
        ->and($line)->not->toHaveKey('total')
        ->and($payload)->not->toHaveKey('source')
        ->and($payload)->not->toHaveKey('shipping_address')
        ->and($payload)->toHaveKey('address');
}

/**
 * Runs $fn with the named provider/account credentials open in the vault.
 *
 * From M2 a connector cannot resolve its own credential: the gateway opens what the tool declared
 * and the connector asks the vault (docs/50). A test that drives a connector directly, without a
 * gateway call, therefore has to open the scope itself, exactly as the diagnostic console commands
 * do. That is the point rather than an inconvenience: it makes the boundary visible in every test
 * that crosses it.
 *
 * @param  list<array{0: string, 1: string}>  $pairs  [provider, account_ref]
 */
function withCredentials(array $pairs, Closure $fn): mixed
{
    return app(CredentialVault::class)->openForAccounts($pairs, $fn);
}

/** The two credentials a Laravel shop adapter can use. */
function shopCredentials(string $store): array
{
    return [['shop_assistant_key', $store], ['shop_admin_token', $store]];
}

/**
 * M4 test helpers.
 *
 * These live here rather than in one test file because two AI test files need them, and a helper
 * declared inside a Pest file is global the moment that file loads: two files declaring the same
 * function is a fatal error that only appears when the loading order changes.
 */
function aiModel(string $provider, string $id, ModelTier $tier, int $priority = 10, string $in = '1.0000', string $out = '5.0000', array $capabilities = ['json_schema'], int $context = 200000): AiModel
{
    return AiModel::create([
        'tier' => $tier->value,
        'provider' => $provider,
        'model_id' => $id,
        'display_name' => $id,
        'context_window' => $context,
        'max_output_tokens' => 4096,
        'capabilities' => $capabilities,
        'input_price_per_mtok' => $in,
        'output_price_per_mtok' => $out,
        'cache_read_price_per_mtok' => '0.1000',
        'cache_write_price_per_mtok' => '1.2500',
        'priority' => $priority,
        'is_active' => true,
    ]);
}

/** Registers the two scripted providers and binds them, so no network or key is involved. */
function aiProviders(): ProviderRegistry
{
    $registry = new ProviderRegistry;
    $registry->register(FixtureProvider::class);
    $registry->register(SecondFixtureProvider::class);
    app()->instance(ProviderRegistry::class, $registry);

    return $registry;
}

/** ⚠ The runtime ships OFF. A test that wants a model call must turn it on deliberately. */
function enableAiRuntime(): void
{
    FeatureFlag::updateOrCreate(
        ['key' => 'ai.runtime', 'environment' => '*'],
        ['enabled' => true, 'rollout' => null],
    );
    app(Features::class)->forget();
}
