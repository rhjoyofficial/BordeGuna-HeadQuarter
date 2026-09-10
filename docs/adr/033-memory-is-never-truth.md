# ADR-033: Memory is never truth

**Status:** accepted (10 Sep 2026). Enforced by the existing separation; written down so no future
retrieval path erodes it.

## Context

HQ holds seven kinds of information and they are not interchangeable:

| Kind | Where | What it is |
| --- | --- | --- |
| system-of-record facts | the ERP, the shops | authoritative. HQ does not own them (ADR-007) |
| operational read models | `facts_orders`, `facts_erp_snapshots` | a **copy with an age** |
| episodic memory | `decision_records`, past runs | what happened, and what a human made of it |
| semantic / inferred memory | `memories` | something concluded, not something read |
| agent beliefs | a model's output | not evidence of anything |
| decisions | approvals, `decision_records` | what a human chose |
| evaluation evidence | `evaluation_*` | what was measured |

The failure this prevents is laundering. Untrusted or inferred information goes in, a summary comes
out, the summary is stored, and three weeks later something reads the summary as a fact. The dangerous
step is the one that looks most helpful: writing down what was concluded, in the same shape as what
was read.

`Provenance::Generated` already exists and is already **not trusted**, with the reason stated:
"a model's own prior output can carry an injection it read earlier, and treating it as trusted is how
a laundering path opens".

## Decision

**No generated memory and no model belief may become authoritative business truth.**

- **A read model is a copy with an age, and says so.** `facts.snapshot_freshness` exists so that an
  answer can lead with what it does not cover. An operator who acts on a stale figure without being
  told it was stale has been misled by an accurate number.
- **Prices, stock, order status and balances are never memory.** They are looked up every time. This
  is `docs/09`'s rule and `docs/48` I.2's precedence order: live tool result beats retrieved memory
  beats stored preference beats prompt example, and the model must say when it is overriding
  remembered information with a live fact.
- **Memory carries provenance, and keeps it.** A block that loses its label on the way into memory is
  a delayed injection with the label removed (ADR-024). `MemoryRecord` carries provenance for exactly
  this reason.
- **The rule is independent of transport.** It holds whether the information arrived through a direct
  API, an MCP server, an internal service, a retrieval system, or model-generated context. A protocol
  is a way of moving bytes; it confers no authority on them (ADR-029).
- **Writing to a system of record is a tool call, never a memory write.** If HQ believes something the
  ERP should know, the way to make it true is to call the ERP through the gateway and verify the
  postcondition (ADR-031) - not to record HQ's belief and let it propagate.

## Alternatives

- **Promote a sufficiently confident inference to a fact.** Rejected. Confidence is a property of the
  model, not of the world, and a threshold would be a number chosen once and then trusted forever.
- **One "knowledge" store with a source field.** Rejected: it makes the distinction a column that a
  query can forget to filter on, rather than a different table with a different contract. The whole
  point is that reading truth and reading recollection should not be the same call.

## Consequences

An agent can be wrong without HQ becoming wrong. A bad inference stays labelled as an inference and
is visible as one in every run record, which is also what makes the review in ADR-034 possible.

⚠ It costs a lookup. Answering "what is the stock" always calls something, and cannot be served from
memory even when memory almost certainly knows. That is the intended trade: the one time it is wrong
is the time somebody promises a customer an item that does not exist.
