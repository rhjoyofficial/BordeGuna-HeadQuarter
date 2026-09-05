<?php

namespace App\Erp;

use App\Commerce\Exceptions\Unsupported;
use App\Commerce\Support\ConnectorCallLogger;
use App\Models\Business;
use App\Models\IntegrationCredential;

/**
 * Resolves an HQ business to its ERP connector.
 *
 * The token lives in integration_credentials as provider `erp_api_token`, account_ref = HQ business
 * slug, payload {value}. It is issued on the ERP by `php artisan hq:api:token` for that one business
 * and pasted here through the encrypted store; it is never in config or env.
 */
final class ErpRegistry
{
    public const PROVIDER = 'erp_api_token';

    public function __construct(private readonly ConnectorCallLogger $logger) {}

    public function for(Business|string $business): ErpConnector
    {
        $business = $business instanceof Business ? $business : Business::where('slug', $business)->firstOrFail();

        if ($business->erp_business_id === null) {
            throw new Unsupported("Business {$business->slug} has no ERP counterpart (erp_business_id is null).");
        }

        $token = IntegrationCredential::query()
            ->where('provider', self::PROVIDER)
            ->where('account_ref', $business->slug)
            ->where('status', 'active')
            ->first()?->payload['value'] ?? null;

        if (! $token) {
            throw new Unsupported('No active '.self::PROVIDER." credential for business {$business->slug}. Issue one on the ERP with hq:api:token and store it in integration_credentials.");
        }

        return new ErpConnector(
            businessSlug: $business->slug,
            erpBusinessId: (int) $business->erp_business_id,
            baseUrl: (string) config('hq.erp.base_url'),
            token: $token,
            logger: $this->logger,
            timeoutSeconds: (int) config('hq.erp.timeout_seconds', 15),
        );
    }

    /** @return string[] slugs of businesses that have an ERP counterpart AND a stored token */
    public function businessesWithConnectors(): array
    {
        $withToken = IntegrationCredential::query()->where('provider', self::PROVIDER)->where('status', 'active')->pluck('account_ref')->all();

        return Business::query()->whereNotNull('erp_business_id')->whereIn('slug', $withToken)->orderBy('slug')->pluck('slug')->all();
    }
}
