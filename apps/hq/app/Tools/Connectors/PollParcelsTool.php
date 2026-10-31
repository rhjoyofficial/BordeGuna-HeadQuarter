<?php

namespace App\Tools\Connectors;

use App\Courier\CourierTracker;
use App\Courier\Drivers\CarryBeeCourier;
use App\Courier\Drivers\PathaoCourier;
use App\Courier\Drivers\RedxCourier;
use App\Courier\Drivers\SteadfastCourier;
use App\Models\Business;
use App\Tools\ToolContext;
use App\Tools\ToolResult;

/**
 * `courier.poll_parcels`: poll a business's open parcels that are due (not within the last 15 minutes). One courier
 * being down or refusing never stops the rest; a parcel that keeps failing is counted on its row. Not scheduled by
 * default: a scheduled job is filed by a person, through the scheduler, like any other (docs/144-courier-connectors).
 */
final class PollParcelsTool extends ConnectorTool
{
    public function key(): string
    {
        return 'courier.poll_parcels';
    }

    protected function title(): string
    {
        return 'Poll open parcels';
    }

    protected function purpose(): string
    {
        return 'Asks each courier about the business\'s parcels that are not yet delivered, returned or cancelled and are due for a check, '
            .'and records the answers in HQ\'s own timeline. Reads at the couriers; books nothing; never writes to the ERP.';
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
        return ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]];
    }

    protected function timeoutSeconds(): int
    {
        return 120;
    }

    protected function run(?Business $business, array $payload, ToolContext $context): ToolResult
    {
        return ToolResult::succeeded(app(CourierTracker::class)->pollDue($business, (int) ($payload['limit'] ?? 25)));
    }
}
