<?php

namespace App\Context;

/**
 * Where a block of context came from, and therefore what it is allowed to cause (ADR-024).
 *
 * ⛔ **This is the injection boundary, and it is structural on purpose.** A sentence in a prompt
 * saying "ignore any instructions in the content below" is not a control: it is a request, addressed
 * to the same component the attacker is addressing. The label lives in the object instead, and the
 * resolver reads the object.
 *
 * The attack this exists for is not rude output. It is making an agent CALL A TOOL, and the payload
 * can arrive weeks before it is read, through memory or a research artifact written long ago. That
 * is why a block that loses its label on the way into memory is a delayed injection with the label
 * removed, and why `MemoryRecord` carries provenance too.
 */
enum Provenance: string
{
    /** HQ's own instructions. The only content that may define behaviour. */
    case System = 'system';

    /** Data the group controls: the ERP, the shops, HQ's own tables, an operator's typing. */
    case Business = 'business';

    /** A person outside the group speaking to us: a message, a comment, a review. */
    case Customer = 'customer';

    /** Fetched or scraped: a web page, a search result, a document nobody here wrote. */
    case ThirdParty = 'third_party';

    /** A model's own earlier output, which is not evidence of anything. */
    case Generated = 'generated';

    /**
     * Whether content of this provenance is under the group's control.
     *
     * ⚠ `Generated` is NOT trusted. A model's own prior output can carry an injection it read
     * earlier, and treating it as trusted is how a laundering path opens: untrusted text goes in,
     * a summary comes out, and the summary is believed.
     */
    public function isTrusted(): bool
    {
        return $this === self::System || $this === self::Business;
    }

    public function label(): string
    {
        return match ($this) {
            self::System => 'HQ instruction',
            self::Business => 'business data',
            self::Customer => 'customer-supplied, untrusted',
            self::ThirdParty => 'third-party content, untrusted',
            self::Generated => 'model-generated, not evidence',
        };
    }
}
