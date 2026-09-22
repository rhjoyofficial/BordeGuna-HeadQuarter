<?php

namespace App\Voice;

use App\Voice\Exceptions\SyntheticIdentityRefused;
use InvalidArgumentException;

/**
 * One piece of text to be spoken, in one stock voice (Phase 7, docs/88 A point 6).
 *
 * ⛔ **No cloned voice in this release.** A real person's voice is a synthetic identity (ADR-021,
 * `ConsentPurpose::SyntheticIdentity`) and needs that person's written consent record, which Phase 8
 * builds at gate P8-G0. Until then the request itself refuses a cloned voice, so no adapter can be handed
 * one by mistake.
 */
final readonly class SpeechSynthesisRequest
{
    /** Long enough for a spoken answer, short enough that a runaway text cannot become a runaway bill. */
    public const MAX_CHARACTERS = 2500;

    public function __construct(
        public string $text,
        public string $voiceId,
        public string $languageCode = 'bn-BD',
        public VoiceKind $voiceKind = VoiceKind::Stock,
    ) {
        if ($voiceKind === VoiceKind::Cloned) {
            throw new SyntheticIdentityRefused(
                "The voice [{$voiceId}] is a cloned voice. Speaking in a real person's voice needs that person's written consent "
                .'record (Phase 8, gate P8-G0), which does not exist yet; this release speaks only in stock voices.'
            );
        }

        if (trim($text) === '') {
            throw new InvalidArgumentException('There is nothing to speak: the text is empty.');
        }

        if (mb_strlen($text) > self::MAX_CHARACTERS) {
            throw new InvalidArgumentException('The text is longer than '.self::MAX_CHARACTERS.' characters; split it or shorten it.');
        }

        if (trim($voiceId) === '') {
            throw new InvalidArgumentException('A stock voice must be named.');
        }
    }
}
