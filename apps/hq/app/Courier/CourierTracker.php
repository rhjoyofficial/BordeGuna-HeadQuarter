<?php

namespace App\Courier;

use App\Connectors\Capabilities;
use App\Connectors\ConnectorRegistry;
use App\Connectors\ConnectorRequest;
use App\Connectors\ConnectorResolution;
use App\Courier\Exceptions\CourierRefused;
use App\Courier\Exceptions\CourierUnavailable;
use App\Models\Business;
use App\Models\CourierParcel;

/**
 * Tracks parcels through whichever courier connector the registry says is ready (docs/144-courier-connectors).
 *
 * It asks the registry for the capability of the PARCEL's courier (`courier.<provider>.track`); it never
 * names a driver. It must run inside a gateway call that opened the courier credential (the drivers read
 * their secret only from the open vault scope), and nothing calls it from anywhere else.
 */
final class CourierTracker
{
    /** A parcel is not polled again within this many minutes. */
    public const POLL_INTERVAL_MINUTES = 15;

    public function __construct(private readonly ConnectorRegistry $registry, private readonly ParcelLedger $ledger) {}

    public static function capabilityFor(string $provider): ?string
    {
        return Capabilities::COURIER_TRACK_BY_PROVIDER[$provider] ?? null;
    }

    public function resolve(Business $business, string $provider): ConnectorResolution
    {
        $capability = self::capabilityFor($provider);

        return $capability === null
            ? ConnectorResolution::unknown("courier.{$provider}.track", "[{$provider}] is not a courier HQ knows.")
            : $this->registry->resolve(new ConnectorRequest($capability, $business->id));
    }

    /**
     * @throws CourierRefused when no connector is ready for this courier, or the courier refuses
     * @throws CourierUnavailable when the courier cannot be reached
     */
    public function track(Business $business, string $provider, string $consignmentRef): CourierParcel
    {
        $resolution = $this->resolve($business, $provider);
        if (! $resolution->resolvedOk()) {
            throw new CourierRefused("No {$provider} connector is ready for {$business->slug}: ".($resolution->reason ?? $resolution->outcome->value));
        }
        $connector = $this->registry->adapter($resolution);
        if (! $connector instanceof CourierConnector) {
            throw new CourierRefused("The {$provider} connector is not a courier connector.");
        }

        $snapshot = $connector->track($business->slug, $consignmentRef);

        return $this->ledger->record($business, $snapshot, 'poll');
    }

    /**
     * Poll the business's open parcels that are due. One courier that is down or refuses does not stop the rest.
     *
     * @return array{polled: int, failed: int, skipped: int}
     */
    public function pollDue(Business $business, int $limit = 25): array
    {
        $due = CourierParcel::query()->where('business_id', $business->id)->where('terminal', false)
            ->where(fn ($q) => $q->whereNull('last_polled_at')->orWhere('last_polled_at', '<', now()->subMinutes(self::POLL_INTERVAL_MINUTES)))
            ->orderBy('last_polled_at')->limit($limit)->get();

        $polled = $failed = $skipped = 0;
        foreach ($due as $parcel) {
            if (! $this->resolve($business, $parcel->provider)->resolvedOk()) {
                $skipped++;

                continue;
            }
            try {
                $this->track($business, $parcel->provider, $parcel->consignment_ref);
                $polled++;
            } catch (CourierRefused|CourierUnavailable) {
                $this->ledger->pollFailed($parcel);
                $failed++;
            }
        }

        return ['polled' => $polled, 'failed' => $failed, 'skipped' => $skipped];
    }
}
