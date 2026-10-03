<?php

namespace App\Conversation\Channels\Meta;

use App\Commerce\Exceptions\Unsupported;
use App\Conversation\Channels\DeliveryReceipt;
use App\Gateway\Credentials\CredentialVault;
use App\Http\Controllers\Webhooks\MetaWebhookController;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * The one way HQ's Meta send adapters call the Graph API (docs/88 A point 2).
 *
 * In order, before a byte leaves: the local send rate for this account (a refusal costs nothing), the
 * account's token from the vault's OPEN scope (a connector never resolves a credential; with no scope
 * open the send is refused, never looked up), and the `appsecret_proof` when the app secret is in the
 * vault. Then one POST, and the answer turned into a `DeliveryReceipt`:
 *
 *  - accepted, with the provider's message id, when Meta answered 2xx and named the message;
 *  - failed, classified (`MetaSendError`), when Meta answered with an error or HQ refused first;
 *  - UNCERTAIN when HQ cannot know whether Meta received it: a timeout after connecting, a 504, a 2xx
 *    that names no message. ADR-031: an uncertain send is never retried automatically.
 *
 * `appsecret_proof` is Meta's documented guard for server calls ("Securing Graph API requests"): the
 * SHA-256 HMAC of the access token keyed with the app secret, so a stolen token is useless without the
 * secret. The token travels in the `Authorization: Bearer` header, never in a URL a log could keep.
 *
 * ⛔ Nothing in HQ constructs a send adapter outside tests: `PlatformRegistry` ships empty and no
 * provider registration exists (a test greps for one). Building this class is not sending.
 */
final class MetaGraphClient
{
    public function __construct(private readonly CredentialVault $vault) {}

    /**
     * @param  array<string, mixed>  $body
     * @param  Closure(array): ?string  $providerRef  reads the message id out of a 2xx body
     */
    public function send(string $path, array $body, string $tokenProvider, string $accountRef, string $throttleKey, Closure $providerRef): DeliveryReceipt
    {
        $now = CarbonImmutable::now('UTC');
        $perMinute = max(1, (int) config("hq.messaging.meta.send_per_minute.{$throttleKey}", 60));
        $limiterKey = "meta-send:{$throttleKey}:{$accountRef}";

        if (RateLimiter::tooManyAttempts($limiterKey, $perMinute)) {
            return $this->failed($now, MetaSendError::local(MetaSendError::THROTTLED_LOCALLY,
                "HQ's own send rate for [{$throttleKey}/{$accountRef}] is {$perMinute} a minute; this send was not attempted"));
        }

        try {
            $token = $this->vault->secret($tokenProvider, $accountRef);
        } catch (Unsupported) {
            return $this->failed($now, MetaSendError::local(MetaSendError::CREDENTIAL_NOT_OPEN,
                "no [{$tokenProvider}] credential is open for [{$accountRef}]; the gateway opens a tool's declared credentials, a connector never looks one up"));
        }

        RateLimiter::hit($limiterKey, 60);

        $url = $this->endpoint($path, $token);

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(5)
                ->timeout((int) config('hq.messaging.meta.timeout', 15))
                ->post($url, $body);
        } catch (ConnectionException $e) {
            // Refused or unresolved before anything was sent: a plain, retryable failure. Anything else
            // (a timeout once connected) may have reached Meta, so it is uncertain.
            if (preg_match('/cURL error (6|7)\b|Could not resolve host|Connection refused/i', $e->getMessage()) === 1) {
                return $this->failed($now, MetaSendError::local(MetaSendError::PROVIDER_ERROR, 'Meta was not reachable; nothing was sent'));
            }

            return new DeliveryReceipt(DeliveryReceipt::UNCERTAIN, null, $now, 'no answer from Meta after the request left: '.$this->short($e->getMessage()));
        } catch (Throwable $e) {
            return new DeliveryReceipt(DeliveryReceipt::UNCERTAIN, null, $now, 'the request failed mid-call: '.$this->short($e->getMessage()));
        }

        $json = $response->json();

        if ($response->successful()) {
            $ref = is_array($json) ? $providerRef($json) : null;
            if ($ref === null || $ref === '') {
                return new DeliveryReceipt(DeliveryReceipt::UNCERTAIN, null, $now, 'Meta answered '.$response->status().' but named no message id');
            }

            return new DeliveryReceipt(DeliveryReceipt::ACCEPTED, $ref, $now);
        }

        if ($response->status() === 504) {
            return new DeliveryReceipt(DeliveryReceipt::UNCERTAIN, null, $now, 'Meta answered 504: the request may or may not have been processed');
        }

        return $this->failed($now, MetaSendError::fromResponse($response->status(), $json));
    }

    /**
     * One GET of a Graph node HQ already holds the id of (docs/95 §5 "Delivery": the reconciliation
     * reads a sent message back by id). The same guards as a send, except the local send rate, which a
     * read does not spend: the token from the OPEN scope only, the `appsecret_proof` when the secret is
     * in the vault, the token in the header and never in the URL.
     *
     * @param  array<string, string>  $query
     * @return array{status: int|null, json: array|null, error: string|null} the answer as it was, or
     *                                                                       status null with the reason
     *                                                                       no answer could be had
     */
    public function read(string $path, array $query, string $tokenProvider, string $accountRef): array
    {
        try {
            $token = $this->vault->secret($tokenProvider, $accountRef);
        } catch (Unsupported) {
            return ['status' => null, 'json' => null, 'error' => "no [{$tokenProvider}] credential is open for [{$accountRef}]"];
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout((int) config('hq.messaging.meta.timeout', 15))
                ->get($this->endpoint($path, $token, $query));
        } catch (Throwable $e) {
            return ['status' => null, 'json' => null, 'error' => 'no answer from Meta: '.$this->short($e->getMessage())];
        }

        $json = $response->json();

        return ['status' => $response->status(), 'json' => is_array($json) ? $json : null, 'error' => null];
    }

    /** @param  array<string, string>  $query */
    private function endpoint(string $path, string $token, array $query = []): string
    {
        $appSecret = $this->vault->optional(MetaWebhookController::SECRET_PROVIDER, MetaWebhookController::ACCOUNT_REF);
        if ($appSecret !== null) {
            $query['appsecret_proof'] = hash_hmac('sha256', $token, $appSecret);
        }

        return rtrim((string) config('hq.messaging.meta.graph_base', 'https://graph.facebook.com'), '/')
            .'/'.trim((string) config('hq.messaging.meta.graph_version', 'v25.0'), '/')
            .'/'.ltrim($path, '/')
            .($query === [] ? '' : '?'.http_build_query($query));
    }

    private function failed(CarbonImmutable $at, MetaSendError $error): DeliveryReceipt
    {
        return new DeliveryReceipt(DeliveryReceipt::FAILED, null, $at, $error->describe(), $error->category, $error->retryable());
    }

    /** Trimmed, and without the proof a transport error may quote back with the URL. */
    private function short(string $message): string
    {
        return mb_substr((string) preg_replace('/appsecret_proof=[0-9a-f]+/i', 'appsecret_proof=[redacted]', $message), 0, 300);
    }
}
