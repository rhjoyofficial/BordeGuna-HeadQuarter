<?php

namespace App\Outcomes;

use App\Context\Provenance;
use InvalidArgumentException;

/**
 * The closed registry of outcome types (docs/107 section 5). Reviewed like code, deliberately not
 * configuration: a type cannot be invented on a server, and every domain shares this one list.
 *
 * ⚠ Types name BUSINESS EVENTS, never HQ actions (D10). "Reply sent" is already in
 *    `conversation_messages` and `tool_calls`; "customer responded" is the outcome. Changing what a
 *    type means is a new type or a new schema version, never a quiet edit of an existing one (D11).
 */
final class OutcomeTypes
{
    public const SCHEMA_VERSION = 'outcome.v1';

    public const ATTRIBUTION_SCHEMA_VERSION = 'attribution.v1';

    /** @var array<string, OutcomeTypeDefinition>|null */
    private static ?array $definitions = null;

    public static function get(string $type): OutcomeTypeDefinition
    {
        return self::all()[$type] ?? throw new InvalidArgumentException("[{$type}] is not a registered outcome type (docs/107 section 5).");
    }

    public static function has(string $type): bool
    {
        return isset(self::all()[$type]);
    }

    /** @return array<string, OutcomeTypeDefinition> */
    public static function all(): array
    {
        if (self::$definitions !== null) {
            return self::$definitions;
        }

        $orderSubjects = [ResultType::ShopOrder, ResultType::ErpOrder];
        $orderSources = [OutcomeSource::ErpReadBack, OutcomeSource::ShopWebhook, OutcomeSource::ShopReconcile];
        $business = [Provenance::Business];

        $definitions = [];
        foreach (['order.placed', 'order.confirmed', 'order.delivered', 'order.cancelled'] as $type) {
            $definitions[] = new OutcomeTypeDefinition($type, 'commerce', $orderSubjects, $orderSources, $business);
        }
        // What the customer did is the customer's act, recorded from HQ's own conversation tables.
        $definitions[] = new OutcomeTypeDefinition('conversation.customer_responded', 'conversation',
            [ResultType::Conversation], [OutcomeSource::HqInternal], [Provenance::Customer, Provenance::Business]);
        $definitions[] = new OutcomeTypeDefinition('conversation.resolved', 'conversation',
            [ResultType::Conversation], [OutcomeSource::HqInternal, OutcomeSource::Operator], $business);
        $definitions[] = new OutcomeTypeDefinition('publication.verified', 'content',
            [ResultType::ContentPublication], [OutcomeSource::HqInternal, OutcomeSource::PlatformRead], [Provenance::Business, Provenance::ThirdParty]);

        $keyed = [];
        foreach ($definitions as $definition) {
            $keyed[$definition->type] = $definition;
        }

        return self::$definitions = $keyed;
    }
}
