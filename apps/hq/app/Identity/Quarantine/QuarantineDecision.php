<?php

namespace App\Identity\Quarantine;

/**
 * What a reviewer decided about a flagged cross-business relationship (docs/117 §5). None of the three
 * lifts suppression: only a ruled, lineage-preserving repair could, and none is built.
 */
enum QuarantineDecision: string
{
    /** The relationship should not exist. */
    case ConfirmedWrong = 'confirmed_wrong';

    /** It should be repaired by the future un-merge (docs/117 §6). */
    case NeedsRepair = 'needs_repair';

    /** The reviewer believes it is legitimate; the note explains why. Stays suppressed: ruling §11 forbids it. */
    case Disputed = 'disputed';

    public function label(): string
    {
        return match ($this) {
            self::ConfirmedWrong => 'Confirmed wrong',
            self::NeedsRepair => 'Needs repair',
            self::Disputed => 'Disputed (needs a ruling)',
        };
    }
}
