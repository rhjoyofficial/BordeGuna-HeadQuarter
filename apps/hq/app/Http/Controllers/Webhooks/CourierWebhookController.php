<?php

namespace App\Http\Controllers\Webhooks;

use App\Connectors\Capabilities;
use App\Connectors\ConnectorRegistry;
use App\Courier\CourierConnector;
use App\Courier\CourierWebhookHandshake;
use App\Courier\Exceptions\InvalidCourierWebhook;
use App\Courier\ParcelLedger;
use App\Gateway\Credentials\CredentialVault;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Observe\Alerts\WebhookSignatureFailures;
use App\Support\Features\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Where a courier's status webhooks arrive: `POST /webhooks/courier/{provider}/{business}` (docs/144-courier-connectors).
 *
 * Fail closed at every step, and each step answers as little as possible:
 *  - the switch `connectors.courier` is off, the courier or the business is unknown, or no connector is registered and on
 *    for that courier: 404 (the URL does not exist);
 *  - no webhook secret stored for that business and courier: 503 (not configured, nothing is read);
 *  - a missing or wrong secret: 401, counted for the signature-failure alert, and the body is never parsed;
 *  - an authentic call is recorded in HQ's own parcel model, idempotently. Nothing reaches the ERP.
 *
 * The secret is `courier_webhook_secret` under the account `<business slug>.<courier>`. Pathao-style couriers get their
 * pairing secret echoed back in the reply, on an authentic call only.
 */
final class CourierWebhookController extends Controller
{
    public const SECRET_PROVIDER = 'courier_webhook_secret';

    public function __construct(
        private readonly Features $features,
        private readonly CredentialVault $vault,
        private readonly ConnectorRegistry $registry,
        private readonly ParcelLedger $ledger,
    ) {}

    public function receive(Request $request, string $provider, string $business): JsonResponse
    {
        $capability = Capabilities::COURIER_TRACK_BY_PROVIDER[$provider] ?? null;
        $biz = Business::query()->where('slug', $business)->first();
        if ($capability === null || $biz === null || ! $this->features->enabled('connectors.courier', $biz->id)) {
            return response()->json(['error' => 'not found'], 404);
        }

        // Any connector registered for this courier and switched on (the webhook needs the secret, not the tracking credential).
        $definition = collect($this->registry->for($capability))->first(fn ($d) => $this->registry->enabled($d, $biz->id));
        if ($definition === null) {
            return response()->json(['error' => 'not found'], 404);
        }
        $connector = app($definition->adapter);
        if (! $connector instanceof CourierConnector) {
            return response()->json(['error' => 'not found'], 404);
        }

        $account = "{$business}.{$provider}";
        $secret = $this->vault->openForAccounts([[self::SECRET_PROVIDER, $account]], fn () => $this->vault->optional(self::SECRET_PROVIDER, $account));
        if ($secret === null) {
            Log::warning('courier webhook received but no secret is stored', ['provider' => $provider, 'business' => $business]);

            return response()->json(['error' => 'webhook not configured'], 503);
        }

        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower($name)] = (string) ($values[0] ?? '');
        }
        if (! isset($headers['x-hq-webhook-token']) && $request->query('token') !== null) {
            $headers['x-hq-webhook-token'] = (string) $request->query('token');
        }

        try {
            $snapshot = $connector->receiveWebhook($request->getContent(), $headers, $secret);
        } catch (InvalidCourierWebhook) {
            Log::warning('courier webhook rejected', ['provider' => $provider, 'business' => $business, 'ip' => $request->ip()]);
            app(WebhookSignatureFailures::class)->record('courier:'.$provider);

            return response()->json(['error' => 'invalid webhook'], 401);
        }

        $reply = $connector instanceof CourierWebhookHandshake ? $connector->handshakeHeaders($secret) : [];
        if ($snapshot === null) {
            return response()->json(['status' => 'ignored'], 202, $reply);
        }
        $this->ledger->record($biz, $snapshot, 'webhook');

        return response()->json(['status' => 'recorded'], 200, $reply);
    }
}
