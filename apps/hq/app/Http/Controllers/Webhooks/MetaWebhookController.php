<?php

namespace App\Http\Controllers\Webhooks;

use App\Gateway\Credentials\CredentialVault;
use App\Http\Controllers\Controller;
use App\Models\PlatformAccount;
use App\Observe\Alerts\WebhookSignatureFailures;
use App\Support\Events\EventRecorder;
use App\Support\Features\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * The only place Meta touches HQ (docs/72 §B): `GET` for the subscription handshake, `POST` for the
 * webhook. Both answer 404 while `channels.meta.inbound` is off, and the flag ships off.
 *
 * The POST verifies `X-Hub-Signature-256` over the raw body with the app secret opened through the
 * vault, writes ONE `events` row and answers 202. Nothing else happens in the request: the
 * normaliser and the conversation core run in the event drain, where a failure retries and a page
 * HQ does not hold dead-letters with its reason instead of being guessed at.
 */
final class MetaWebhookController extends Controller
{
    public const FLAG = 'channels.meta.inbound';

    public const SIGNATURE_HEADER = 'X-Hub-Signature-256';

    public const SECRET_PROVIDER = 'meta_app_secret';

    public const VERIFY_TOKEN_PROVIDER = 'meta_webhook_verify_token';

    public const ACCOUNT_REF = 'meta';

    /** The webhook envelope's `object` and the platform its `entry[].id` names. */
    public const PLATFORM_FOR_OBJECT = ['page' => 'facebook_page', 'instagram' => 'instagram_account'];

    public function __construct(
        private readonly EventRecorder $events,
        private readonly CredentialVault $vault,
        private readonly Features $features,
    ) {}

    /** Meta's one-time subscription handshake: echo the challenge when the verify token matches. */
    public function verify(Request $request): Response|JsonResponse
    {
        if (! $this->features->enabled(self::FLAG)) {
            return response()->json(['error' => 'not found'], 404);
        }

        $token = $this->vault->openForAccounts([[self::VERIFY_TOKEN_PROVIDER, self::ACCOUNT_REF]], fn () => $this->vault->optional(self::VERIFY_TOKEN_PROVIDER, self::ACCOUNT_REF));
        if ($token === null) {
            return response()->json(['error' => 'webhook not configured'], 503);
        }

        $given = (string) $request->query('hub_verify_token', '');
        if ($request->query('hub_mode') !== 'subscribe' || $given === '' || ! hash_equals($token, $given)) {
            return response()->json(['error' => 'verification failed'], 403);
        }

        return response((string) $request->query('hub_challenge', ''), 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request): JsonResponse
    {
        if (! $this->features->enabled(self::FLAG)) {
            return response()->json(['error' => 'not found'], 404);
        }

        // Inbound verification, so no gateway call to hang a credential scope on; still through the
        // vault, because the vault being the only place that decrypts is an invariant a test greps for.
        $secret = $this->vault->openForAccounts([[self::SECRET_PROVIDER, self::ACCOUNT_REF]], fn () => $this->vault->optional(self::SECRET_PROVIDER, self::ACCOUNT_REF));
        if ($secret === null) {
            Log::warning('meta webhook received but no app secret configured');

            return response()->json(['error' => 'webhook not configured'], 503);
        }

        $raw = $request->getContent();
        $given = (string) $request->header(self::SIGNATURE_HEADER, '');
        $expected = 'sha256='.hash_hmac('sha256', $raw, $secret);
        if ($given === '' || ! hash_equals($expected, $given)) {
            Log::warning('meta webhook signature mismatch', ['ip' => $request->ip()]);
            app(WebhookSignatureFailures::class)->record('meta');

            return response()->json(['error' => 'invalid signature'], 401);
        }

        $body = json_decode($raw, true);
        $object = is_array($body) ? ($body['object'] ?? null) : null;
        if (! is_array($body) || ! isset(self::PLATFORM_FOR_OBJECT[$object]) || ! is_array($body['entry'] ?? null)) {
            return response()->json(['error' => 'malformed envelope: expected {object: page|instagram, entry: [...]}'], 422);
        }

        $digest = hash('sha256', $raw);
        $pageId = is_array($body['entry'][0] ?? null) ? (string) ($body['entry'][0]['id'] ?? '') : '';
        // A Page's Messenger and its comments arrive under the Page; an Instagram account's DMs under
        // the account (docs/78 B.1). Either is a `platform_accounts` row HQ holds, or the event dead-letters.
        $businessId = $pageId === '' ? null : PlatformAccount::query()->where('platform', self::PLATFORM_FOR_OBJECT[$object])->where('account_ref', $pageId)->with('brand:id,business_id')->first()?->brand?->business_id;

        $event = $this->events->record(
            type: 'conversation.inbound_received',
            payload: $body + ['_digest' => $digest],
            source: 'meta:webhook',
            direction: 'inbound',
            aggregateType: 'conversation',
            aggregateId: $pageId !== '' ? $pageId : null,
            businessId: $businessId,
            dedupeKey: "meta:{$digest}",
        );

        return response()->json(['accepted' => true, 'event_id' => $event->event_id, 'duplicate' => ! $event->wasRecentlyCreated], 202);
    }
}
