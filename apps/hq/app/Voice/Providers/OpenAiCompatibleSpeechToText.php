<?php

namespace App\Voice\Providers;

use App\Gateway\Credentials\CredentialVault;
use App\Voice\Exceptions\SpeechProviderRefused;
use App\Voice\Exceptions\SpeechProviderUnavailable;
use App\Voice\SpeechToText;
use App\Voice\Transcript;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Speech to text through any service that speaks the OpenAI audio API: `POST {base_url}/audio/transcriptions`
 * (multipart `file`, `model`, optional `language`, `response_format=json`), answering `{"text": "..."}`. The
 * default (docs/144-voice-provider) because the same adapter serves OpenAI, a self-hosted Whisper server or
 * any compatible gateway by changing `hq.voice.openai_compatible.base_url` and `model`.
 *
 * ⚠ Whether the model transcribes Bangla well is a quality question the 50-note bake-off settles (docs/97
 * section 2.3), not a property of this adapter. The language hint is sent as an ISO 639-1 code.
 * ⚠ The key is read only from the open vault scope and never appears in a message.
 *
 * Credential kind `speech_openai_compatible_key`, account `hq`.
 */
final class OpenAiCompatibleSpeechToText implements SpeechToText
{
    public const PROVIDER = 'openai_compatible_stt';

    public const CREDENTIAL = 'speech_openai_compatible_key';

    public const ACCOUNT_REF = 'hq';

    public function __construct(private readonly CredentialVault $vault) {}

    public function key(): string
    {
        return self::PROVIDER;
    }

    public function transcribe(string $audio, string $mime, ?string $languageCode = null): Transcript
    {
        $maxBytes = (int) config('hq.voice.openai_compatible.max_bytes', 10 * 1024 * 1024);
        if ($audio === '') {
            throw new SpeechProviderRefused('openai_compatible_stt: the audio is empty.');
        }
        if (strlen($audio) > $maxBytes) {
            throw new SpeechProviderRefused('openai_compatible_stt: the audio is over the '.$maxBytes.'-byte limit.');
        }
        if (! str_starts_with(strtolower($mime), 'audio/') && strtolower($mime) !== 'video/webm') {
            throw new SpeechProviderRefused("openai_compatible_stt: [{$mime}] is not audio.");
        }

        $token = $this->vault->secret(self::CREDENTIAL, self::ACCOUNT_REF);
        $base = rtrim((string) config('hq.voice.openai_compatible.base_url', 'https://api.openai.com/v1'), '/');
        $model = (string) config('hq.voice.openai_compatible.stt_model', 'gpt-4o-transcribe');
        $language = $languageCode === null ? null : strtolower(substr($languageCode, 0, 2));

        try {
            $request = Http::withToken($token)->acceptJson()->timeout((int) config('hq.voice.openai_compatible.timeout', 60))
                ->attach('file', $audio, 'voice.'.self::extension($mime), ['Content-Type' => $mime]);
            $response = $request->post($base.'/audio/transcriptions', array_filter([
                'model' => $model, 'response_format' => 'json', 'language' => $language,
            ], static fn ($v) => $v !== null));
        } catch (ConnectionException $e) {
            throw new SpeechProviderUnavailable('openai_compatible_stt: '.$e->getMessage());
        } catch (Throwable $e) {
            // Class only: a client exception can embed the request, and the request carries the key.
            throw new SpeechProviderUnavailable('openai_compatible_stt: '.get_class($e));
        }

        if ($response->failed()) {
            $status = $response->status();
            $reason = substr((string) ($response->json('error.message') ?? 'error'), 0, 120);

            throw $status === 429 || $status >= 500
                ? new SpeechProviderUnavailable("openai_compatible_stt HTTP {$status}: {$reason}")
                : new SpeechProviderRefused("openai_compatible_stt HTTP {$status}: {$reason}");
        }

        $text = $response->json('text');
        if (! is_string($text)) {
            throw new SpeechProviderRefused('openai_compatible_stt: the answer has no text.');
        }

        return new Transcript(
            text: trim($text),
            languageCode: is_string($response->json('language')) ? $response->json('language') : ($languageCode ?? 'und'),
            confidence: null, // the OpenAI audio API returns none; an unknown confidence is null, never invented
            provider: self::PROVIDER,
            model: $model,
            transcribedAt: CarbonImmutable::now(),
        );
    }

    private static function extension(string $mime): string
    {
        return match (strtolower(explode(';', $mime)[0])) {
            'audio/ogg' => 'ogg', 'audio/mpeg', 'audio/mp3' => 'mp3', 'audio/wav', 'audio/x-wav' => 'wav',
            'audio/mp4', 'audio/m4a' => 'm4a', 'audio/flac' => 'flac', default => 'webm',
        };
    }
}
