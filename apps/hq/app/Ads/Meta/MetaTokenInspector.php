<?php

namespace App\Ads\Meta;

use App\Gateway\Credentials\CredentialVault;
use App\Http\Controllers\Webhooks\MetaWebhookController;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Asks Meta about a stored token without using it for anything else: `GET /debug_token?input_token=<token>` with the
 * app access token `<app id>|<app secret>` answers `{"data": {"is_valid": true, "expires_at": 0, "scopes": [...], "type": "..."}}`
 * (`expires_at` 0 means the token does not expire). The token screen and the `meta.check_token_health` tool use it
 * (docs/144-meta-readiness). Read-only at Meta; the app secret and the inspected token come only from the open vault scope
 * and appear in no message.
 */
final class MetaTokenInspector
{
    public function __construct(private readonly CredentialVault $vault) {}

    /**
     * @return array{valid: bool, expires_at: ?CarbonImmutable, scopes: list<string>, type: ?string}
     *
     * @throws RuntimeException when the inspection could not be done (no app id or secret, Meta unreachable, an unreadable answer)
     */
    public function inspect(string $tokenKind, string $accountRef): array
    {
        $appId = (string) config('hq.meta_health.app_id', '');
        $secret = $this->vault->optional(MetaWebhookController::SECRET_PROVIDER, MetaWebhookController::ACCOUNT_REF);
        $token = $this->vault->optional($tokenKind, $accountRef);
        if ($appId === '' || $secret === null) {
            throw new RuntimeException('The Meta app id (HQ_META_APP_ID) and the app secret credential are needed to inspect a token.');
        }
        if ($token === null) {
            throw new RuntimeException('No such token is stored.');
        }

        try {
            $response = Http::acceptJson()->timeout((int) config('hq.ads.meta.timeout_seconds', 20))
                ->get(rtrim((string) config('hq.ads.meta.base_url'), '/').'/'.config('hq.ads.meta.graph_version').'/debug_token', [
                    'input_token' => $token, 'access_token' => $appId.'|'.$secret,
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Meta could not be reached.');
        } catch (Throwable $e) {
            throw new RuntimeException('Meta inspection failed: '.$e::class);
        }
        if ($response->failed() || ! is_array($response->json('data'))) {
            throw new RuntimeException('Meta did not answer with a token description ('.$response->status().').');
        }

        $expires = (int) ($response->json('data.expires_at') ?? 0);

        return [
            'valid' => $response->json('data.is_valid') === true,
            'expires_at' => $expires > 0 ? CarbonImmutable::createFromTimestamp($expires) : null,
            'scopes' => array_values(array_filter((array) $response->json('data.scopes', []), 'is_string')),
            'type' => is_string($response->json('data.type')) ? $response->json('data.type') : null,
        ];
    }
}
