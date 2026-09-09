# ADR-024: Untrusted content is labelled, and can never increase autonomy

**Status:** accepted (8 Sep 2026, approved by Joy against docs/53) and **IMPLEMENTED** (9 Sep 2026,
Phase 2.5 G, docs/57). `Provenance`, `ContextBlock` and `ContextBundle` label content structurally;
`AutonomyResolver` reads the label and caps at A1; conversation messages and memory rows carry
provenance so a block cannot lose it on the way into storage. A model's own output is labelled
`generated` and is NOT trusted, which closes the laundering path.

## Context
Today no model has ever been called and no tool is agent-executable, so the threat surface is small.
Both change in Phase 3.

A customer message, a Facebook comment, a product review and a fetched web page are all text the
business does not control, and all of it would be placed in a model's context. The attack is not
making the model say something rude. It is **making the agent call a tool**, and the payload can
arrive weeks before it is read, through memory or a research artifact written long ago.

Prompt-level defences of the "ignore any instructions in the content below" kind are not a control.
They are a request, addressed to the same component the attacker is addressing.

## Decision
Three structural rules.

**Content carries provenance.** Every block placed in a model's context is labelled as trusted
business data or untrusted third-party content. The boundary is structural, part of how context is
assembled, rather than a sentence inside a prompt.

**Untrusted content can never raise autonomy.** A request whose context contains untrusted content is
capped by ADR-020's resolver. Reading a customer's message may inform a proposal; it may never be the
reason an action became automatic.

**Arguments derived from untrusted text stay fully controlled.** Schema validation, the canonical
payload hash and the single-use approval binding already exist from M1 and M3, and nothing about a
model having proposed the arguments exempts them from any of it.

## Alternatives
- **Rely on prompt instructions.** Rejected above.
- **Refuse to process untrusted content.** Rejected: reading it is the entire point of customer AI.
  The answer is bounding what reading it can cause, not refusing to read.

## Consequences
Context assembly in Phase 2.5 carries provenance from its first version rather than gaining it later.
The research engine in Phase 6 inherits the same treatment for fetched pages. Memory carries the
provenance of what was written into it, because a labelled block that loses its label on the way into
memory is a delayed injection with the label removed.
