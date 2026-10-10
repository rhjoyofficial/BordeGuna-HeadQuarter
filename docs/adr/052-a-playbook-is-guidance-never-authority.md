# ADR-052: A playbook is guidance, never authority

**Status:** accepted (10 Oct 2026, docs/112, ruling docs/103 §8). Enforced structurally by
`PlaybookStructureTest`; written down so no future convenience erodes it.

## Context

The business has rules an agent should know: who may approve a discount, which complaint goes to a
person at once, which claim about a product may be made and which may not. docs/95 V19 called this the
living playbook. The temptation, once such a store exists, is to let it DO things: a pricing playbook
that the gateway consults to decide a discount, an escalation playbook that the workflow engine reads to
route a thread, a claims playbook that lifts a content check. Each would be one small read of one table,
and each would turn prose a person typed into an authority decision no reviewer of that prose knew they
were making.

HQ already holds the opposite rule for the two nearest things. `owner_preferences` enter the context as
`Business` data so that "always approve" written as a preference cannot mean it (ContextAssembler).
`language_policies` are versioned, activated by a named person, and enforced by the runtime, but the rule
is a closed set of keys the code understands, not free text.

## Decision

**A playbook is read-only business guidance. It is read in exactly one place, context assembly, as
`Business`-provenance data behind a `System` framing block that says what it is not. Nothing that decides
reads it.**

- Not the autonomy resolver, not the Tool Gateway, not the policy checks, not the consent ledger, not the
  execution-context resolver, not the workflow engine, not a scheduled job. `PlaybookStructureTest` holds
  this by tokenising `app/` and refusing any reference to the loader outside `ContextAssembler`, and any
  mention of the word under the deciding namespaces.
- Provenance is `Business`, not `System`: a person inside the group wrote it, so it is trusted data; it
  is still not an HQ instruction, and only `System` may define behaviour (ADR-024).
- The framing block is belt, not braces: it tells the model that a playbook grants no permission,
  changes no policy or consent, does not change autonomy, and that a live tool result beats it
  (ADR-033; docs/48 I.2 precedence). Enforcement is that the deciding code never sees the text.
- Changes are human acts: draft, review, activate, each by a named enabled person holding its own
  permission, with a reason, an append-only event and an audit row; one active version per
  (business, brand-or-business-wide, kind); every version kept; the database refuses the rest
  (`playbook_versions` triggers). No model and no job writes a playbook (docs/103 §11).
- Every run records the id and content digest of each playbook it read (`agent_runs.playbooks`), so a
  wrong answer can be traced to the exact text, and the text to the person who activated it.

## Alternatives

- **Let the gateway read the pricing playbook to decide discounts.** Rejected. A discount rule the
  gateway enforces belongs in the policy engine as a typed rule a test can hold, not in prose. When the
  owner wants a rule enforced, it is written as a policy and the playbook explains it; the two are
  different tables with different contracts, by design (the same reasoning as ADR-033's second
  alternative).
- **`System` provenance, so the model treats it as an instruction.** Rejected. The playbook would then
  be the one place a person who is not a developer can write text the model reads as HQ's own
  instruction, with no review of what the text can cause. `Business` provenance keeps it on the data
  side of the boundary while still trusted enough not to cap autonomy.
- **Retrieval by similarity over the playbook texts.** Rejected for now (non-goal). Selection is by key
  (business, brand, kind), so what a run read is a short list, not a ranking nobody can reproduce.

## Consequences

The owner can write and change his business rules without a deploy and without a developer, and an
agent reads them the next run. What he cannot do from that screen is widen anything, which is exactly
what a reviewer of prose should not be asked to guard against.

⚠ It costs a duplication the owner will notice: a rule he wants enforced is written twice, once as a
policy (enforced, typed) and once in the playbook (explained, prose). That is the intended trade; the
day the two disagree, the policy wins and the run record shows which playbook text the model read.
