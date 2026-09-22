<?php

namespace App\Voice;

use App\Voice\Exceptions\SpeechProviderRefused;
use App\Voice\Exceptions\SpeechProviderUnavailable;

/**
 * Turning a short piece of audio into text (Phase 7). The first use is a customer's voice note arriving
 * in a conversation; phone calls come later and are a separate capability with their own consent
 * (`TelephonyProvider::TRANSCRIBE`, `ConsentPurpose::Recording`).
 *
 * An implementation is a replaceable adapter: it authenticates only through the `CredentialVault` scope
 * the caller opened, sends the audio to its provider, and returns an untrusted `Transcript`. It never
 * decides what the words mean or what may be done about them; the conversation core and the runtime
 * boundaries do that, exactly as for typed text.
 */
interface SpeechToText
{
    /** The provider's name, recorded in the transcript's lineage. */
    public function key(): string;

    /**
     * @param  string  $audio  the raw audio bytes
     * @param  string  $mime  the audio's media type, e.g. `audio/ogg`
     *
     * @throws SpeechProviderRefused when the provider (or this adapter, before calling it) refuses the request
     * @throws SpeechProviderUnavailable when the provider could not be reached or failed on its side
     */
    public function transcribe(string $audio, string $mime, ?string $languageCode = null): Transcript;
}
