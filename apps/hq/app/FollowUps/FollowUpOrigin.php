<?php

namespace App\FollowUps;

/** Why the follow-up exists. `promise` is what docs/109's "promises" section will read (docs/113 section 2). */
enum FollowUpOrigin: string
{
    case Promise = 'promise';
    case Opportunity = 'opportunity';
    case Operator = 'operator';
}
