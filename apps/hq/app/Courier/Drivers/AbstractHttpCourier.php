<?php

namespace App\Courier\Drivers;

use App\Courier\CourierConnector;
use App\Courier\Exceptions\CourierRefused;
use App\Courier\Exceptions\CourierUnavailable;
use App\Courier\Exceptions\InvalidCourierWebhook;
use App\Gateway\Credentials\CredentialVault;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * What the four courier drivers share: reading the credential only from the open vault scope, mapping
 * every failure to one of two exception classes, and verifying a webhook secret in constant time.
 *
 * ⚠ A message never carries the request, the response body or a credential: a client exception can embed
 * the request, and the request carries the secret, so only the class name and the status code are kept.
 */
abstract class AbstractHttpCourier implements CourierConnector
{
    public function __construct(protected readonly CredentialVault $vault) {}

    /** The `integration_credentials.provider` this driver reads. */
    abstract protected function credentialKind(): string;

    protected function baseUrl(): string
    {
        $url = rtrim((string) config('hq.courier.'.$this->key().'.base_url', ''), '/');
        if ($url === '') {
            throw new CourierRefused($this->key().': no base URL is configured (hq.courier.'.$this->key().'.base_url).');
        }

        return $url;
    }

    protected function pending(): PendingRequest
    {
        return Http::acceptJson()->timeout((int) config('hq.courier.timeout_seconds', 15));
    }

    /**
     * The credential, from the vault scope the caller opened (throws before any request when there is none).
     *
     * @param  list<string>  $requiredKeys  for a JSON credential: the keys that must be present
     * @return array<string, string>|string a decoded JSON object when the stored value is one, else the raw value
     */
    protected function credential(string $accountRef, array $requiredKeys = []): array|string
    {
        $raw = $this->vault->secret($this->credentialKind(), $accountRef);
        if ($requiredKeys === []) {
            return $raw;
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new CourierRefused($this->key().': the stored credential is not the JSON object this courier needs ('.implode(', ', $requiredKeys).').');
        }
        foreach ($requiredKeys as $key) {
            if (! isset($decoded[$key]) || ! is_string($decoded[$key]) || $decoded[$key] === '') {
                throw new CourierRefused($this->key().": the stored credential has no [{$key}].");
            }
        }

        return $decoded;
    }

    /** @param  callable(): Response  $call */
    protected function guard(callable $call): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException) {
            throw new CourierUnavailable($this->key().': the courier could not be reached.');
        } catch (CourierRefused|CourierUnavailable $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new CourierUnavailable($this->key().': '.$e::class);
        }

        $status = $response->status();
        if ($status === 401 || $status === 403) {
            throw new CourierRefused($this->key().': the courier rejected the credentials.');
        }
        if ($status === 404) {
            throw new CourierRefused($this->key().': the courier does not know this consignment.');
        }
        if ($status === 429 || $status >= 500) {
            throw new CourierUnavailable($this->key()." answered {$status}.");
        }
        if ($response->failed()) {
            throw new CourierRefused($this->key()." refused the request ({$status}).");
        }

        return $response;
    }

    /** Constant-time check; an empty configured or given secret never matches. */
    protected function requireSecret(?string $given, string $secret): void
    {
        if ($secret === '' || $given === null || $given === '' || ! hash_equals($secret, $given)) {
            throw new InvalidCourierWebhook($this->key().': the webhook secret is missing or wrong.');
        }
    }

    /** @return array<string, mixed> */
    protected function jsonBody(string $rawBody): array
    {
        $body = json_decode($rawBody, true);
        if (! is_array($body)) {
            throw new InvalidCourierWebhook($this->key().': the webhook body is not JSON.');
        }

        return $body;
    }

    protected static function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    protected static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : (is_numeric($value) ? (string) $value : null);
    }
}
