<?php

use App\Courier\CourierConnector;
use App\Courier\Drivers\CarryBeeCourier;
use App\Courier\Drivers\PathaoCourier;
use App\Courier\Drivers\RedxCourier;
use App\Courier\Drivers\SteadfastCourier;
use App\Courier\Exceptions\CourierRefused;
use App\Courier\Exceptions\CourierUnavailable;
use App\Courier\Exceptions\InvalidCourierWebhook;
use App\Courier\Fake\FakeCarryBeeCourier;
use App\Courier\Fake\FakePathaoCourier;
use App\Courier\Fake\FakeRedxCourier;
use App\Courier\Fake\FakeSteadfastCourier;
use App\Courier\ParcelStatus;
use App\Gateway\Credentials\CredentialVault;
use App\Models\IntegrationCredential;
use Illuminate\Support\Facades\Http;

/*
 | docs/144-courier-connectors: the contract EVERY courier connector must pass, the four real drivers (against faked HTTP in the
 | shapes they were written from) and the four fakes alike. A real driver is proven against the courier's own sandbox when the keys
 | arrive; this proves HQ's own behaviour: statuses, refusals, unavailability, webhook authentication, and that no secret leaks.
 */

const M3_SECRET = 'whsec-test-value-123';

/** @return array<string, array{class: class-string<CourierConnector>, real: bool, ref: string, credential: ?array{kind: string, value: string}, fake: ?callable, webhook: array{headers: array<string,string>, body: array<string,mixed>}}> */
function m3CourierCases(): array
{
    $at = '2026-10-02T10:00:00Z';

    return [
        'steadfast' => ['class' => SteadfastCourier::class, 'real' => true, 'ref' => 'SF100',
            'credential' => ['kind' => SteadfastCourier::CREDENTIAL, 'value' => json_encode(['api_key' => 'KEY-AAA', 'secret_key' => 'KEY-BBB'])],
            'webhook' => ['headers' => ['authorization' => 'Bearer '.M3_SECRET], 'body' => ['notification_type' => 'delivery_status', 'consignment_id' => 'SF100', 'status' => 'delivered', 'tracking_message' => 'Handed over', 'updated_at' => $at]]],
        'redx' => ['class' => RedxCourier::class, 'real' => true, 'ref' => 'RX100',
            'credential' => ['kind' => RedxCourier::CREDENTIAL, 'value' => 'RX-TOKEN-AAA'],
            'webhook' => ['headers' => ['x-hq-webhook-token' => M3_SECRET], 'body' => ['tracking_id' => 'RX100', 'message_en' => 'Delivered', 'timestamp' => $at]]],
        'pathao' => ['class' => PathaoCourier::class, 'real' => true, 'ref' => 'PA100',
            'credential' => ['kind' => PathaoCourier::CREDENTIAL, 'value' => json_encode(['client_id' => 'cid', 'client_secret' => 'PA-SECRET-AAA', 'username' => 'u@example.test', 'password' => 'PA-PASS-AAA'])],
            'webhook' => ['headers' => ['x-pathao-signature' => M3_SECRET], 'body' => ['consignment_id' => 'PA100', 'order_status' => 'Delivered', 'merchant_order_id' => 'ORD-1', 'updated_at' => $at]]],
        'carrybee' => ['class' => CarryBeeCourier::class, 'real' => true, 'ref' => 'CB100',
            'credential' => ['kind' => CarryBeeCourier::CREDENTIAL, 'value' => json_encode(['client_id' => 'cid', 'client_secret' => 'CB-SECRET-AAA', 'client_context' => 'ctx'])],
            'webhook' => ['headers' => ['x-carrybee-webhook-signature' => M3_SECRET], 'body' => ['consignment_id' => 'CB100', 'status' => 'Delivered', 'updated_at' => $at]]],
        'fake pathao' => ['class' => FakePathaoCourier::class, 'real' => false, 'ref' => 'FAKE-DELIVERED-1', 'credential' => null,
            'webhook' => ['headers' => ['x-hq-webhook-token' => M3_SECRET], 'body' => ['consignment_id' => 'FAKE-DELIVERED-1', 'status' => 'Delivered']]],
        'fake carrybee' => ['class' => FakeCarryBeeCourier::class, 'real' => false, 'ref' => 'FAKE-DELIVERED-1', 'credential' => null,
            'webhook' => ['headers' => ['x-hq-webhook-token' => M3_SECRET], 'body' => ['consignment_id' => 'FAKE-DELIVERED-1', 'status' => 'Delivered']]],
        'fake steadfast' => ['class' => FakeSteadfastCourier::class, 'real' => false, 'ref' => 'FAKE-DELIVERED-1', 'credential' => null,
            'webhook' => ['headers' => ['x-hq-webhook-token' => M3_SECRET], 'body' => ['consignment_id' => 'FAKE-DELIVERED-1', 'status' => 'Delivered']]],
        'fake redx' => ['class' => FakeRedxCourier::class, 'real' => false, 'ref' => 'FAKE-DELIVERED-1', 'credential' => null,
            'webhook' => ['headers' => ['x-hq-webhook-token' => M3_SECRET], 'body' => ['consignment_id' => 'FAKE-DELIVERED-1', 'status' => 'Delivered']]],
    ];
}

/** Fake the courier's HTTP for one real driver: a known parcel, an unknown one (404) and a down courier (503). */
function m3FakeCourierHttp(string $class, string $mode): void
{
    config(['hq.courier.carrybee.base_url' => 'https://carrybee.test']);
    $answer = static fn (array $ok) => $mode === 'ok' ? Http::response($ok, 200) : ($mode === 'unknown' ? Http::response(['message' => 'not found'], 404) : Http::response('down', 503));
    $at = '2026-10-02T10:00:00Z';

    Http::fake([
        '*issue-token*' => Http::response(['access_token' => 'PA-ACCESS-TOKEN'], 200),
        '*status_by_cid*' => $answer(['status' => 200, 'delivery_status' => 'delivered_approval_pending']),
        '*parcel/track*' => $answer(['tracking' => [['message_en' => 'Picked up', 'time' => '2026-10-01T10:00:00Z'], ['message_en' => 'Delivered', 'time' => $at]]]),
        '*orders/*/info' => $answer(['data' => ['order_status' => 'Delivered', 'updated_at' => $at, 'merchant_order_id' => 'ORD-1']]),
        '*orders/*/details' => $answer(['data' => ['status' => 'In Transit', 'updated_at' => $at]]),
    ]);
}

/** Run the callback with the courier's credential open in the vault, as a gateway call would. */
function m3WithCourierCredential(?array $credential, Closure $fn): mixed
{
    if ($credential === null) {
        return $fn();
    }
    IntegrationCredential::create(['provider' => $credential['kind'], 'account_ref' => 'laladia', 'credential_type' => 'token', 'payload' => ['value' => $credential['value']], 'status' => 'active']);

    return app(CredentialVault::class)->openForAccounts([[$credential['kind'], 'laladia']], $fn);
}

dataset('couriers', fn () => array_map(fn ($c) => [$c], m3CourierCases()));

it('answers a known parcel with at least one event and a status in HQ\'s own words', function (array $case) {
    $case['real'] && m3FakeCourierHttp($case['class'], 'ok');
    $courier = app($case['class']);

    $snapshot = m3WithCourierCredential($case['credential'], fn () => $courier->track('laladia', $case['ref']));

    expect($snapshot->provider)->toBe($courier->key())
        ->and($snapshot->consignmentRef)->toBe($case['ref'])
        ->and($snapshot->events)->not->toBeEmpty()
        ->and($snapshot->status)->toBeInstanceOf(ParcelStatus::class)
        ->and($snapshot->status)->not->toBe(ParcelStatus::Unknown);
})->with('couriers');

it('refuses a consignment the courier does not know, as a refusal and not an outage', function (array $case) {
    $case['real'] && m3FakeCourierHttp($case['class'], 'unknown');
    $courier = app($case['class']);

    expect(fn () => m3WithCourierCredential($case['credential'], fn () => $courier->track('laladia', $case['real'] ? $case['ref'] : 'FAKE-UNKNOWN-1')))
        ->toThrow(CourierRefused::class);
})->with('couriers');

it('reports a courier that is down as unavailable, never as an answer', function (array $case) {
    $case['real'] && m3FakeCourierHttp($case['class'], 'down');
    $courier = app($case['class']);

    expect(fn () => m3WithCourierCredential($case['credential'], fn () => $courier->track('laladia', $case['real'] ? $case['ref'] : 'FAKE-DOWN-1')))
        ->toThrow(CourierUnavailable::class);
})->with('couriers');

it('accepts an authentic webhook and rejects a missing or wrong secret before reading the body', function (array $case) {
    $courier = app($case['class']);
    $body = json_encode($case['body'] ?? $case['webhook']['body']);

    $snapshot = $courier->receiveWebhook($body, $case['webhook']['headers'], M3_SECRET);
    expect($snapshot)->not->toBeNull()->and($snapshot->status)->toBe(ParcelStatus::Delivered)->and($snapshot->events)->not->toBeEmpty();

    // Wrong secret, no secret, and an empty configured secret: all refused, and not even valid JSON is needed to be refused.
    $wrong = array_map(fn () => 'not-the-secret', $case['webhook']['headers']);
    expect(fn () => $courier->receiveWebhook($body, $wrong, M3_SECRET))->toThrow(InvalidCourierWebhook::class)
        ->and(fn () => $courier->receiveWebhook($body, [], M3_SECRET))->toThrow(InvalidCourierWebhook::class)
        ->and(fn () => $courier->receiveWebhook('{{ not json', $wrong, M3_SECRET))->toThrow(InvalidCourierWebhook::class)
        ->and(fn () => $courier->receiveWebhook($body, $case['webhook']['headers'], ''))->toThrow(InvalidCourierWebhook::class);
})->with('couriers');

it('refuses an authentic webhook that is not a status change', function (array $case) {
    $courier = app($case['class']);

    expect(fn () => $courier->receiveWebhook(json_encode(['hello' => 'world']), $case['webhook']['headers'], M3_SECRET))->toThrow(InvalidCourierWebhook::class);
})->with('couriers');

it('never puts a credential in an exception message', function (array $case) {
    if (! $case['real']) {
        expect(true)->toBeTrue();

        return;
    }
    foreach (['unknown', 'down'] as $mode) {
        m3FakeCourierHttp($case['class'], $mode);
        try {
            m3WithCourierCredential($case['credential'], fn () => app($case['class'])->track('laladia', $case['ref']));
        } catch (Throwable $e) {
            foreach (['KEY-AAA', 'KEY-BBB', 'RX-TOKEN-AAA', 'PA-SECRET-AAA', 'PA-PASS-AAA', 'CB-SECRET-AAA', 'PA-ACCESS-TOKEN'] as $secret) {
                expect($e->getMessage())->not->toContain($secret);
            }
        }
        // Each mode needs a fresh credential row in this test.
        IntegrationCredential::withTrashed()->forceDelete();
    }
})->with('couriers');

it('refuses before any request leaves HQ when no credential scope is open', function (array $case) {
    if (! $case['real']) {
        expect(true)->toBeTrue();

        return;
    }
    Http::fake();

    expect(fn () => app($case['class'])->track('laladia', $case['ref']))->toThrow(\App\Commerce\Exceptions\Unsupported::class);
    Http::assertNothingSent();
})->with('couriers');

it('maps courier wording to one vocabulary and never guesses delivered', function (string $raw, ParcelStatus $expected) {
    expect(ParcelStatus::fromCourierText($raw))->toBe($expected);
})->with([
    ['Delivered', ParcelStatus::Delivered], ['partial_delivered', ParcelStatus::Delivered], ['delivered_approval_pending', ParcelStatus::InTransit],
    ['Out For Delivery', ParcelStatus::OutForDelivery], ['delivery-in-progress', ParcelStatus::OutForDelivery], ['Delivery_Failed', ParcelStatus::OnHold],
    ['agent-hold', ParcelStatus::OnHold], ['Return', ParcelStatus::Returned], ['cancelled', ParcelStatus::Cancelled], ['in_review', ParcelStatus::Created],
    ['Pickup Requested', ParcelStatus::Created], ['Picked Up', ParcelStatus::PickedUp], ['At the Sorting HUB', ParcelStatus::InTransit], ['something new', ParcelStatus::Unknown], ['', ParcelStatus::Unknown],
]);

it('echoes the integration secret in the reply for the couriers that want it', function () {
    expect(app(PathaoCourier::class)->handshakeHeaders('abc'))->toBe(['X-Pathao-Merchant-Webhook-Integration-Secret' => 'abc'])
        ->and(app(PathaoCourier::class)->receiveWebhook(json_encode(['event' => 'webhook_integration']), ['x-pathao-signature' => M3_SECRET], M3_SECRET))->toBeNull();
});
