<?php

namespace App\Voice;

/**
 * Ruling 31 Oct 2026 item 8: speech to text has NO production default until the 50-voice-note Bangla bake-off passes
 * (plan: docs/147-r2-voice-bakeoff-plan.md). An OpenAI-compatible interface is not evidence of Bangla quality.
 *
 * `hq.voice.stt.benchmark_passed_provider` names the ONE provider key that passed the bake-off. It ships null, and it is
 * code (config, no env()): naming a provider is a reviewed release that commits the bake-off evidence beside it, never a switch
 * or an environment value. While it is null, `voice.transcribe` refuses for everyone, a person included, before any audio
 * leaves HQ; when it is set, only that provider may transcribe (any other resolved provider is refused). Activation of voice
 * still needs its own flag (`connectors.voice`), credential and the separate activation gate (P7-G2).
 */
final class SttBenchmarkGate
{
    /** The provider keys the ruling lists for the bake-off (Google Chirp 3 bn-BD, ElevenLabs Scribe, OpenAI, Gemini). */
    public const CANDIDATES = ['google_speech', 'elevenlabs_scribe', 'openai_compatible_stt', 'google_genai_stt'];

    public function passedProvider(): ?string
    {
        $provider = config('hq.voice.stt.benchmark_passed_provider');

        return is_string($provider) && $provider !== '' ? $provider : null;
    }

    /** Null when this provider may transcribe; otherwise the refusal sentence. */
    public function refusalFor(string $providerKey): ?string
    {
        $passed = $this->passedProvider();
        if ($passed === null) {
            return 'No speech-to-text provider has passed the Bangla bake-off yet (ruling 31 Oct item 8), so nothing is transcribed and no audio leaves HQ.';
        }
        if ($providerKey !== $passed) {
            return "Only the provider that passed the Bangla bake-off [{$passed}] may transcribe; [{$providerKey}] did not.";
        }

        return null;
    }
}
