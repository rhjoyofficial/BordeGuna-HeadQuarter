<?php

namespace App\Voice;

use App\Context\Provenance;

/**
 * Audio a text-to-speech provider made. Always `Provenance::Generated`: it is a model's output, not
 * evidence of anything, and it is not trusted (ADR-024). Nothing in this release stores or sends it.
 */
final readonly class SynthesizedSpeech
{
    public Provenance $provenance;

    public function __construct(
        public string $audio,
        public string $mime,
        public string $provider,
        public string $model,
        public string $voiceId,
        public string $languageCode,
    ) {
        $this->provenance = Provenance::Generated;
    }

    public function bytes(): int
    {
        return strlen($this->audio);
    }
}
