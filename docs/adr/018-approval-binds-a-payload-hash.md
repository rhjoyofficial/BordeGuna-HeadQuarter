# ADR-018: An approval binds a canonical payload hash and a tool version, and is single use

**Status:** proposed (Phase 2, docs/48)

## Context
`docs/13` already says "the stored payload is what executes on approval. The agent is never asked to
regenerate it, so what was approved is what runs." That is the right rule and it needs a mechanism,
because the attack it defends against is subtle and cheap: an agent proposes a harmless-looking
action, a human approves the card they read, and something else executes under that approval. It
does not require a malicious model, only a re-serialisation, a retry that rebuilds arguments, or a
row edited between decision and execution.

## Decision
1. The gateway stores the **validated** arguments, after schema validation and coercion. Not the
   model's raw output, and not a re-serialisation of it.
2. `approvals.payload_hash` is the SHA-256 of a canonical JSON encoding of that payload: sorted keys,
   no insignificant whitespace, fixed number formatting.
3. On execution the gateway recomputes the hash from the stored payload and compares. A mismatch
   cannot happen through the application, so it means the row was changed underneath it: execution is
   refused, an alert fires, the approval is marked `cancelled`.
4. `approvals.tool_version` is stored. An approval whose tool has since had a major version bump is
   refused rather than executed against a changed schema.
5. Approvals are single use (`consumed_at`). A replayed or stolen approval id executes nothing twice.
6. **No model call happens between approval and execution.** There is no window in which arguments
   could change, because nothing regenerates them.
7. "Approve with edit" does not mutate the approval. It creates a new row with the edited payload, a
   new hash and `superseded_by` linkage, plus a `decision_records` row holding proposal, final and
   delta.
8. Expiry never auto-approves. An ignored approval is a refusal.

## Alternatives
- **Trust the stored row without a hash.** Rejected: it makes database integrity the only defence and
  leaves no evidence when it fails.
- **Sign the payload with `APP_KEY` instead of hashing.** Considered. A hash is sufficient because
  the row is not transported outside the trust boundary, and it avoids making approval execution
  depend on key rotation.
- **Auto-approve on SLA expiry to keep throughput up.** Rejected outright: it makes every gate
  decorative under exactly the load that makes gates matter.

## Consequences
`approvals` carries `payload_hash`, `tool_version`, `consumed_at` and `superseded_by`. The canonical
encoder is a single tested function used by both the writer and the verifier, because two encoders
would eventually disagree and every approval would then look tampered with. Approve-with-edit
produces the highest-value signal the Behavior Alignment Layer receives, so the delta is captured
deliberately rather than as a by-product.
