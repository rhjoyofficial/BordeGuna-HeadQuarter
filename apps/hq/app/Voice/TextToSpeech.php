<?php

namespace App\Voice;

use App\Voice\Exceptions\SpeechProviderRefused;
use App\Voice\Exceptions\SpeechProviderUnavailable;

/**
 * Turning text into speech in a stock voice (Phase 7). ElevenLabs is the first choice for a natural
 * Bangla voice, Azure's `bn-BD` neural voices the cheaper fallback (docs/82 section 5).
 *
 * An implementation authenticates only through the `CredentialVault` scope the caller opened and
 * returns `Provenance::Generated` audio. It decides nothing about who hears it: sending audio to anyone
 * is an outbound message and goes through the gateway, consent and approval like any other.
 */
interface TextToSpeech
{
    public function key(): string;

    /**
     * @throws SpeechProviderRefused
     * @throws SpeechProviderUnavailable
     */
    public function synthesize(SpeechSynthesisRequest $request): SynthesizedSpeech;
}
