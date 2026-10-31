<?php

namespace App\Courier\Drivers;

use App\Courier\Exceptions\CourierRefused;
use App\Courier\Exceptions\InvalidCourierWebhook;
use App\Courier\ParcelEvent;
use App\Courier\ParcelSnapshot;
use App\Courier\ParcelStatus;
use Carbon\CarbonImmutable;

/**
 * Steadfast Courier (Packzy merchant API). Read from the published API: `GET /status_by_cid/{consignment_id}`
 * with `Api-Key` and `Secret-Key` headers answers `{"status":200,"delivery_status":"in_review"}`; webhooks
 * authenticate with `Authorization: Bearer <merchant API key>` and carry `consignment_id`, `invoice`,
 * `status` and `tracking_message`. The paths and the host are configuration (`hq.courier.steadfast`), so a
 * change of the courier's side is a config change; `docs/144-courier-connectors.md` lists what is still to
 * be proven against a live merchant account.
 *
 * Credential kind `steadfast_courier_secret`: a JSON object `{"api_key": "...", "secret_key": "..."}`.
 */
final class SteadfastCourier extends AbstractHttpCourier
{
    public const PROVIDER = 'steadfast';

    public const CREDENTIAL = 'steadfast_courier_secret';

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
        $credential = $this->credential($accountRef, ['api_key', 'secret_key']);
        $path = (string) config('hq.courier.steadfast.track_path', '/status_by_cid/{ref}');
        $url = $this->baseUrl().str_replace('{ref}', rawurlencode($consignmentRef), $path);

        $response = $this->guard(fn () => $this->pending()
            ->withHeaders(['Api-Key' => $credential['api_key'], 'Secret-Key' => $credential['secret_key']])
            ->get($url));

        $raw = self::text($response->json('delivery_status'));
        if ($raw === null) {
            throw new CourierRefused('steadfast: the answer has no delivery_status.');
        }

        return $this->snapshot($consignmentRef, $raw, null, null);
    }

    public function receiveWebhook(string $rawBody, array $headers, string $secret): ?ParcelSnapshot
    {
        $this->requireSecret(
            str_starts_with((string) ($headers['authorization'] ?? ''), 'Bearer ') ? substr((string) $headers['authorization'], 7) : ($headers['x-hq-webhook-token'] ?? null),
            $secret,
        );
        $body = $this->jsonBody($rawBody);

        if (($body['notification_type'] ?? 'delivery_status') !== 'delivery_status') {
            return null; // a balance or other notice: authentic, nothing to record
        }
        $ref = self::text($body['consignment_id'] ?? null);
        $raw = self::text($body['status'] ?? null);
        if ($ref === null || $raw === null) {
            throw new InvalidCourierWebhook('steadfast: the webhook has no consignment_id or status.');
        }

        return $this->snapshot($ref, $raw, self::text($body['tracking_message'] ?? null), self::time($body['updated_at'] ?? null), self::text($body['invoice'] ?? null));
    }

    private function snapshot(string $ref, string $raw, ?string $message, ?CarbonImmutable $at, ?string $merchantRef = null): ParcelSnapshot
    {
        $status = ParcelStatus::fromCourierText($raw);

        return new ParcelSnapshot(self::PROVIDER, $ref, $status, $raw, [
            new ParcelEvent($at ?? CarbonImmutable::now(), $status, $raw, $message, timed: $at !== null),
        ], $merchantRef);
    }
}
