# ADR-025: HUMAN_ONLY is a permanent declaration, distinct from "not yet agent-executable"

**Status:** accepted and **implemented** (8 Sep 2026, Phase 2 M4, PR #6). The one item from docs/53
pulled forward, because it is cheap before the tool count grows and archaeology afterwards.

## Context
ADR-017 established that a tool declaring `agentExecutable: false` reads as `ActionClass::HumanOnly`,
is checked before everything else, and cannot be lifted by any approval. That mechanism is correct
and is unchanged by this decision.

What it could not do is say **why**. All seven registered tools declare `agentExecutable: false`
because none has been cleared for an agent yet, not because none may ever be. Those are different
statements. One is a stage that evaluation evidence can end (ADR-023); the other is a decision about
what the tool is. With seven tools the difference is obvious to anyone who reads them. With fifty, on
the day somebody is widening autonomy, the permanently forbidden ones would look exactly like the
merely uncleared ones.

## Decision
`ToolDescriptor` gains `permanentlyHumanOnly`, defaulting to false. It is additive: no existing tool
changes, and no behaviour changes, because both cases still refuse an agent before anything that
could be relaxed is considered.

What changes is that the refusal names which case it is. The reason code splits into
`action_class.human_only` and `action_class.human_only_permanent`, so the distinction lands in the
audit trail where somebody reads it rather than in a boolean nobody prints. `hq:tools:sync` shows
NEVER, "not yet", or yes.

**The contradiction is refused at construction.** A descriptor declaring `permanentlyHumanOnly` and
`agentExecutable` together throws, so the mistake fails the build at the line that made it. Silently
resolving it in either direction would be worse: a tool quietly agent-executable while a document
still calls it human-only, or one quietly refused while its author believes it enabled.

The permanent class covers moving money; changing credentials, permissions, roles or scopes;
changing autonomy itself; overriding or deleting consent; granting synthetic identity consent;
altering audit logs, decision records or the consent ledger; legal, regulatory and tax statements;
final pricing policy; employment and payroll decisions; mass outbound above a human-set threshold;
accepting liability on the group's behalf; and any write to a system of record where HQ is not
authoritative (ADR-007, ADR-015).

## Alternatives
- **Replace the boolean with a three-state enum.** Rejected for now: it would touch every descriptor
  and the whole safety test surface for a distinction one additive flag already expresses.
- **Keep the boolean and record permanence in documentation.** Rejected: a rule that lives only in a
  document is a rule a code change can contradict without failing.

## Consequences
Today every registered tool is "not yet" and none is permanent, which is the honest state: the seven
are diagnostics, polls and reconciles, and none of them is a decision to forbid forever. A test
asserts exactly that, so the first genuinely permanent tool must be a deliberate, visible change to
that list rather than one more `false` nobody looked at.
