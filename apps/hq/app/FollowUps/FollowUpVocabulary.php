<?php

namespace App\FollowUps;

/**
 * The S4 vocabulary, explicit, validated and version-controlled (docs/120 item 12, item 10; docs/123
 * section 6). Every value a follow-up or an opportunity may carry is one of these, held three times:
 *
 *  1. the PHP enums below are the only way a value enters the service (a proposal, agent or person,
 *     is built from enums, never from free text, so a model cannot invent a kind or a reason);
 *  2. the screen validates against the same enums (`Rule::enum`);
 *  3. the database refuses anything else (CHECK constraints, migration `2026_10_15_400000`).
 *
 * ⛔ Changing a list is a reviewed change: a new VERSION here, a new migration, and the pin in
 *    `FollowUpVocabularyTest` updated in the same commit. There is no `transactional` kind (item 10):
 *    a third kind would need consent semantics of its own, which nobody has ruled.
 */
final class FollowUpVocabulary
{
    public const VERSION = 'followups.vocabulary.v1';

    /** @return array<string, list<string>> column => allowed values */
    public static function all(): array
    {
        $values = fn (string $enum) => array_map(fn (\BackedEnum $c) => $c->value, $enum::cases());

        return [
            'follow_ups.kind' => $values(FollowUpKind::class),
            'follow_ups.origin' => $values(FollowUpOrigin::class),
            'follow_ups.state' => $values(FollowUpState::class),
            'opportunities.kind' => $values(OpportunityKind::class),
            'opportunities.state' => $values(OpportunityState::class),
            'opportunities.close_reason' => $values(OpportunityCloseReason::class),
        ];
    }
}
