<?php

namespace App\Http\Controllers\Webhooks;

use App\Gateway\Credentials\CredentialVault;
use App\Http\Controllers\Controller;
use App\Observe\Alerts\WebhookSignatureFailures;
use App\Support\Features\Features;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The n8n copy-forward for gate P3-G3 (docs/72 §D, docs/88 A point 2).
 *
 * During the channel shadow Meta's webhook keeps pointing at n8n, the bot that answers today, and n8n
 * forwards a COPY of each Meta body to HQ with one HTTP node, so no Meta setting changes. HQ records it
 * exactly as it would record Meta's own delivery (one `events` row, same dedupe key, same drain), so the
 * conversation core, the support agent's proposal and the shadow pairing see no difference.
 *
 * Authentication is HQ's own, because n8n is not Meta: `X-HQ-Forward-Signature: sha256=<hmac>` over
 * `<X-HQ-Forward-Timestamp>.<raw body>` with the forwarding secret (vault `meta_forward_secret`, account
 * `n8n`), the timestamp within five minutes, so a captured copy cannot be replayed later. If n8n also
 * passes Meta's own `X-Hub-Signature-256` over the untouched body, it must verify against the app
 * secret; a mismatch is refused, because a body that claims Meta's signature and fails it has been
 * altered. The copy carries its provenance on the event.
 *
 * ⛔ 404 while `channels.meta.forwarded` is off, which it ships as; 503 with no secret stored.
 */
final class MetaForwardController extends Controller
{
    public const FLAG = 'channels.meta.forwarded';

    public const SECRET_PROVIDER = 'meta_forward_secret';

    public const ACCOUNT_REF = 'n8n';

    public const SIGNATURE_HEADER = 'X-HQ-Forward-Signature';

    public const TIMESTAMP_HEADER = 'X-HQ-Forward-Timestamp';

    public const MAX_SKEW_SECONDS = 300;

    public function __construct(
        private readonly CredentialVault $vault,
        private readonly Features $features,
        private readonly MetaWebhookController $meta,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->features->enabled(self::FLAG)) {
            return response()->json(['error' => 'not found'], 404);
        }

        [$forwardSecret, $appSecret] = $this->vault->openForAccounts(
            [[self::SECRET_PROVIDER, self::ACCOUNT_REF], [MetaWebhookController::SECRET_PROVIDER, MetaWebhookController::ACCOUNT_REF]],
            fn () => [$this->vault->optional(self::SECRET_PROVIDER, self::ACCOUNT_REF), $this->vault->optional(MetaWebhookController::SECRET_PROVIDER, MetaWebhookController::ACCOUNT_REF)],
        );
        if ($forwardSecret === null) {
            return response()->json(['error' => 'forwarding not configured'], 503);
        }

        $raw = $request->getContent();
        $timestamp = (string) $request->header(self::TIMESTAMP_HEADER, '');
        $given = (string) $request->header(self::SIGNATURE_HEADER, '');

        if (! ctype_digit($timestamp) || abs(CarbonImmutable::now()->getTimestamp() - (int) $timestamp) > self::MAX_SKEW_SECONDS) {
            return $this->refuse($request, 'stale or missing forward timestamp');
        }
        if ($given === '' || ! hash_equals('sha256='.hash_hmac('sha256', $timestamp.'.'.$raw, $forwardSecret), $given)) {
            return $this->refuse($request, 'invalid forward signature');
        }

        $metaSignature = (string) $request->header(MetaWebhookController::SIGNATURE_HEADER, '');
        $metaVerified = null;
        if ($metaSignature !== '') {
            if ($appSecret === null || ! hash_equals('sha256='.hash_hmac('sha256', $raw, $appSecret), $metaSignature)) {
                return $this->refuse($request, "the copy carries Meta's signature and it does not verify");
            }
            $metaVerified = true;
        }

        return $this->meta->accept($raw, 'meta:n8n-forward', [
            'forwarded_by' => 'n8n',
            'meta_signature' => $metaVerified === true ? 'verified' : 'absent',
        ]);
    }

    private function refuse(Request $request, string $why): JsonResponse
    {
        Log::warning('meta forward refused', ['why' => $why, 'ip' => $request->ip()]);
        app(WebhookSignatureFailures::class)->record('meta-forward');

        return response()->json(['error' => $why], 401);
    }
}
