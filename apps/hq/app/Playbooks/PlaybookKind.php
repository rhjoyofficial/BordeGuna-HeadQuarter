<?php

namespace App\Playbooks;

/**
 * The kinds of business guidance a playbook may hold (docs/112 §3; ruling docs/103 §8 names pricing
 * authority, escalation and product claims; docs/95 V19 lists the rest).
 *
 * A closed vocabulary on purpose: a kind is a key the loader selects by and a column a screen groups
 * by, and a free-text kind is a key nobody can find twice. Adding one is a code change and a review.
 *
 * ⛔ A kind says what the text is ABOUT. It never says what the agent MAY do: `pricing_authority` is
 * where the business writes who may approve a discount, and the gateway still decides every discount
 * by its own policy, approval and permission checks, which read nothing here.
 */
enum PlaybookKind: string
{
    case PricingAuthority = 'pricing_authority';
    case Escalation = 'escalation';
    case ProductClaims = 'product_claims';
    case ProductInformation = 'product_information';
    case CommunicationStandards = 'communication_standards';
    case ObjectionHandling = 'objection_handling';
    case CampaignInformation = 'campaign_information';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::PricingAuthority => 'Pricing and discount authority',
            self::Escalation => 'Escalation',
            self::ProductClaims => 'Product claims',
            self::ProductInformation => 'Product information',
            self::CommunicationStandards => 'Communication standards',
            self::ObjectionHandling => 'Objection handling',
            self::CampaignInformation => 'Campaign information',
            self::General => 'General guidance',
        };
    }

    /** One line for the drafting screen: what belongs in this kind, and what does not. */
    public function hint(): string
    {
        return match ($this) {
            self::PricingAuthority => 'Who may approve which discount, and what is never discounted. The gateway still decides every discount by its own checks.',
            self::Escalation => 'Which situations go to a person at once, and to whom.',
            self::ProductClaims => 'What may and may not be said about each product, with the proof behind each claim.',
            self::ProductInformation => 'Origin, process, sizes, storage. Prices and stock are never written here; they are looked up live.',
            self::CommunicationStandards => 'Tone, forms of address, what to say first, what never to promise.',
            self::ObjectionHandling => 'The objections customers raise and the honest answers the business gives.',
            self::CampaignInformation => 'What is running now, where it is running, and what a customer who saw it was told.',
            self::General => 'Guidance that fits no other kind.',
        };
    }

    /**
     * The order the loader reads kinds in when a run's budget cannot hold them all (docs/112 §4): the
     * rules that stop a wrong promise come before the ones that improve a right answer.
     *
     * @return list<self>
     */
    public static function loadOrder(): array
    {
        return [
            self::ProductClaims,
            self::PricingAuthority,
            self::Escalation,
            self::CommunicationStandards,
            self::ProductInformation,
            self::ObjectionHandling,
            self::CampaignInformation,
            self::General,
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $k) => $k->value, self::cases());
    }
}
