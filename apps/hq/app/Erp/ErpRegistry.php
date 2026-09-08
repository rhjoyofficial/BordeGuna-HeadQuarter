<?php

namespace App\Erp;

use App\Commerce\Exceptions\Unsupported;
use App\Commerce\Support\ConnectorCallLogger;
use App\Gateway\Credentials\CredentialVault;
use App\Models\Business;

/**
 * Resolves an HQ business to its ERP connector.
 *
 * The token lives in integration_credentials as provider `erp_api_token`, account_ref = HQ business
 * slug. It is issued on the ERP by `php artisan hq:api:token` for that one business and pasted here
 * through the encrypted store; it is never in config or env.
 *
 * ⚠ M2: this class no longer LOOKS UP the token. The gateway resolves what the tool declared and
 * opens it in the CredentialVault for the duration of the call, and this asks the vault. A connector
 * that resolved its own credential was precisely the gap that let a secret reach an error message
 * without the gateway ever having seen the value (docs/50, closing the M1 limitation).
 */
final class ErpRegistry
{
    public const PROVIDER = 'erp_api_token';

    public function __construct(
        private readonly ConnectorCallLogger $logger,
        private readonly CredentialVault $vault,
    ) {}

    public function for(Business|string $business): ErpConnector
    {
        $business = $business instanceof Business ? $business : Business::where('slug', $business)->firstOrFail();

        if ($business->erp_business_id === null) {
            throw new Unsupported("Business {$business->slug} has no ERP counterpart (erp_business_id is null).");
        }

        // Throws a clear Unsupported when the gateway did not open this credential, which is the
        // point: an undeclared credential fails loudly instead of falling back to a second lookup.
        $token = $this->vault->secret(self::PROVIDER, $business->slug);

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
        // Metadata only: which businesses have a token, never what the token is.
        $withToken = $this->vault->accountsFor(self::PROVIDER);

        return Business::query()->whereNotNull('erp_business_id')->whereIn('slug', $withToken)->orderBy('slug')->pluck('slug')->all();
    }
}
