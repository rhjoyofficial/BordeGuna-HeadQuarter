<?php

namespace App\Voice;

use App\Context\Provenance;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * What a speech-to-text provider heard in one piece of audio (Phase 7, docs/88 A point 6).
 *
 * ⛔ **A transcript is never trusted content.** It is a machine's guess at what a person said, and the
 * person is usually a customer: the words carry exactly the authority the same words would carry typed,
 * which for a customer is none. The constructor refuses a trusted provenance, so no caller can label a
 * transcript as HQ's instruction or as business data, whatever channel it came from. A voice channel
 * never grants authority (docs/88 A point 6).
 *
 * The lineage is kept beside the words so a person can always trace a sentence back to the audio it
 * came from, the provider and model that heard it, and how sure the provider said it was.
 */
final readonly class Transcript
{
    public function __construct(
        public string $text,
        public string $languageCode,
        public ?float $confidence,
        public string $provider,
        public string $model,
        public CarbonImmutable $transcribedAt,
        public Provenance $provenance = Provenance::Customer,
        public ?string $requestId = null,
        public ?string $billedDuration = null,
    ) {
        if ($provenance->isTrusted()) {
            throw new InvalidArgumentException(
                "A transcript cannot carry the provenance [{$provenance->value}]: speech turned into text is untrusted content, "
                .'never an instruction to HQ and never business data.'
            );
        }

        if ($confidence !== null && ($confidence < 0.0 || $confidence > 1.0)) {
            throw new InvalidArgumentException("A transcript confidence must be between 0 and 1, not [{$confidence}].");
        }
    }

    public function isEmpty(): bool
    {
        return trim($this->text) === '';
    }

    /** Whether the provider's own confidence is below the given floor. Unknown confidence is not low: it is unknown. */
    public function isLowConfidence(float $floor): bool
    {
        return $this->confidence !== null && $this->confidence < $floor;
    }

    /**
     * The lineage recorded on the conversation message the transcript becomes.
     *
     * @return array<string, mixed>
     */
    public function lineage(?string $audioAssetUuid): array
    {
        return array_filter([
            'source' => 'voice_note',
            'transcribed' => true,
            'audio_asset_uuid' => $audioAssetUuid,
            'provider' => $this->provider,
            'model' => $this->model,
            'language_code' => $this->languageCode,
            'confidence' => $this->confidence,
            'transcribed_at' => $this->transcribedAt->utc()->toIso8601String(),
            'request_id' => $this->requestId,
            'billed_duration' => $this->billedDuration,
            'provenance' => $this->provenance->value,
        ], static fn ($v) => $v !== null);
    }
}
