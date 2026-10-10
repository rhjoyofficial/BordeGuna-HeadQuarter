<?php

namespace App\FollowUps;

/** docs/113 Q5: the first list; the business may name its own. */
enum OpportunityKind: string
{
    case Reorder = 'reorder';
    case Quotation = 'quotation';
    case Wholesale = 'wholesale';
    case ProductInterest = 'product_interest';
    case Other = 'other';
}
