<?php

namespace App\Voice\Fake;

use App\Voice\Exceptions\SpeechProviderRefused;
use App\Voice\SpeechToText;
use App\Voice\Transcript;
use Carbon\CarbonImmutable;

/**
 * A speech to text stand-in that listens to nothing and reaches nothing (contract tests, the sandbox switch).
 * It answers a fixed line, so a screen can be tried before any provider is connected. It refuses empty and
 * non-audio input exactly as a real adapter must.
 */
final class FakeSpeechToText implements SpeechToText
{
    public const TEXT = 'sandbox transcript: no speech provider is connected';

    public function key(): string
    {
        return 'fake_stt';
    }

    public function transcribe(string $audio, string $mime, ?string $languageCode = null): Transcript
    {
        if ($audio === '') {
            throw new SpeechProviderRefused('fake_stt: the audio is empty.');
        }
        if (! str_starts_with(strtolower($mime), 'audio/') && strtolower($mime) !== 'video/webm') {
            throw new SpeechProviderRefused("fake_stt: [{$mime}] is not audio.");
        }

        return new Transcript(self::TEXT, $languageCode ?? 'en', 0.5, 'fake_stt', 'fake', CarbonImmutable::now());
    }
}
