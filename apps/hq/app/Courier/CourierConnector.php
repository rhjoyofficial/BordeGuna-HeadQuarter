<?php

namespace App\Courier;

use App\Courier\Exceptions\CourierRefused;
use App\Courier\Exceptions\CourierUnavailable;
use App\Courier\Exceptions\InvalidCourierWebhook;

/**
 * Where a parcel is, from a courier (capabilities `courier.<provider>.track`, docs/144-courier-connectors).
 *
 * ⛔ **Reads and listens; books nothing.** Booking, cancelling and payout stay in the ERP, which owns the
 * courier relationship (the Courier hub). HQ's only effect is its own read model of where parcels are.
 *
 * An implementation authenticates only through the `CredentialVault` scope the caller opened, so with no
 * scope open it refuses before any request leaves HQ. Its answers are third-party and untrusted data.
 *
 * ⭐ **Contract tests** (`tests/Feature/Courier/CourierContractTest`): every driver, the fake included,
 * must answer a known parcel with at least one event, refuse an unknown parcel as `CourierRefused`,
 * report a down courier as `CourierUnavailable`, and reject a webhook with a wrong or missing secret
 * before reading its body.
 */
interface CourierConnector
{
    /** Lower-case provider key: `pathao`, `carrybee`, `steadfast`, `redx`. */
    public function key(): string;

    /**
     * @param  string  $accountRef  the business slug the credential is stored under
     *
     * @throws CourierRefused the courier does not know this consignment, or refused the credentials
     * @throws CourierUnavailable the courier could not be reached or failed on its side
     */
    public function track(string $accountRef, string $consignmentRef): ParcelSnapshot;

    /**
     * Verify an incoming webhook against the shared secret and read it. Fail closed: a missing or wrong
     * secret throws before the body is parsed, and a body that is not a status change throws too.
     *
     * @param  array<string, string>  $headers  lower-case header name => value
     * @return ParcelSnapshot|null null when the call is authentic but carries nothing to record (a courier's test ping)
     *
     * @throws InvalidCourierWebhook
     */
    public function receiveWebhook(string $rawBody, array $headers, string $secret): ?ParcelSnapshot;
}
