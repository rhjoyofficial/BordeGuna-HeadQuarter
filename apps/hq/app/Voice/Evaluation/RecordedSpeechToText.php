<?php

namespace App\Voice\Evaluation;

use App\Voice\Exceptions\SpeechProviderRefused;
use App\Voice\SpeechToText;
use App\Voice\Transcript;
use Carbon\CarbonImmutable;

/**
 * An evaluation hook for the future voice suite (Phase 7, proposed gate P7-G1): a speech-to-text
 * "provider" that answers from recorded transcripts, keyed by the SHA-256 of the audio, and calls nobody.
 *
 * It lets a voice scenario be replayed through `VoiceNoteIngestion` and the conversation core exactly as a
 * real voice note would travel, with the provider's answer fixed, so a suite measures what HQ does with
 * what was heard rather than how well a provider heard it. Unknown audio is refused, never guessed.
 *
 * ⛔ Never registered in production: `SpeechProviders` ships empty and only a test or a future gate runner
 * hands it this class.
 */
final class RecordedSpeechToText implements SpeechToText
{
    public const PROVIDER = 'recorded';

    /** @var array<string, array{text: string, language: string, confidence: ?float}> */
    private array $recordings = [];

    /** @var list<string> the audio checksums asked for, in order */
    private array $asked = [];

    public function record(string $audio, string $text, string $language = 'bn-BD', ?float $confidence = 0.9): self
    {
        $this->recordings[hash('sha256', $audio)] = ['text' => $text, 'language' => $language, 'confidence' => $confidence];

        return $this;
    }

    public function key(): string
    {
        return self::PROVIDER;
    }

    public function transcribe(string $audio, string $mime, ?string $languageCode = null): Transcript
    {
        $checksum = hash('sha256', $audio);
        $this->asked[] = $checksum;

        $recording = $this->recordings[$checksum] ?? null;
        if ($recording === null) {
            throw new SpeechProviderRefused('recorded: no transcript is recorded for this audio.');
        }

        return new Transcript(
            text: $recording['text'],
            languageCode: $recording['language'],
            confidence: $recording['confidence'],
            provider: self::PROVIDER,
            model: 'fixture',
            transcribedAt: CarbonImmutable::now(),
        );
    }

    /** @return list<string> */
    public function asked(): array
    {
        return $this->asked;
    }
}
