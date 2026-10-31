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
 * Pathao Courier (merchant API, "Hermes"). The token call is `POST /aladdin/api/v1/issue-token` (password
 * grant with the merchant's client id and secret); the tracking call is
 * `GET /aladdin/api/v1/orders/{consignment_id}/info` with `Authorization: Bearer <access token>`, answering
 * `{"data": {"consignment_id": "...", "order_status": "...", "updated_at": "..."}}`. Webhooks carry the merchant's
 * integration secret in `X-PATHAO-Signature`, a test ping has the event `webhook_integration`, and the reply
 * must send the same secret back in `X-Pathao-Merchant-Webhook-Integration-Secret`.
 *
 * ⚠ The token is fetched per call and never cached: it is a credential, and polling is small and rare.
 *
 * Credential kind `pathao_courier_secret`: a JSON object
 * `{"client_id": "...", "client_secret": "...", "username": "...", "password": "..."}`.
 */
final class PathaoCourier extends AbstractHttpCourier implements CourierWebhookHandshake
{
    public const PROVIDER = 'pathao';

    public const CREDENTIAL = 'pathao_courier_secret';

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
        $c = $this->credential($accountRef, ['client_id', 'client_secret', 'username', 'password']);
        $base = $this->baseUrl();

        $token = $this->guard(fn () => $this->pending()->post($base.(string) config('hq.courier.pathao.token_path', '/aladdin/api/v1/issue-token'), [
            'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'],
            'username' => $c['username'], 'password' => $c['password'], 'grant_type' => 'password',
        ]))->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new CourierRefused('pathao: the courier issued no access token.');
        }

        $path = (string) config('hq.courier.pathao.track_path', '/aladdin/api/v1/orders/{ref}/info');
        $response = $this->guard(fn () => $this->pending()->withToken($token)->get($base.str_replace('{ref}', rawurlencode($consignmentRef), $path)));

        $raw = self::text($response->json('data.order_status'));
        if ($raw === null) {
            throw new CourierRefused('pathao: the answer has no order_status.');
        }
        $at = self::time($response->json('data.updated_at'));
        $status = ParcelStatus::fromCourierText($raw);

        return new ParcelSnapshot(self::PROVIDER, $consignmentRef, $status, $raw, [
            new ParcelEvent($at ?? CarbonImmutable::now(), $status, $raw, null, timed: $at !== null),
        ], self::text($response->json('data.merchant_order_id')));
    }

    public function receiveWebhook(string $rawBody, array $headers, string $secret): ?ParcelSnapshot
    {
        $this->requireSecret($headers['x-pathao-signature'] ?? $headers['x-hq-webhook-token'] ?? null, $secret);
        $body = $this->jsonBody($rawBody);

        if (($body['event'] ?? null) === 'webhook_integration') {
            return null; // the courier's own test ping while the merchant pairs the webhook
        }
        $ref = self::text($body['consignment_id'] ?? null);
        $raw = self::text($body['order_status'] ?? $body['event'] ?? null);
        if ($ref === null || $raw === null) {
            throw new InvalidCourierWebhook('pathao: the webhook has no consignment_id or order_status.');
        }
        $at = self::time($body['updated_at'] ?? $body['timestamp'] ?? null);
        $status = ParcelStatus::fromCourierText($raw);

        return new ParcelSnapshot(self::PROVIDER, $ref, $status, $raw, [
            new ParcelEvent($at ?? CarbonImmutable::now(), $status, $raw, null, timed: $at !== null),
        ], self::text($body['merchant_order_id'] ?? null));
    }

    public function handshakeHeaders(string $secret): array
    {
        return ['X-Pathao-Merchant-Webhook-Integration-Secret' => $secret];
    }
}
