# ADR-021: Consent is an append-only ledger, enforced at the gateway

**Status:** accepted (8 Sep 2026, approved by Joy against docs/53) and **IMPLEMENTED** (9 Sep 2026,
Phase 2.5 B, docs/57). `consent_events` is append-only and immutable at the database level,
`consent_state` is a rebuildable projection, and `ConsentGate` runs inside the Tool Gateway.
`customers.consent` survives as a legacy column with nothing reading it as authoritative; migrating
its values into the ledger is still outstanding and is noted in docs/57 K.6.

## Context
Consent today is `customers.consent`, a mutable jsonb column holding
`{marketing_sms, marketing_whatsapp, marketing_messenger, source, at}`.

A mutable current-state column answers "may we contact this person now". Compliance asks a different
question, months later, about a specific message somebody is complaining about: **"was consent valid
at the moment we sent that"**. The value that was true at send time has since been overwritten, so
the column cannot answer, and no amount of care at send time creates the record afterwards.

This is the same argument that made `audit_logs` immutable and `decision_records` append-only.

## Decision
Consent becomes an append-only ledger with a derived current-state projection.

`consent_events` records customer, business, channel, purpose (service, marketing or research),
state (granted, withdrawn or expired), basis, evidence reference, when it occurred and when it was
recorded. Rows are never updated. `consent_state` is a projection for fast reads, rebuildable from
the ledger at any past instant.

**Consent is checked at the Tool Gateway**, for any tool declaring a consent requirement in its
descriptor (ADR-026), not inside each tool's body. A check inside each tool is a check that will
eventually be forgotten in one of them, and the one it is forgotten in will be the newest channel.

The ledger covers more than messaging consent: do-not-contact at the person level rather than per
channel, channel-specific restrictions such as the WhatsApp service window, call recording
permission for Phase 7, synthetic voice and face consent with an allowed-use scope for Phase 8,
brand and business restriction, expiry and revocation, and retention policy per data class.

**Withdrawal takes effect immediately and is never inferred.** A suppression is a recorded event,
not the absence of a grant.

## Alternatives
- **Keep the jsonb column and add history later.** Rejected: the history that matters is the one
  covering messages already sent, and it cannot be reconstructed.
- **Check consent inside each channel adapter.** Rejected: adding a channel would be able to add a
  channel that forgot to ask.

## Consequences
Phase 3 cannot send an outbound message without consulting the ledger. Phase 5 audience eligibility
derives from it rather than from campaign settings. Phases 7 and 8 have somewhere to record
recording and synthetic identity consent instead of inventing their own. The existing
`customers.consent` column becomes a migration source and then a legacy field.
