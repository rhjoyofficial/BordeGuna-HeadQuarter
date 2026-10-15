<?php

namespace App\Decisions;

/**
 * The one decision vocabulary of `decision_records` (ruling docs/120 item 20): `approved`, `edited`,
 * `rejected`. Every writer stores one of these three from this release on; `DecisionRecord` refuses
 * anything else on create.
 *
 * ## The compatibility map (legacy rows are never rewritten)
 *
 * Before this release the inbox proposal screen stored the button a person pressed, `send` or
 * `discard`, instead of the verdict. Those rows stay exactly as written: rewriting them would change
 * history without new evidence. Every reader goes through `normalize()`, which maps them:
 *
 *  - `discard` -> `rejected` (the proposal was not used);
 *  - `send` with `final.edited` true, or with a delta when `final.edited` is absent -> `edited`;
 *  - `send` otherwise -> `approved` (sent as proposed).
 *
 * A stored word outside the vocabulary and outside this map is read as `edited` when the row carries a
 * delta and `approved` otherwise, which is how the reader treated it before; such a row is a defect to
 * report, not a fourth meaning.
 */
final class DecisionVocabulary
{
    public const APPROVED = 'approved';

    public const EDITED = 'edited';

    public const REJECTED = 'rejected';

    /** What a writer may store. */
    public const CURRENT = [self::APPROVED, self::EDITED, self::REJECTED];

    /** Legacy stored words and what they mean in the current vocabulary (see the class comment for `send`). */
    public const LEGACY = ['send' => 'approved or edited, by final.edited', 'discard' => self::REJECTED];

    /** A stored decision read in the current vocabulary. */
    public static function normalize(string $stored, ?array $final = null, ?array $delta = null): string
    {
        return match ($stored) {
            self::APPROVED, self::EDITED, self::REJECTED => $stored,
            'discard' => self::REJECTED,
            'send' => (bool) ($final['edited'] ?? ($delta !== null && $delta !== [])) ? self::EDITED : self::APPROVED,
            default => $delta !== null && $delta !== [] ? self::EDITED : self::APPROVED,
        };
    }

    /** The inbox proposal screen's two buttons, as a verdict: discard is a rejection, a send is kept or edited. */
    public static function fromInboxAction(string $action, bool $edited): string
    {
        return $action === 'discard' ? self::REJECTED : ($edited ? self::EDITED : self::APPROVED);
    }

    public static function isCurrent(string $decision): bool
    {
        return in_array($decision, self::CURRENT, true);
    }
}
