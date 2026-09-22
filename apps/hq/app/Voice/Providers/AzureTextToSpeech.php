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
 * Azure AI Speech text-to-speech, the cheaper Bangla fallback (Phase 7, docs/82 section 5). Registered nowhere.
 *
 * Read from Microsoft Learn on 22 September 2026: `POST https://{region}.tts.speech.microsoft.com/cognitiveservices/v1`,
 * headers `Ocp-Apim-Subscription-Key` (or a Bearer token), `Content-Type: application/ssml+xml`,
 * `X-Microsoft-OutputFormat` and `User-Agent` (fewer than 255 characters), an SSML body; output
 * `ogg-24khz-16bit-mono-opus` is on the documented list and is the format a WhatsApp voice note uses.
 * The `bn-BD` neural voices are `bn-BD-NabanitaNeural` (female) and `bn-BD-PradeepNeural` (male).
 * ⚠ Azure has NO `bn-BD` speech-to-text locale (only `bn-IN`), which is why transcription is Google's.
 */
final class AzureTextToSpeech implements TextToSpeech
{
    public const PROVIDER = 'azure_speech';

    public const CREDENTIAL_PROVIDER = 'azure_speech_key';

    public const ACCOUNT_REF = 'hq';

    /** The stock Bangla (Bangladesh) voices, as Microsoft lists them. */
    public const BANGLA_VOICES = ['bn-BD-NabanitaNeural', 'bn-BD-PradeepNeural'];

    public function __construct(private readonly CredentialVault $vault) {}

    public function key(): string
    {
        return self::PROVIDER;
    }

    public function synthesize(SpeechSynthesisRequest $request): SynthesizedSpeech
    {
        $region = (string) config('hq.voice.azure.region', 'southeastasia');
        $format = (string) config('hq.voice.azure.output_format', 'ogg-24khz-16bit-mono-opus');

        if (! preg_match('/^[a-z0-9]+$/', $region)) {
            throw new SpeechProviderRefused("azure_speech: [{$region}] is not an Azure region name.");
        }
        if (! preg_match('/^[A-Za-z]{2,3}-[A-Za-z]{2,4}-[A-Za-z0-9]+Neural$/', $request->voiceId)) {
            throw new SpeechProviderRefused("azure_speech: [{$request->voiceId}] is not an Azure neural voice name.");
        }

        $key = $this->vault->secret(self::CREDENTIAL_PROVIDER, self::ACCOUNT_REF);

        try {
            $response = Http::withHeaders([
                'Ocp-Apim-Subscription-Key' => $key,
                'X-Microsoft-OutputFormat' => $format,
                'User-Agent' => 'bordeguna-hq',
            ])
                ->withBody($this->ssml($request), 'application/ssml+xml')
                ->timeout((int) config('hq.voice.azure.timeout', 60))
                ->post("https://{$region}.tts.speech.microsoft.com/cognitiveservices/v1");
        } catch (ConnectionException $e) {
            throw new SpeechProviderUnavailable('azure_speech: '.$e->getMessage());
        } catch (Throwable $e) {
            throw new SpeechProviderUnavailable('azure_speech: '.get_class($e));
        }

        if ($response->failed()) {
            $status = $response->status();

            throw $status === 429 || $status >= 500
                ? new SpeechProviderUnavailable("azure_speech HTTP {$status}")
                : new SpeechProviderRefused("azure_speech HTTP {$status}");
        }

        if ($response->body() === '') {
            throw new SpeechProviderUnavailable('azure_speech: the response carried no audio.');
        }

        return new SynthesizedSpeech(
            audio: $response->body(),
            mime: str_starts_with($format, 'ogg') ? 'audio/ogg' : (str_contains($format, 'mp3') ? 'audio/mpeg' : 'application/octet-stream'),
            provider: self::PROVIDER,
            model: 'neural',
            voiceId: $request->voiceId,
            languageCode: $request->languageCode,
        );
    }

    /** The SSML body. The text is escaped: it is data to be spoken, never markup that could change the voice or add audio. */
    public function ssml(SpeechSynthesisRequest $request): string
    {
        $lang = htmlspecialchars($request->languageCode, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $voice = htmlspecialchars($request->voiceId, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $text = htmlspecialchars($request->text, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return "<speak version='1.0' xml:lang='{$lang}'><voice xml:lang='{$lang}' name='{$voice}'>{$text}</voice></speak>";
    }
}
