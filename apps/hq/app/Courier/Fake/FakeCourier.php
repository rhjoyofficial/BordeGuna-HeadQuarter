<?php

namespace App\Courier\Fake;

use App\Courier\CourierConnector;
use App\Courier\Exceptions\CourierRefused;
use App\Courier\Exceptions\CourierUnavailable;
use App\Courier\Exceptions\InvalidCourierWebhook;
use App\Courier\ParcelEvent;
use App\Courier\ParcelSnapshot;
use App\Courier\ParcelStatus;
use Carbon\CarbonImmutable;

/**
 * A courier that exists only inside HQ, for contract tests, the sandbox switch and a first look at the
 * screens before any courier account is connected. It reaches nothing and needs no credential.
 *
 * The consignment reference chooses the story, so a test or a person trying the screen can ask for any:
 * `FAKE-DELIVERED-..`, `FAKE-TRANSIT-..`, `FAKE-RETURNED-..`; `FAKE-UNKNOWN-..` is refused; `FAKE-DOWN-..`
 * is reported unavailable. Any other reference is refused as unknown, exactly as a courier refuses a number
 * it has never seen. The webhook secret is the literal `fake-secret`, which is no credential of anything.
 */
abstract class FakeCourier implements CourierConnector
{
    public const SECRET = 'fake-secret';

    public function track(string $accountRef, string $consignmentRef): ParcelSnapshot
    {
        $at = CarbonImmutable::parse('2026-10-01 09:00:00', 'UTC');
        $story = match (true) {
            str_starts_with($consignmentRef, 'FAKE-DOWN') => throw new CourierUnavailable($this->key().': the fake courier is down.'),
            str_starts_with($consignmentRef, 'FAKE-DELIVERED') => ['Pending', 'Picked Up', 'In Transit', 'Out For Delivery', 'Delivered'],
            str_starts_with($consignmentRef, 'FAKE-TRANSIT') => ['Pending', 'Picked Up', 'In Transit'],
            str_starts_with($consignmentRef, 'FAKE-RETURNED') => ['Pending', 'Picked Up', 'On Hold', 'Returned'],
            default => throw new CourierRefused($this->key().': the fake courier does not know this consignment.'),
        };

        $events = [];
        foreach ($story as $i => $raw) {
            $events[] = new ParcelEvent($at->addHours($i * 6), ParcelStatus::fromCourierText($raw), $raw, null);
        }
        $last = $events[array_key_last($events)];

        return new ParcelSnapshot($this->key(), $consignmentRef, $last->status, $last->rawStatus, $events, 'FAKE-ORDER-1');
    }

    public function receiveWebhook(string $rawBody, array $headers, string $secret): ?ParcelSnapshot
    {
        $given = $headers['x-hq-webhook-token'] ?? null;
        if ($secret === '' || $given === null || ! hash_equals($secret, $given)) {
            throw new InvalidCourierWebhook($this->key().': the webhook secret is missing or wrong.');
        }
        $body = json_decode($rawBody, true);
        if (! is_array($body) || ! is_string($body['consignment_id'] ?? null) || ! is_string($body['status'] ?? null)) {
            throw new InvalidCourierWebhook($this->key().': the webhook is not a status change.');
        }
        $status = ParcelStatus::fromCourierText($body['status']);

        return new ParcelSnapshot($this->key(), $body['consignment_id'], $status, $body['status'], [
            new ParcelEvent(CarbonImmutable::parse((string) ($body['updated_at'] ?? '2026-10-02 10:00:00'), 'UTC'), $status, $body['status']),
        ], is_string($body['merchant_order_id'] ?? null) ? $body['merchant_order_id'] : null);
    }
}
