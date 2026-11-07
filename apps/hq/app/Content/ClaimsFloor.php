<?php

namespace App\Content;

/**
 * The floor every draft is checked against, whatever the owner's policy says (docs/145-a).
 *
 * It only ever FORBIDS, so it can never make a draft pass that the owner's policy would fail; it cannot
 * be retired from a screen, because a rule that stops an unproven health claim (CRPA 2009 s.44, false or
 * misleading claims about a product) or an invented testimonial is not a matter of taste.
 *
 * It is a deterministic phrase list (the same matcher as `ClaimsPolicy`: a phrase matches where a word
 * starts, case-insensitively, after NFC). It proves the listed phrases are absent, not that the text makes
 * no claim nobody listed; the human review of every draft is still the gate.
 *
 * Changing this list is a code change and a review, never a configuration value.
 */
final class ClaimsFloor
{
    public const VERSION = 'claims-floor.v1';

    /** @var list<array{id: string, phrases: list<string>, reason: string, legal_ref: string}> */
    public const RULES = [
        [
            'id' => 'floor.health_claim',
            'phrases' => [
                'cures', 'cure for', 'cured', 'heals', 'healing', 'medicine for', 'remedy for', 'prevents disease',
                'prevents cancer', 'fights cancer', 'cancer', 'diabetes', 'blood pressure', 'boosts immunity', 'immunity booster',
                'রোগ নিরাময়', 'নিরাময়', 'সারিয়ে', 'সারায়', 'ওষুধ', 'ঔষধ', 'রোগ প্রতিরোধ', 'ক্যান্সার', 'ডায়াবেটিস', 'রক্তচাপ', 'প্রেসার কমায়',
            ],
            'reason' => 'An unproven health or medical claim. HQ never states one; a proven claim needs the owner\'s written rule with its proof, and still a person\'s review.',
            'legal_ref' => 'CRPA 2009 s.44',
        ],
        [
            'id' => 'floor.testimonial',
            'phrases' => [
                'testimonial', 'customer says', 'customers say', 'a customer said', 'our customers say', 'customer review', '5 star review', 'five star review',
                'গ্রাহক বলেছেন', 'গ্রাহকরা বলেন', 'গ্রাহকের মতামত', 'ক্রেতা বলেছেন', 'ক্রেতারা বলেন', 'রিভিউ',
            ],
            'reason' => 'A testimonial or review in a draft. HQ never invents one; a real one is quoted by a person from its recorded source, outside AI drafting.',
            'legal_ref' => 'CRPA 2009 s.44',
        ],
    ];

    /** The floor in `ClaimsPolicy`'s own rule shape. @return list<array{id: string, phrases: list<string>, reason: string}> */
    public static function rules(): array
    {
        return array_map(static fn (array $r): array => [
            'id' => $r['id'], 'phrases' => $r['phrases'], 'reason' => $r['reason'].' ('.$r['legal_ref'].')',
        ], self::RULES);
    }
}
