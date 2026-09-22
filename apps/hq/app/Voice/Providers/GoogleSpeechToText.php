<?php

namespace App\Voice\Providers;

use App\Gateway\Credentials\CredentialVault;
use App\Voice\Exceptions\SpeechProviderRefused;
use App\Voice\Exceptions\SpeechProviderUnavailable;
use App\Voice\SpeechToText;
use App\Voice\Transcript;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google Cloud Speech-to-Text V2, synchronous `recognize`, for a short Bangla voice note (Phase 7).
 *
 * Every field below was read from Google's documentation on 22 September 2026, not recalled:
 *  - `POST https://{location}-speech.googleapis.com/v2/projects/{project}/locations/{location}/recognizers/_:recognize`
 *    (the V2 reference: the recognizer may be `_`, "an empty implicit Recognizer"; the regional host
 *    from the Chirp 2 code samples, `api_endpoint="us-central1-speech.googleapis.com"`);
 *  - request `config` (`autoDecodingConfig`, `model`, `languageCodes`, `features.enableAutomaticPunctuation`)
 *    and `content` (base64 bytes); response `results[].alternatives[].transcript`, `.confidence` (0 to 1),
 *    `results[].languageCode`, `metadata.totalBilledDuration`, `metadata.requestId`;
 *  - authorisation: OAuth scope `https://www.googleapis.com/auth/cloud-platform`, a Bearer token (the
 *    authentication page shows only Bearer tokens; no API key is documented for this API);
 *  - the V2 language list names `bn-BD` for `chirp_2`; the Chirp 2 model page's own tables did not list
 *    Bengali when read. Both the model and the location are configuration, and gate P7-G0 re-reads the
 *    live language list before any real audio is sent.
 *
 * `autoDecodingConfig` rather than an explicit encoding: a WhatsApp or Messenger voice note arrives as
 * Ogg Opus at whatever rate the phone chose, and V1's `OGG_OPUS` would need that rate stated (V1 docs:
 * `sampleRateHertz` "is required for all other audio formats" than FLAC and WAV).
 *
 * ⚠ **The token is never read here except from the open vault scope**, as for every other driver
 * (`CredentialVault::secret()` throws when the gateway did not open it), and never appears in an
 * exception message. With no scope open, the adapter refuses before any request leaves HQ.
 */
final class GoogleSpeechToText implements SpeechToText
{
    public const PROVIDER = 'google_speech';

    /** The `integration_credentials.provider` holding a short-lived OAuth access token for the service account. */
    public const CREDENTIAL_PROVIDER = 'google_speech_access_token';

    public const ACCOUNT_REF = 'hq';

    public function __construct(private readonly CredentialVault $vault) {}

    public function key(): string
    {
        return self::PROVIDER;
    }

    public function transcribe(string $audio, string $mime, ?string $languageCode = null): Transcript
    {
        $project = (string) config('hq.voice.google.project', '');
        $location = (string) config('hq.voice.google.location', 'asia-southeast1');
        $model = (string) config('hq.voice.google.model', 'chirp_2');
        $language = $languageCode ?? (string) config('hq.voice.google.language', 'bn-BD');
        $maxBytes = (int) config('hq.voice.google.max_bytes', 10 * 1024 * 1024);

        if ($project === '') {
            throw new SpeechProviderRefused('google_speech: no Google Cloud project is configured (hq.voice.google.project).');
        }
        if (! preg_match('/^[a-z0-9-]+$/', $location)) {
            throw new SpeechProviderRefused("google_speech: the location [{$location}] is not a Google Cloud location id.");
        }
        if ($audio === '') {
            throw new SpeechProviderRefused('google_speech: the audio is empty.');
        }
        if (strlen($audio) > $maxBytes) {
            // Synchronous recognize is for short audio; a long recording is a batch job, not a voice note.
            throw new SpeechProviderRefused('google_speech: the audio is '.strlen($audio)." bytes, over the {$maxBytes}-byte limit for a voice note.");
        }
        if (! str_starts_with(strtolower($mime), 'audio/')) {
            throw new SpeechProviderRefused("google_speech: [{$mime}] is not audio.");
        }

        // Read from the scope the caller opened; throws before anything is sent when there is none.
        $token = $this->vault->secret(self::CREDENTIAL_PROVIDER, self::ACCOUNT_REF);

        $url = sprintf(
            'https://%s-speech.googleapis.com/v2/projects/%s/locations/%s/recognizers/_:recognize',
            $location, rawurlencode($project), $location,
        );

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout((int) config('hq.voice.google.timeout', 30))
                ->post($url, [
                    'config' => [
                        'autoDecodingConfig' => (object) [],
                        'model' => $model,
                        'languageCodes' => [$language],
                        'features' => ['enableAutomaticPunctuation' => true],
                    ],
                    'content' => base64_encode($audio),
                ]);
        } catch (ConnectionException $e) {
            throw new SpeechProviderUnavailable('google_speech: '.$e->getMessage());
        } catch (Throwable $e) {
            // Class only: a client exception can embed the request, and the request carries the token.
            throw new SpeechProviderUnavailable('google_speech: '.get_class($e));
        }

        if ($response->failed()) {
            throw $this->failure($response);
        }

        return $this->transcriptFrom($response, $model, $language);
    }

    private function transcriptFrom(Response $response, string $model, string $language): Transcript
    {
        $parts = [];
        $confidences = [];
        $detected = null;

        foreach ((array) $response->json('results', []) as $result) {
            // Each result is a consecutive stretch of the audio; its first alternative is the most likely.
            $best = (array) data_get($result, 'alternatives.0', []);
            $text = trim((string) ($best['transcript'] ?? ''));
            if ($text !== '') {
                $parts[] = $text;
            }
            if (isset($best['confidence']) && is_numeric($best['confidence']) && (float) $best['confidence'] > 0.0) {
                // A confidence of 0 means "not set" in Google's responses, not "certainly wrong".
                $confidences[] = (float) $best['confidence'];
            }
            $detected ??= is_string($result['languageCode'] ?? null) && $result['languageCode'] !== '' ? $result['languageCode'] : null;
        }

        return new Transcript(
            text: implode(' ', $parts),
            languageCode: $detected ?? $language,
            confidence: $confidences === [] ? null : round(array_sum($confidences) / count($confidences), 4),
            provider: self::PROVIDER,
            model: $model,
            transcribedAt: CarbonImmutable::now(),
            requestId: is_string($response->json('metadata.requestId')) ? $response->json('metadata.requestId') : null,
            billedDuration: is_string($response->json('metadata.totalBilledDuration')) ? $response->json('metadata.totalBilledDuration') : null,
        );
    }

    private function failure(Response $response): SpeechProviderRefused|SpeechProviderUnavailable
    {
        $status = $response->status();
        $reason = substr((string) ($response->json('error.status') ?? $response->json('error.message') ?? 'error'), 0, 120);

        // Throttling and Google's own failures may pass; a bad request, a bad token or a missing permission will not.
        return $status === 429 || $status >= 500
            ? new SpeechProviderUnavailable("google_speech HTTP {$status}: {$reason}")
            : new SpeechProviderRefused("google_speech HTTP {$status}: {$reason}");
    }
}
