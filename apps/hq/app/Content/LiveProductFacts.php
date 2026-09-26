<?php

namespace App\Content;

use App\Models\ContentDraft;
use App\Research\ResearchRefused;

/**
 * Reads a draft's product facts again, live, at the moment of publication (docs/85 C.12 point 2).
 *
 * ⛔ Not wired yet: it refuses. Wiring it to the ERP and shop read tools (`erp.lookup_sku`,
 * `erp.read_price`, the shop's product API) is part of gate P6-G3, together with registering a
 * publisher. Until then, nothing can be published even if every other switch were on, because the
 * re-check against live facts cannot run, and a publication without it is refused.
 */
class LiveProductFacts
{
    /** @return list<ProductFact> */
    public function reread(ContentDraft $draft): array
    {
        throw new ResearchRefused('The live re-read of product facts at publish time is wired at gate P6-G3; nothing is published before it.');
    }
}
