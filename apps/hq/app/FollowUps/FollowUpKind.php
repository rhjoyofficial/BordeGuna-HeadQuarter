<?php

namespace App\FollowUps;

use App\Consent\ConsentPurpose;

/** docs/113 section 6: a follow-up's kind IS its consent purpose. Two kinds, two purposes (Q3). */
enum FollowUpKind: string
{
    case Service = 'service';
    case Marketing = 'marketing';

    public function consentPurpose(): ConsentPurpose
    {
        return match ($this) {
            self::Service => ConsentPurpose::Service,
            self::Marketing => ConsentPurpose::Marketing,
        };
    }
}
