# ADR-020: The Autonomy Control Plane is a separate resolver from the Policy Engine

**Status:** accepted (8 Sep 2026, approved by Joy against docs/53). **Not implemented.** Scheduled
for Phase 2.5. Today autonomy is the `AutonomyLevel` enum and the single `agents.max_autonomy_level`
column, which stays as-is until 2.5 replaces it.

## Context
The target requires autonomy to vary across business, brand, channel, agent, tool, action, monetary
value, customer segment, environment, schedule, confidence and compliance state. One column on
`agents` cannot say "may answer order-status questions on Messenger for returning customers during
business hours, but never on WhatsApp, never about refunds, and never above five thousand taka".

The tempting move is to put autonomy rules into the policy engine, which already resolves rules by
specificity. That would be wrong, and the reason is not organisational.

## Decision
Autonomy is resolved by a separate component, and the reason it must be separate is that it combines
differently.

**The Policy Engine is first match wins, no merging.** That is correct for "which rule governs this
call": one rule is the governing one, and merging would make it impossible to say which.

**Autonomy combines by MINIMUM.** Every applicable constraint binds at once. The agent's cap, the
tool's cap, the channel's cap, the compliance ceiling and the evaluation-derived ceiling are all
simultaneously true, and the effective level is the lowest of them. Bolting that onto a first-match
engine would corrupt a working engine to express a different idea.

Also decided:

- **Fail closed.** An unresolvable or conflicting configuration yields A0, never the benefit of the
  doubt.
- **Absence means A0, not permission.** Note that this is the opposite of the `tools` table, where an
  absent row means enabled. That is deliberate: an absent tool row means a capability the deployed
  code already contains and a human already reviewed, while an absent autonomy grant means a
  combination nobody has evaluated. Both defaults are safe; they point in opposite directions
  because they answer different questions.
- **A5 exists as a state and remains unreachable.** Defining the whole ladder now stops the enum
  being edited later; leaving A5 unreachable stops it being used before anybody has decided what
  strategic orchestration means.
- **Autonomy never bypasses anything.** The resolver produces an INPUT to the existing gateway
  sequence. It cannot skip the Tool Gateway, Policy Engine, permission intersection, Approval
  Center, credential boundary, audit trail or idempotency controls. A higher autonomy level means
  fewer human interruptions, never a different code path. This should be enforced the way M4
  enforced the model boundary: a test asserting the resolver has no route to execution.
- **Grants are versioned and superseded**, the way `policies` already are, so a past decision stays
  reproducible.

## Alternatives
- **Extend the policy engine.** Rejected above: two different combinators in one component.
- **Keep one column per agent.** Rejected: cannot express channel, value or segment bounds at all.
- **Compute autonomy inside each agent.** Rejected: an agent that decides its own autonomy has none.

## Consequences
Phase 2.5 gains an `autonomy_grants` table, a resolver, and a test proving it cannot execute
anything. Every later phase expresses its autonomy bounds as grants rather than as code. The
existing `agents.max_autonomy_level` becomes one input among many rather than the answer.
