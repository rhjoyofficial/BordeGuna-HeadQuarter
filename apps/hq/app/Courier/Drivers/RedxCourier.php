<?php

namespace App\Courier\Drivers;

use App\Courier\Exceptions\CourierRefused;
use App\Courier\Exceptions\InvalidCourierWebhook;
use App\Courier\ParcelEvent;
use App\Courier\ParcelSnapshot;
use App\Courier\ParcelStatus;
use Carbon\CarbonImmutable;

/**
 * RedX. The tracking call is `GET /parcel/track/{tracking_id}` with the header `API-ACCESS-TOKEN: Bearer <token>`,
 * answering `{"tracking": [{"message_en": "...", "message_bn": "...", "time": "..."}]}`; the courier's webhook
 * carries `tracking_id`, `timestamp`, `status`, `message_en`, `message_bn` and `invoice_number` and documents no
 * signature, so HQ's webhook URL carries a secret that the courier sends back as `?token=` (the controller
 * hands it over as the `x-hq-webhook-token` header). Host and path are configuration (`hq.courier.redx`).
 *
 * Credential kind `redx_courier_token`: the API token as a plain string.
 */
final class RedxCourier extends AbstractHttpCourier
{
    public const PROVIDER = 'redx';

    public const CREDENTIAL = 'redx_courier_token';

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
        $token = $this->credential($accountRef);
        $path = (string) config('hq.courier.redx.track_path', '/parcel/track/{ref}');
        $url = $this->baseUrl().str_replace('{ref}', rawurlencode($consignmentRef), $path);

        $response = $this->guard(fn () => $this->pending()->withHeaders(['API-ACCESS-TOKEN' => 'Bearer '.$token])->get($url));

        $rows = $response->json('tracking');
        if (! is_array($rows) || $rows === []) {
            throw new CourierRefused('redx: the answer has no tracking lines.');
        }

        $events = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $message = self::text($row['message_en'] ?? $row['messageEn'] ?? null);
            $at = self::time($row['time'] ?? null);
            if ($message === null) {
                continue;
            }
            $events[] = new ParcelEvent($at ?? CarbonImmutable::now(), ParcelStatus::fromCourierText($message), $message, self::text($row['message_bn'] ?? $row['messageBn'] ?? null), timed: $at !== null);
        }
        if ($events === []) {
            throw new CourierRefused('redx: no tracking line was readable.');
        }
        usort($events, static fn (ParcelEvent $a, ParcelEvent $b) => $a->at <=> $b->at);
        $last = $events[array_key_last($events)];

        return new ParcelSnapshot(self::PROVIDER, $consignmentRef, $last->status, $last->rawStatus, $events);
    }

    public function receiveWebhook(string $rawBody, array $headers, string $secret): ?ParcelSnapshot
    {
        $this->requireSecret($headers['x-hq-webhook-token'] ?? null, $secret);
        $body = $this->jsonBody($rawBody);

        $ref = self::text($body['tracking_id'] ?? null);
        $raw = self::text($body['message_en'] ?? $body['status'] ?? null);
        if ($ref === null || $raw === null) {
            throw new InvalidCourierWebhook('redx: the webhook has no tracking_id or status.');
        }
        $at = self::time($body['timestamp'] ?? null);
        $status = ParcelStatus::fromCourierText($raw);

        return new ParcelSnapshot(self::PROVIDER, $ref, $status, $raw, [
            new ParcelEvent($at ?? CarbonImmutable::now(), $status, $raw, self::text($body['message_bn'] ?? null), timed: $at !== null),
        ], self::text($body['invoice_number'] ?? null));
    }
}
