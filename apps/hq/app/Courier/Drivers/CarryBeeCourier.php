<?php

namespace App\Courier\Drivers;

use App\Courier\CourierWebhookHandshake;
use App\Courier\Exceptions\CourierRefused;
use App\Courier\Exceptions\InvalidCourierWebhook;
use App\Courier\ParcelEvent;
use App\Courier\ParcelSnapshot;
use App\Courier\ParcelStatus;
use Carbon\CarbonImmutable;

/**
 * CarryBee. ⚠ The least certain of the four: the merchant API is documented to merchants only, so the call
 * shapes below follow the CarryBee integration the ERP's courier hub already runs (client id, client secret and
 * client context headers; an order-details read by consignment id; a webhook whose reply echoes the merchant's
 * integration secret) and are all configuration (`hq.courier.carrybee`: base URL, `track_path`). They are
 * PROVEN only against a CarryBee sandbox or live merchant account, which is the first step when the keys
 * arrive (`docs/144-courier-connectors.md`); until then the contract tests prove HQ's own behaviour against
 * these shapes, not CarryBee's.
 *
 * Credential kind `carrybee_courier_secret`: a JSON object
 * `{"client_id": "...", "client_secret": "...", "client_context": "..."}`.
 */
final class CarryBeeCourier extends AbstractHttpCourier implements CourierWebhookHandshake
{
    public const PROVIDER = 'carrybee';

    public const CREDENTIAL = 'carrybee_courier_secret';

    public function key(): string
    {
        return self::PROVIDER;
    }

    protected function credentialKind(): string
    {
        return self::CREDENTIAL;
    }

    public function track(string $accountRef, string $consignmentRef): ParcelSnapshot
    {
        $c = $this->credential($accountRef, ['client_id', 'client_secret', 'client_context']);
        $path = (string) config('hq.courier.carrybee.track_path', '/api/v2/orders/{ref}/details');
        $url = $this->baseUrl().str_replace('{ref}', rawurlencode($consignmentRef), $path);

        $response = $this->guard(fn () => $this->pending()->withHeaders([
            'Client-ID' => $c['client_id'], 'Client-Secret' => $c['client_secret'], 'Client-Context' => $c['client_context'],
        ])->get($url));

        $raw = self::text($response->json('data.transfer_status') ?? $response->json('data.status'));
        if ($raw === null) {
            throw new CourierRefused('carrybee: the answer has no status.');
        }
        $at = self::time($response->json('data.updated_at'));
        $status = ParcelStatus::fromCourierText($raw);

        return new ParcelSnapshot(self::PROVIDER, $consignmentRef, $status, $raw, [
            new ParcelEvent($at ?? CarbonImmutable::now(), $status, $raw, null, timed: $at !== null),
        ], self::text($response->json('data.merchant_order_id')));
    }

    public function receiveWebhook(string $rawBody, array $headers, string $secret): ?ParcelSnapshot
    {
        $this->requireSecret($headers['x-carrybee-webhook-signature'] ?? $headers['x-hq-webhook-token'] ?? null, $secret);
        $body = $this->jsonBody($rawBody);

        if (in_array($body['event'] ?? null, ['webhook.test', 'webhook_integration'], true)) {
            return null;
        }
        $ref = self::text($body['consignment_id'] ?? null);
        $raw = self::text($body['transfer_status'] ?? $body['status'] ?? $body['event'] ?? null);
        if ($ref === null || $raw === null) {
            throw new InvalidCourierWebhook('carrybee: the webhook has no consignment_id or status.');
        }
        $at = self::time($body['updated_at'] ?? $body['timestamp'] ?? null);
        $status = ParcelStatus::fromCourierText($raw);

        return new ParcelSnapshot(self::PROVIDER, $ref, $status, $raw, [
            new ParcelEvent($at ?? CarbonImmutable::now(), $status, $raw, null, timed: $at !== null),
        ], self::text($body['merchant_order_id'] ?? null));
    }

    public function handshakeHeaders(string $secret): array
    {
        return ['X-Carrybee-Webhook-Integration-Secret' => $secret];
    }
}
