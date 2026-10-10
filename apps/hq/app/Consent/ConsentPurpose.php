<?php

namespace App\Consent;

/**
 * WHY the group is contacting or recording someone (ADR-021).
 *
 * Purpose is separate from channel because the same channel carries both kinds of message and they
 * are not governed alike. Answering "where is my order" on WhatsApp is service: the customer
 * started it and expects a reply. Sending a discount on the same thread is marketing and needs its
 * own grant. Collapsing the two is how a business ends up believing an order enquiry was permission
 * to advertise.
 */
enum ConsentPurpose: string
{
    /** A reply or notification about something the customer already asked for or bought. */
    case Service = 'service';

    /** Promotion, offer, campaign, re-engagement. */
    case Marketing = 'marketing';

    /** Surveys, interviews, feedback gathering. */
    case Research = 'research';

    /** Recording a call or meeting (Phase 7). */
    case Recording = 'recording';

    /** Use of a person's likeness or voice in synthetic media (Phase 8). */
    case SyntheticIdentity = 'synthetic_identity';

    /**
     * Whether the absence of any recorded event may be treated as permitted.
     *
     * ⛔ False for every case, deliberately, and this method exists to say so once rather than have
     * each caller decide. Service messaging feels like the exception, and it is not: the ledger is
     * the record that a service relationship existed at that moment, and "we assumed" is precisely
     * what an incident cannot be answered with.
     */
    public function permittedWithoutRecord(): bool
    {
        return false;
    }

    /**
     * docs/108 §3.4: whether this purpose needs its OWN opt-in. Only a grant of exactly this purpose,
     * on a basis that can evidence an opt-in, satisfies it; a wildcard grant (purpose null) or a
     * customer's message never does. Service is the one purpose a broader grant may cover.
     */
    public function requiresOptIn(): bool
    {
        return $this !== self::Service;
    }
}
