# ADR-031: Consequential execution is verification-first

**Status:** accepted as the governing pattern (10 Sep 2026). **Specified, not implemented** - HQ has
no agent-executable write tool, so there is nothing to verify yet. It is written now so the first one
meets a pattern rather than an absence.

## Context

The target is not an AI that makes no mistakes. It is a system where **errors are detectable,
contained, recoverable, auditable, and never silently accepted as truth.**

The failure this ADR exists for is the most ordinary one in automation: **a successful request is
treated as a completed effect.** An HTTP 200 means the far side accepted the bytes. It does not mean
the message reached a person, the order exists, the post is visible, the refund settled, or the
parcel was booked. Every one of those has an independent state that can be read back, and the gap
between "the call returned" and "the thing is true" is where autonomous systems accumulate silent
wrongness.

HQ has already been bitten by the reverse of this and knows the shape. `docs/47` found HQ holding one
order line for an order that had two, with nothing anywhere reporting a problem, because the event
that would have corrected it was never emitted. Nothing failed. The record was simply wrong, and
stayed wrong, and every number built on it was quietly wrong too.

## Decision

Every consequential capability is designed against this sequence:

```
precondition
  → proposal          (structured, from the model; never an invocation)
  → policy            (may this class of action happen at all)
  → authorization     (may THIS caller, at this identity, scope, consent, autonomy)
  → execution         (through the gateway, under an idempotency key)
  → postcondition     (read the effect back from the system of record)
  → reconciliation    (make HQ's record agree with what is actually true)
  → compensation / retry / escalation
```

Four rules bind it:

1. **A provider's success response is not a postcondition.** Where the effect can be read back, it is
   read back, from the system of record rather than from the response that claimed it. Where it
   genuinely cannot be, the capability says so and is treated as unverifiable - which is itself a
   reason to keep it human-only for longer.
2. **Where an effect is reversible, model the compensating action.** `ToolDescriptor` already carries
   `Reversibility` and refuses `Compensable` without naming a `compensatingTool` - "a compensable
   effect that does not say what compensates it is an irreversible one with better manners".
3. **Where an effect is not safely reversible, the human-only boundary stays.** Verification does not
   earn autonomy for something that cannot be undone; it earns autonomy for something that can be
   *checked*, which is a different claim.
4. **An unverified effect is `uncertain`, never `succeeded`.** The gateway already distinguishes
   these and already reconciles an uncertain call before retrying (ADR-011). This extends the same
   discipline from delivery to outcome: the honest state after an unread postcondition is "we do not
   know", and a system that cannot say that will eventually assert something false.

⚠ **Verification is a dimension, not a feeling.** `execution` and `outcome` are two of the six in
ADR-030 precisely so that "the call worked" and "the thing is true" accumulate as separate evidence,
and so that autonomy over a capability can be gated on the second rather than the first.

## Alternatives

- **Trust the provider response and reconcile nightly.** Rejected as the default. Nightly
  reconciliation is a good safety net and a bad primary control: for a customer-visible action the
  damage is done in minutes, and a net that catches it the next morning catches it after the customer
  has already read the wrong thing.
- **Verify everything, including reads.** Rejected: a read has no postcondition to establish, and
  paying for one on every call buys nothing while making the expensive path look ordinary.
- **Implement the pattern now.** Rejected. There is nothing to verify: no agent-executable write tool
  exists. Building the machinery before its first consumer would fix its shape around an imagined
  case, which is how ADR-013's "no fine-tuning" and ADR-022's capability flags both nearly went wrong.

## Consequences

Phase 3 and everything after it inherit a sequence rather than inventing one per capability. The
first write capability implements it fully and becomes the reference.

⚠ Nothing in HQ implements postcondition verification today, and the honest reading of that is: **HQ
is not yet able to run a consequential action autonomously, and this ADR is a precondition for it
rather than a description of it.**
