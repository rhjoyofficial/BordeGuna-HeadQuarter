# ADR-027: Autonomy governs proposed actions, not evidence gathering

**Status:** accepted and **implemented** (10 Sep 2026, Phase 2 M5). This is the one interpretation
M5 had to make in order to exist, so it is written down rather than left in a method.

## Context

`AutonomyLevel::A0` is defined as "runs on real inputs, writes what it WOULD do, **executes
nothing**". Joy's decisions V.2 and V.3 (8 Sep 2026) put both Phase 2 agents at A0 for seven days
before A1.

Read literally as "calls no tool at all", A0 makes the milestone impossible. Both agents are
read-only: their entire tool set is R1 and R2 reads. An agent that may call nothing gathers no
evidence, produces no answer, and the seven-day shadow period ends with nothing to review — which
defeats the decision the period exists to serve. The safety gate `docs/48` M5 defines ("the shadow
log is reviewed with Joy before either agent is allowed to answer him for real") presupposes a
shadow log with something in it.

Read as "executes no proposed action", A0 is exactly the control that was wanted, and the seven days
produce the evidence they were meant to.

## Decision

**Autonomy governs what an agent may CAUSE, not what it may READ.**

- An R1 or R2 read has no effect on the world. It is the agent perceiving, not the agent acting, and
  it is already governed by four other things: the tool must exist in the deployed code, be enabled
  at runtime, be granted to that agent, and be `agentExecutable`; and every call still goes through
  the permission intersection, policy, consent, credentials and the audit trail.
- Anything that writes, or reaches R3, is an **action**. `AgentRunner` refuses to execute one unless
  the resolved level `allowsExecution()` (A2 or above), and records it as a proposal instead, with
  the reason and the level that bound it.
- What A0 withholds is the **answer**. `agent_runs.shadow` is derived from the resolved level, never
  set by a caller, and a shadow run's answer is labelled as not-HQ's-answer everywhere it appears.

The architecture already drew this line and this ADR only makes it explicit. ADR-024 says reading a
customer's message may inform a proposal and may never be the reason an action became automatic —
which is the same statement about the same boundary, arrived at from the injection side.

## Alternatives

- **A0 means no tool call whatsoever.** Rejected: it makes a read-only agent inert, produces no
  shadow evidence, and would have forced the seven-day period to be skipped or faked. It also
  protects nothing: the reads are of data the enabling human can already read, under their own
  permissions.
- **Drop A0 and start the agents at A1.** Rejected: it is Joy's decision V.2, and A1 would mean the
  agent answering him for real from day one with no reviewed baseline to widen from later.
- **Leave the rule in the runner's code and not in an ADR.** Rejected. It is an interpretation of a
  decision somebody else made, and an unrecorded interpretation is one the next person silently
  re-decides.

## Consequences

In Phase 2 the branch that refuses an effect tool is **unreachable**: neither agent holds a tool that
writes. That is the point of writing it now. The first Phase 3 write tool meets a rule rather than an
absence, and the rule was written while nothing depended on it.

It also means running the safety corpus against a live agent on production is safe, because a corpus
case cannot cause an effect at A0 no matter what the model proposes.

⚠ The rule is enforced in two independent places, deliberately. `AgentRunner::hasEffect()` refuses
the call, and the `PolicyEngine` would refuse it anyway because no write tool is `agentExecutable`.
Neither is load-bearing alone.
