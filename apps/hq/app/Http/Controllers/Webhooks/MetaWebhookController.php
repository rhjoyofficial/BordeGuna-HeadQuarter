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

    /**
     * The webhook envelope's `object` and the platform account it arrives for: the Page or Instagram
     * account `entry[].id` names, or, for WhatsApp, the business phone number in
     * `entry[].changes[].value.metadata.phone_number_id` (`entry[].id` is the WhatsApp Business Account,
     * which HQ does not hold as an account; docs/88 A point 2).
     */
    public const PLATFORM_FOR_OBJECT = ['page' => 'facebook_page', 'instagram' => 'instagram_account', 'whatsapp_business_account' => 'whatsapp_number'];

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

        return $this->accept($raw, 'meta:webhook');
    }

    /**
     * The envelope check and the one `events` row, shared with the n8n copy-forward receiver
     * (`MetaForwardController`), so a copy and Meta's own delivery of the same body are one event:
     * the dedupe key is the digest of the body either way.
     *
     * @param  array<string, mixed>  $extra  provenance kept on the event beside Meta's body
     */
    public function accept(string $raw, string $source, array $extra = []): JsonResponse
    {
        $body = json_decode($raw, true);
        $object = is_array($body) ? ($body['object'] ?? null) : null;
        if (! is_array($body) || ! is_string($object) || ! isset(self::PLATFORM_FOR_OBJECT[$object]) || ! is_array($body['entry'] ?? null)) {
            return response()->json(['error' => 'malformed envelope: expected {object: page|instagram|whatsapp_business_account, entry: [...]}'], 422);
        }

        $digest = hash('sha256', $raw);
        $accountRef = self::accountRef($object, $body);
        // A Page's Messenger and its comments arrive under the Page; an Instagram account's DMs under
        // the account (docs/78 B.1); a WhatsApp message under the business number (docs/88). Each is a
        // `platform_accounts` row HQ holds, or the event dead-letters.
        $businessId = $accountRef === '' ? null : PlatformAccount::query()->where('platform', self::PLATFORM_FOR_OBJECT[$object])->where('account_ref', $accountRef)->with('brand:id,business_id')->first()?->brand?->business_id;

        $event = $this->events->record(
            type: 'conversation.inbound_received',
            payload: $body + ['_digest' => $digest] + ($extra === [] ? [] : ['_provenance' => $extra]),
            source: $source,
            direction: 'inbound',
            aggregateType: 'conversation',
            aggregateId: $accountRef !== '' ? $accountRef : null,
            businessId: $businessId,
            dedupeKey: "meta:{$digest}",
        );

        return response()->json(['accepted' => true, 'event_id' => $event->event_id, 'duplicate' => ! $event->wasRecentlyCreated], 202);
    }

    /** The platform account reference an envelope arrives for (see PLATFORM_FOR_OBJECT). */
    public static function accountRef(string $object, array $body): string
    {
        $entry = is_array($body['entry'][0] ?? null) ? $body['entry'][0] : [];
        if ($object === 'whatsapp_business_account') {
            return (string) data_get($entry, 'changes.0.value.metadata.phone_number_id', '');
        }

        return (string) ($entry['id'] ?? '');
    }
}
