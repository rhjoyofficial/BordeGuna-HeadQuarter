# ADR-048: Autonomy evidence is a governed, versioned policy, produced from results and applied as a ceiling

**Status:** accepted and **implemented** (21 Sep 2026, `docs/81` C.4), **nothing switched on**: no
policy version exists in any environment, no seeder or migration writes one, every numeric field is
undecided until an owner decides it, production autonomy grants stay at zero. Written for ChatGPT's
instruction of 21 September (verbatim in `docs/81` section A, point 5, respecting point 13).

## Context

ADR-023 made autonomy earned: a grant may require a measured agreement rate before it applies.
ADR-030 split the single rate into six dimensions and refused the overall number as a stand-in for
any of them. Reading the code for `docs/81` found three gaps between those decisions and what ran:

1. **No requirement existed per level or per dimension.** The only requirement anywhere was a number
   on one grant (`autonomy_grants.min_agreement_rate`). Nothing said, once, what A1 or A2 needs.
2. **The link had no producer.** `AgentRunner::resolveAutonomy()` asked the resolver with no rate at
   all, so a grant requiring evidence could never match. Fail closed, and ADR-023 unreachable.
3. **The resolver ignored a grant's `evaluation_suite_key`.** It compared the grant with whatever
   pooled rate a caller passed. Harmless while nothing passed a rate; not harmless once a producer
   exists.

## Decision

**1. The policy is configuration with a history, not a number in code.** `autonomy_evidence_policies`
holds versions (`proposed`, `active`, `retired`); `autonomy_evidence_requirements` says, per level
(A1 to A4) and per ADR-030 dimension, the minimum agreement rate, the minimum number of measured
results, the maximum age of the evidence and the required measured state (only `fully_measured`
exists); `autonomy_evidence_hard_checks` names grader checks that must be 100 percent for a level;
`autonomy_evidence_policy_events` is the append-only history. There is no `overall` row.

**2. No number is invented.** Every numeric field is nullable and NULL means "not decided". A
proposal may hold NULLs, and that is how the undecided is written down and shown. A version holding
one can never become active: the domain class refuses it and a Postgres trigger refuses it again
underneath. Tests carry their own values, labelled as test values.

**3. Governance is a named human act.** `App\Autonomy\Evidence\EvidencePolicies` is the one writer;
the console is the only surface (`hq:autonomy-policy:propose|activate|retire|show`, each a dry run
until `--apply`). Activation needs an enabled user holding `hq.policies.manage` and a reason, is
audited under that person, writes an event with a snapshot and a digest, and retires the previous
version in the same transaction. At most one version is active (a partial unique index). An active
or retired version and its rows are immutable at the database level; a change is a new version.

**4. Evidence is produced from rows, never from a stored rate.** `EvidenceProducer::measure()` takes
the latest finished run of each applicable suite and recounts its role-applicable results, with the
run and result ids behind every figure. The autonomy evidence view is built on the same code, so the
page cannot show a figure the runtime would not use. It writes nothing.

**5. The policy is a ceiling, never a grant.** `judge()` finds the highest level the evidence
reaches under the active version; the runner passes it to the resolver as one more ceiling, combined
by MINIMUM with the agent's, the compliance and the context ceilings. It can only lower an answer.
With no matching grant the answer is still A0. Nothing creates, edits or promotes a grant (point 13).

**6. The rules fail closed.** Levels are cumulative. A requirement is met only on the dimension's own
measured rows, with enough of them, the pooled rate AND each contributing suite's rate at or above
the minimum in whole thousandths, every contributing run recent enough, on the current prompt stamp,
passed, fully measured and recounting to what it stored, and every declaring suite run. A hard check
is zero failures and enough passes. More result rows than the producer will read counts nothing.

**7. Rates reach a grant only when clean.** With a policy active the runner also passes the overall,
per-dimension and per-suite rates, floored to thousandths, and only for clean evidence. A grant
naming a suite is compared with that suite's own figure, never the pooled one (gap 3 closed).

**8. With no active policy nothing changes.** `forRunner()` returns null after one lookup, the
request is what it was, the decision record has no new key, `agent_runs.autonomy_evidence` is null.
The pinned identity case stayed green without re-pinning.

## Alternatives considered

- **Thresholds on each grant only (today).** Rejected: the same level would mean different things
  on different grants, and nothing would say what a level needs before a grant is written.
- **Default numbers in the migration or a seeder.** Rejected by instruction: a shipped number is an
  invented number, and it would be in force the moment the table existed.
- **The policy as sufficient (a met level grants autonomy).** Rejected: a grant is the owner's
  decision about a combination; evidence is necessary for it, never a substitute (point 13).
- **A cache of the evidence.** Rejected: one latest-run lookup per suite and one bounded results
  query, and a cache would bring back the stale-read question `docs/81` C.1 removed elsewhere.

## Consequences

- A grant requiring evidence can now match, but only while a policy version is active. Until an
  owner activates one, every grant requiring evidence stays unmatched, exactly as before.
- Activating a version is the owner's decision and needs his numbers. Which levels, which dimensions,
  which rates, sample counts, ages and hard checks are listed in `docs/81` C.4 as the decision left.
- The run row and the decision record carry the policy version, its digest, the evidence digest and
  the run ids, so a run's autonomy can be traced to the exact rules and results it was judged on.
