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
 * Text to speech through any service that speaks the OpenAI audio API: `POST {base_url}/audio/speech`
 * (`model`, `input`, `voice`, `response_format=mp3`), answering the audio bytes.
 *
 * ⚠ OpenAI's own voices are not built for Bengali (docs/97 section 2.3 recommends Azure `bn-BD`, which stays
 * registered beside this one): the adapter is the default of the brief because it serves any compatible
 * Bangla-capable server, and the bake-off, not this class, decides which provider speaks for the owner.
 * Stock voices only: a cloned voice is refused by `SpeechSynthesisRequest` before this class sees it.
 *
 * Credential kind `speech_openai_compatible_key` (the same key the speech to text adapter reads), account `hq`.
 */
final class OpenAiCompatibleTextToSpeech implements TextToSpeech
{
    public const PROVIDER = 'openai_compatible_tts';

    public function __construct(private readonly CredentialVault $vault) {}

    public function key(): string
    {
        return self::PROVIDER;
    }

    public function synthesize(SpeechSynthesisRequest $request): SynthesizedSpeech
    {
        $token = $this->vault->secret(OpenAiCompatibleSpeechToText::CREDENTIAL, OpenAiCompatibleSpeechToText::ACCOUNT_REF);
        $base = rtrim((string) config('hq.voice.openai_compatible.base_url', ''), '/');
        $model = (string) config('hq.voice.openai_compatible.tts_model', '');
        if ($base === '' || $model === '') {
            throw new SpeechProviderRefused('openai_compatible_tts: the base URL and the model are not configured (hq.voice.openai_compatible).');
        }

        try {
            $response = Http::withToken($token)->timeout((int) config('hq.voice.openai_compatible.timeout', 60))
                ->post($base.'/audio/speech', ['model' => $model, 'input' => $request->text, 'voice' => $request->voiceId, 'response_format' => 'mp3']);
        } catch (ConnectionException $e) {
            throw new SpeechProviderUnavailable('openai_compatible_tts: '.$e->getMessage());
        } catch (Throwable $e) {
            throw new SpeechProviderUnavailable('openai_compatible_tts: '.get_class($e));
        }

        if ($response->failed()) {
            $status = $response->status();
            throw $status === 429 || $status >= 500
                ? new SpeechProviderUnavailable("openai_compatible_tts HTTP {$status}")
                : new SpeechProviderRefused("openai_compatible_tts HTTP {$status}");
        }
        $audio = $response->body();
        if ($audio === '') {
            throw new SpeechProviderRefused('openai_compatible_tts: the answer has no audio.');
        }

        return new SynthesizedSpeech($audio, 'audio/mpeg', self::PROVIDER, $model, $request->voiceId, $request->languageCode);
    }
}
