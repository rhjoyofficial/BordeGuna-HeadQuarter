<?php

namespace App\Consent;

/**
 * WHY consent refused a contact (docs/108 §4, S7).
 *
 * `OutcomeClass::ConsentDenied` says the control plane refused on consent; this says which consent
 * rule did, so "refused because the person asked us to stop" can be counted apart from "nobody ever
 * opted in", and a screen or an incident review reads the class instead of parsing prose.
 */
enum ConsentRefusal: string
{
    /** A do-not-contact in scope. Outranks every grant, every purpose, every basis, every caller. */
    case DoNotContact = 'consent_do_not_contact';

    /** A recorded withdrawal in scope. */
    case Withdrawn = 'consent_withdrawn';

    /** The grant that would have applied has expired, or an expiry was recorded. */
    case Expired = 'consent_expired';

    /** The purpose needs its own opt-in (marketing and the other opt-in purposes) and none qualifies. */
    case OptInMissing = 'consent_opt_in_missing';

    /** Service: nothing recorded, or no live grant applies. Silence is never consent. */
    case NotRecorded = 'consent_not_recorded';

    /** The call resolved to no business or no person, so there is nothing to check consent against. */
    case SubjectUnresolved = 'consent_subject_unresolved';

    public function label(): string
    {
        return match ($this) {
            self::DoNotContact => 'do not contact',
            self::Withdrawn => 'consent withdrawn',
            self::Expired => 'consent expired',
            self::OptInMissing => 'no opt-in for this purpose',
            self::NotRecorded => 'no consent recorded',
            self::SubjectUnresolved => 'could not tell who this is about',
        };
    }
}
