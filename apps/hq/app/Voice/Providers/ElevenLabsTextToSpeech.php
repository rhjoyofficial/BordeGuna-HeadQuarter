<?php

namespace App\Voice\Providers;

use App\Gateway\Credentials\CredentialVault;
use App\Voice\Exceptions\SpeechProviderRefused;
use App\Voice\Exceptions\SpeechProviderUnavailable;
use App\Voice\SpeechSynthesisRequest;
use App\Voice\SynthesizedSpeech;
use App\Voice\TextToSpeech;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * ElevenLabs text-to-speech, for a natural Bangla voice (Phase 7, docs/82 section 5). Registered nowhere.
 *
 * Read from ElevenLabs' API reference on 22 September 2026: `POST https://api.elevenlabs.io/v1/text-to-speech/{voice_id}`,
 * header `xi-api-key`, body `text` (required), `model_id`, `language_code`, `voice_settings`; query
 * `output_format`, default `mp3_44100_128`; the response is the audio. The models page lists Bengali for
 * `eleven_v3` and NOT for `eleven_multilingual_v2`, so the configured model is `eleven_v3`; gate P7-G0
 * re-reads the model list before first use.
 *
 * Only stock voices reach this class: `SpeechSynthesisRequest` refuses a cloned one (Phase 8).
 */
final class ElevenLabsTextToSpeech implements TextToSpeech
{
    public const PROVIDER = 'elevenlabs';

    public const CREDENTIAL_PROVIDER = 'elevenlabs_api_key';

    public const ACCOUNT_REF = 'hq';

    public const ENDPOINT = 'https://api.elevenlabs.io/v1/text-to-speech/';

    public function __construct(private readonly CredentialVault $vault) {}

    public function key(): string
    {
        return self::PROVIDER;
    }

    public function synthesize(SpeechSynthesisRequest $request): SynthesizedSpeech
    {
        $model = (string) config('hq.voice.elevenlabs.model', 'eleven_v3');
        $format = (string) config('hq.voice.elevenlabs.output_format', 'mp3_44100_128');
        $languageCode = config('hq.voice.elevenlabs.language_code');

        if (! preg_match('/^[A-Za-z0-9]+$/', $request->voiceId)) {
            throw new SpeechProviderRefused("elevenlabs: [{$request->voiceId}] is not an ElevenLabs voice id.");
        }

        $key = $this->vault->secret(self::CREDENTIAL_PROVIDER, self::ACCOUNT_REF);

        try {
            $response = Http::withHeaders(['xi-api-key' => $key])
                ->accept('audio/mpeg')
                ->timeout((int) config('hq.voice.elevenlabs.timeout', 60))
                ->post(self::ENDPOINT.$request->voiceId.'?output_format='.rawurlencode($format), array_filter([
                    'text' => $request->text,
                    'model_id' => $model,
                    'language_code' => is_string($languageCode) && $languageCode !== '' ? $languageCode : null,
                ], static fn ($v) => $v !== null));
        } catch (ConnectionException $e) {
            throw new SpeechProviderUnavailable('elevenlabs: '.$e->getMessage());
        } catch (Throwable $e) {
            throw new SpeechProviderUnavailable('elevenlabs: '.get_class($e));
        }

        if ($response->failed()) {
            $status = $response->status();

            throw $status === 429 || $status >= 500
                ? new SpeechProviderUnavailable("elevenlabs HTTP {$status}")
                : new SpeechProviderRefused("elevenlabs HTTP {$status}");
        }

        if ($response->body() === '') {
            throw new SpeechProviderUnavailable('elevenlabs: the response carried no audio.');
        }

        return new SynthesizedSpeech(
            audio: $response->body(),
            mime: str_starts_with($format, 'mp3') ? 'audio/mpeg' : (string) ($response->header('Content-Type') ?: 'application/octet-stream'),
            provider: self::PROVIDER,
            model: $model,
            voiceId: $request->voiceId,
            languageCode: $request->languageCode,
        );
    }
}
