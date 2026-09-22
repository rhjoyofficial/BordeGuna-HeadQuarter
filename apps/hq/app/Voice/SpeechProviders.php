<?php

namespace App\Voice;

/**
 * The speech providers this deployment uses, one of each kind at most (Phase 7).
 *
 * ⛔ **Ships EMPTY, and that is the correct state** (docs/88 A points 1 and 6: registered nowhere, no
 * voice interaction activated). `AppServiceProvider` binds an empty instance and nothing in `app/`
 * registers a provider; a test holds both. With nothing registered, a customer's voice note is kept as
 * an audio attachment with no transcript, exactly as before this class existed, and no audio leaves HQ.
 *
 * Registering a provider is the activation step of gate P7-G2, a person's decision recorded in the audit
 * log, never a side effect of a deploy or a configuration default.
 */
final class SpeechProviders
{
    private ?SpeechToText $speechToText = null;

    private ?TextToSpeech $textToSpeech = null;

    public function registerSpeechToText(SpeechToText $provider): void
    {
        $this->speechToText = $provider;
    }

    public function registerTextToSpeech(TextToSpeech $provider): void
    {
        $this->textToSpeech = $provider;
    }

    public function speechToText(): ?SpeechToText
    {
        return $this->speechToText;
    }

    public function textToSpeech(): ?TextToSpeech
    {
        return $this->textToSpeech;
    }

    public function isEmpty(): bool
    {
        return $this->speechToText === null && $this->textToSpeech === null;
    }
}
