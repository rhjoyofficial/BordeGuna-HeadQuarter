# ADR-023: Evaluation evidence is the precondition for widening autonomy

**Status:** accepted (8 Sep 2026, approved by Joy against docs/53). **Not implemented.** Scheduled
for Phase 2.5.

## Context
The project has said since Phase 0 that autonomy is earned against a measured agreement rate and
never granted on a schedule, which is why `decision_records` existed before any agent did.

`docs/30` then plans Phases 9 and 10 to widen autonomy using "evaluation data from Phases 2-6".
Nothing in Phases 2 to 6 records evaluation data. There is no `evaluations` table, despite `docs/48`
listing one in M1's dependency detail, and the M1 record does not claim to have delivered it.

The consequence is concrete: without it, autonomy could only be widened by opinion, and every
argument this project has made about earning trust through measurement would be unsupported by
anything measurable.

## Decision
Evaluation is a shared subsystem built in Phase 2.5, before the first agent, not a later addition.

It stores evaluation cases with expected tool calls, refusals, citations and outcomes; graders that
are deterministic where possible and rubric-based where not; and runs stamped with the model, prompt
version, skill version, policy version and autonomy level in force, so a regression can be
attributed rather than merely noticed.

It must measure answer quality, tool-call correctness, business-policy compliance, hallucination
rate, customer satisfaction, handover correctness, order correctness, campaign decisions, content
quality, autonomy agreement rate, false positives and negatives, and regression after any prompt,
skill, model or routing change.

Two properties carry the weight. **Agreement rate is the currency of the autonomy ladder**: the
measured rate at which a reviewing human agrees with what the agent proposed, and it is what
ADR-020's evaluation-derived ceiling reads. And **the safety corpus is permanent**, covering
injection attempts, credential requests, cross-business data requests and requests for raw SQL, with
expected refusals; a regression in it blocks a release rather than filing a ticket.

## Alternatives
- **Widen autonomy on judgement and review the logs.** Rejected: that is the thing the project
  decided against before it had agents.
- **Build evaluation per agent when each agent needs it.** Rejected: cross-agent regression becomes
  invisible and autonomy cannot be earned on comparable evidence.

## Consequences
Phase 2.5 gains `evaluations` and `evaluation_runs`. Phases 9 and 10 are rewritten to consume
evidence rather than assume it. No autonomy level above A1 may be granted for a combination with no
evaluation history. `docs/48`'s claim that M1 delivered an evaluations migration is corrected.
