<?php

namespace App\Tools\Connectors;

use App\Courier\CourierTracker;
use App\Courier\Drivers\CarryBeeCourier;
use App\Courier\Drivers\PathaoCourier;
use App\Courier\Drivers\RedxCourier;
use App\Courier\Drivers\SteadfastCourier;
use App\Courier\Exceptions\CourierRefused;
use App\Courier\Exceptions\CourierUnavailable;
use App\Gateway\OutcomeClass;
use App\Models\Business;
use App\Tools\ToolContext;
use App\Tools\ToolResult;

/**
 * `courier.track_parcel`: ask the courier where one consignment is and record it in HQ's parcel read model
 * (docs/144-courier-connectors). Reads at the courier, writes only HQ's own rows, books and changes nothing, and
 * never touches the ERP.
 */
final class TrackParcelTool extends ConnectorTool
{
    public function key(): string
    {
        return 'courier.track_parcel';
    }

    protected function title(): string
    {
        return 'Track a parcel';
    }

    protected function purpose(): string
    {
        return 'Asks the courier that carries one consignment where it is and records the answer in HQ\'s own parcel timeline. '
            .'Reads at the courier; books, cancels and pays nothing; never writes to the ERP.';
    }

    protected function flag(): string
    {
        return 'connectors.courier';
    }

    protected function permission(): string
    {
        return 'hq.tools.courier.read';
    }

    protected function credentialSources(): array
    {
        $business = 'args.business_slug|business.slug';

        return [PathaoCourier::CREDENTIAL => $business, CarryBeeCourier::CREDENTIAL => $business, SteadfastCourier::CREDENTIAL => $business, RedxCourier::CREDENTIAL => $business];
    }

    protected function arguments(): array
    {
        return [
            'courier' => ['type' => 'string', 'enum' => array_keys(\App\Connectors\Capabilities::COURIER_TRACK_BY_PROVIDER)],
            'consignment_ref' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 96],
        ];
    }

    protected function required(): array
    {
        return ['courier', 'consignment_ref'];
    }

    protected function idempotent(): bool
    {
        return false; // tracking the same parcel again is how it is kept fresh
    }

    protected function run(?Business $business, array $payload, ToolContext $context): ToolResult
    {
        if (preg_match('/^[A-Za-z0-9._\-]+$/', (string) $payload['consignment_ref']) !== 1) {
            return ToolResult::failed('A consignment number is letters, digits, dots, dashes and underscores.', [], OutcomeClass::InvalidArguments);
        }
        try {
            $parcel = app(CourierTracker::class)->track($business, (string) $payload['courier'], (string) $payload['consignment_ref']);
        } catch (CourierRefused $e) {
            return ToolResult::failed($e->getMessage(), ['connector_state' => 'refused'], OutcomeClass::ConnectorError);
        } catch (CourierUnavailable $e) {
            return ToolResult::failed($e->getMessage(), ['connector_state' => 'unavailable'], OutcomeClass::ProviderUnavailable);
        }

        return ToolResult::succeeded([
            'parcel_uuid' => $parcel->uuid, 'provider' => $parcel->provider, 'consignment_ref' => $parcel->consignment_ref,
            'status' => $parcel->status, 'raw_status' => $parcel->raw_status, 'terminal' => $parcel->terminal,
            'events' => $parcel->events()->count(), 'linked_order' => $parcel->facts_order_id !== null,
        ]);
    }
}
