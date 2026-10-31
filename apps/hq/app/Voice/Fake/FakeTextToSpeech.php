<?php

namespace App\Voice\Fake;

use App\Voice\SpeechSynthesisRequest;
use App\Voice\SynthesizedSpeech;
use App\Voice\TextToSpeech;

/** A text to speech stand-in: returns a few bytes of silence-shaped audio. Reaches nothing, needs no credential. */
final class FakeTextToSpeech implements TextToSpeech
{
    public function key(): string
    {
        return 'fake_tts';
    }

    public function synthesize(SpeechSynthesisRequest $request): SynthesizedSpeech
    {
        // A valid MPEG audio frame header followed by padding: enough for a player to accept, never a real voice.
        return new SynthesizedSpeech("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 'audio/mpeg', 'fake_tts', 'fake', $request->voiceId, $request->languageCode);
    }
}
