<?php

namespace App\FollowUps;

/**
 * A person's word on why an opportunity closed (docs/113 F4). `converted` is NOT an attributed outcome:
 * nothing here reads or writes the S3 ledger; when S3 is agreed the word is checked against it.
 */
enum OpportunityCloseReason: string
{
    case Converted = 'converted';
    case Lost = 'lost';
    case NoResponse = 'no_response';
    case Duplicate = 'duplicate';
    case Other = 'other';
}
