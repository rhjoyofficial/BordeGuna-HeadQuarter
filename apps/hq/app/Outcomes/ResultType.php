<?php

namespace App\Outcomes;

/**
 * The kinds of business record an outcome can be about (docs/107 section 4.2). A reference always
 * carries the system of record's natural key; these name which HQ read-model row, if any, it also
 * points at (D4, D6).
 */
enum ResultType: string
{
    /** An order a shop owns, mirrored in `facts_orders`. */
    case ShopOrder = 'shop_order';

    /** An order the ERP owns; `facts_orders` holds HQ's read-back of it when there is one. */
    case ErpOrder = 'erp_order';

    case Conversation = 'conversation';
    case ConversationMessage = 'conversation_message';
    case ContentPublication = 'content_publication';

    /** A record HQ does not mirror at all: an ERP invoice or payment, an ad on Meta. Natural key only. */
    case External = 'external';

    /** The column on `outcome_result_refs` this kind uses, or null for an external reference. */
    public function column(): ?string
    {
        return match ($this) {
            self::ShopOrder, self::ErpOrder => 'facts_order_id',
            self::Conversation => 'conversation_id',
            self::ConversationMessage => 'conversation_message_id',
            self::ContentPublication => 'content_publication_id',
            self::External => null,
        };
    }

    /**
     * Whether a reference of this kind must always point at an HQ row. An ERP order may not have one yet; once
     * it has, the recorder requires the typed key too (D4 as ruled, docs/120 item 1).
     */
    public function requiresRow(): bool
    {
        return ! in_array($this, [self::ErpOrder, self::External], true);
    }

    /** @return list<string> every distinct target column */
    public static function columns(): array
    {
        return ['facts_order_id', 'conversation_id', 'conversation_message_id', 'content_publication_id'];
    }
}
