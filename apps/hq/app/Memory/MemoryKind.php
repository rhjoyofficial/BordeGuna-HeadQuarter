<?php

namespace App\Memory;

/**
 * The three kinds of memory, which differ in what would make them WRONG.
 *
 * A structured fact is wrong when it is out of date. An episodic record is wrong only if it did not
 * happen. A semantic belief is wrong whenever the inference was bad, which is often enough that it
 * carries a confidence and must never be quoted to a customer as fact.
 *
 * Collapsing the three produces a store that cannot say whether it is remembering or guessing,
 * which is exactly the distinction a customer-facing agent must not lose.
 */
enum MemoryKind: string
{
    /** A current fact with a key: delivery address, preferred language. One live value per key. */
    case Structured = 'structured';

    /** Something that happened at a time. Stays true forever, because it happened. */
    case Episodic = 'episodic';

    /** An inference: "prefers calls after 6pm". Carries a confidence and may simply be wrong. */
    case Semantic = 'semantic';

    /** Whether a row of this kind may be superseded by a newer answer to the same question. */
    public function isCorrectable(): bool
    {
        return $this !== self::Episodic;
    }

    /** Whether a row of this kind may be stated to a customer as fact. */
    public function isAssertable(): bool
    {
        return $this !== self::Semantic;
    }
}
