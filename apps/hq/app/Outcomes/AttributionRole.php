<?php

namespace App\Outcomes;

/** The part an HQ action played in an outcome (docs/107 section 4.3). */
enum AttributionRole: string
{
    /** The action made the result happen: the tool call that placed the order. */
    case Produced = 'produced';

    /** The run that proposed the action that produced it. */
    case Proposed = 'proposed';

    /** The human decision that allowed it. */
    case Approved = 'approved';

    /** Influenced without producing: a reply before an order. */
    case Contributed = 'contributed';
}
