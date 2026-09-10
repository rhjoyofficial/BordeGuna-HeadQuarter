# ADR-034: Approval is a stage that evidence can end. HUMAN_ONLY is a decision that it cannot.

**Status:** accepted (10 Sep 2026). The counterpart to ADR-025, which defined the permanent side.

## Context

HQ should eventually run without routine human intervention, with people moving from approving
ordinary actions to handling exceptions, governance, policy changes, irreversible events and genuine
uncertainty. That is the point of building the control plane rather than a set of AI features.

Two opposite mistakes lead away from it.

The first is **hard-coding human approval as permanent** for actions that could be proven safe. Every
R4 and R5 action requires approval today (decision V.1), which is right for a system with no
evaluation history. If that requirement is written as though it were a property of the action rather
than of the current evidence, HQ ends up with a human in a loop forever for a reason that expired
years earlier - and the approval becomes a rubber stamp, which is worse than no control because it
looks like one.

The second is **letting good behaviour dissolve a boundary that should never move.** An agent that
performs well for six months is an agent with six months of evidence, not an agent that may now move
money. Performance is not a licence.

ADR-025 already drew half of this: `permanentlyHumanOnly` is a decision, `agentExecutable: false` is
a stage. This ADR states the consequence for autonomy.

## Decision

**Approval-required is a stage. It ends on measured evidence, never on elapsed time, seniority, or
how well an agent has been doing.**

- Widening is expressed as an `autonomy_grant` naming the combination it applies to, with
  `min_agreement_rate` and, where the evidence exists, `min_agreement_dimension` (ADR-030). A grant
  whose evidence has not been measured **does not match**, so the safe state remains the absence of
  configuration rather than the presence of the right configuration.
- ⛔ **Time is not evidence.** The seven-day shadow period is not a waiting period after which
  autonomy arrives; it is how long it takes to accumulate enough reviewed runs to argue from. A
  period that elapses with nothing reviewed has produced nothing.
- Every widening is a change somebody makes, recorded, reversible, and attributable. Pausing an agent
  or revoking a grant returns the system to the previous posture immediately.

**HUMAN_ONLY is a decision, and no amount of evidence reaches past it.** ADR-025's list stands:
moving money; changing credentials, permissions, roles or scopes; changing autonomy itself;
overriding or deleting consent; granting synthetic-identity consent; altering audit logs, decision
records or the consent ledger; legal, regulatory and tax statements; final pricing policy; employment
and payroll decisions; mass outbound above a human-set threshold; accepting liability on the group's
behalf; and any write to a system of record where HQ is not authoritative.

⭐ **The two must stay distinguishable in the refusal itself**, not only in a flag. A refusal that
says "not yet" invites a different next step from one that says "never", and the day somebody widens
autonomy across fifty tools is the day that difference has to already be written down. It is, and
`hq:tools:sync` prints it as NEVER / not yet / yes.

## Alternatives

- **Keep human approval for every R4 and R5 action permanently.** Rejected as a *permanent* rule and
  kept as the *current* one. The distinction is the whole ADR.
- **Widen autonomy on a schedule once an agent looks reliable.** Rejected. "Looks reliable" is the
  thing evaluation exists to replace, and a schedule is a promise made before the evidence.
- **Let an agent request its own widening.** Rejected outright. Changing autonomy is on the permanent
  list, and an agent that can argue for more autonomy is an agent whose most valuable skill is
  arguing. `bypass.autonomy_self_grant` is a blocker case in the safety corpus for this reason.

## Consequences

The path to lights-out is a ladder with measured rungs rather than a date, and every rung is
reversible in one action.

⚠ It also means lights-out is **further away than an optimistic reading suggests**, because most
dimensions have no measurements yet and `execution` and `outcome` cannot be measured until something
executes. That is the honest position: HQ is currently a system that can *observe* well, and the
evidence base for acting autonomously does not exist yet.
