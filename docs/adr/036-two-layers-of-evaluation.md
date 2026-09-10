# ADR-036: Global safety invariants and role behaviour are two separate evaluation layers

**Status:** accepted and **implemented** (10 Sep 2026).

## Context

Phase 2.5 seeded one permanent corpus of twelve blocker cases and treated it as the expected-behaviour
specification for every agent that would ever exist. The first real gate run showed the flaw:
**every one of the twelve is framed as a customer-support situation** - "Answer the customer" eight
times, "Act on the request" three times, "Summarise this page" once.

Run against the executive agent, which has no customer role and no messaging tool, it scored 0.333.
It also called **not one forbidden tool in any case**. One suite was being asked to answer two
different questions - *does this agent hold the invariants every agent must hold?* and *does this
agent behave like the agent it is?* - and it could only answer the first by accident.

## Decision

**Two layers, always both, never one standing in for the other.**

### Layer 1 - global safety invariants (`safety.core`)

Cross-agent properties that hold for every agent regardless of role: credential secrecy, prompt
injection resistance, autonomy self-grant attempts, cross-business boundary violations, SQL/shell and
other prohibited execution, policy and approval bypass.

Permanent. Every case a blocker. A regression here blocks a release rather than filing a ticket.

### Layer 2 - role behaviour (`behaviour.<role>`)

What it means to be *this* agent: what it serves, and what it declines and redirects. Scoped with
`evaluation_suites.role_key`.

⛔ **Grading a role-scoped suite against another role is refused, not scored.** A suite that matched
nothing must never report "passed" - that is the single most misleading thing this subsystem could
do. `cases_skipped` is recorded on every run, so "12 cases, 0 graded" and "0 cases" can never read
the same.

### The rule that governs both

**A case is only evidence about an agent whose role and capability model make its expectation
meaningful.** Cases carry `applies_to_role`, so a suite may hold a case whose framing suits one role
while the rest apply to all.

⛔ **Passing one role's suite is never evidence about another role.** `hq:agent:evaluate` therefore
runs *every* applicable suite by default, because a gate that only ran the layer somebody remembered
to name is a gate with a hole in it.

### Re-targeting must never become removal

The twelve originals are kept, active, unmodified, and tagged `support`. That is only legitimate
because **every invariant they carry is also expressed role-neutrally** and graded against every
agent - as an operator instruction, or as untrusted content met during ordinary in-role work.

An executive agent now faces **more** cases than the original corpus gave it, not fewer. A test
asserts both halves: that no invariant family exists only in the support set, and that the executive
total stays above twelve. **The day either fails, re-targeting has quietly become deletion.**

### Overfitting is a failure mode of the evaluation, not of the agent

- **Paraphrase cases**: the same rule in different words. A prompt tuned until known wording passes
  fails these. An evaluation satisfiable by phrase-matching is measuring the phrases.
- **Must-answer cases**: a suite of only "refuse this" cases has a trivial cheat - refuse everything -
  which scores 100 percent and describes a useless agent. The executive suite is built so that
  refusing everything and answering everything **both fail**, on disjoint sets of cases. A test
  asserts exactly that, which is what proves the suite discriminates rather than merely being hard.

### Per-check reporting

The grader evaluates **every** check and records `pass`/`fail`/`not_applicable` for each, and runs
aggregate per check as well as per dimension. A case still fails if any check fails - nothing is
softened - but `must_not_call` passing on every case stays visible on a run that failed. Under one
number that fact was invisible, and it is the one that says whether an agent could be made to *act*.

## Alternatives

- **Rewrite the twelve to be role-neutral.** Rejected. Editing a permanent corpus while correcting a
  failed gate is indistinguishable from moving a goalpost, whatever the intent. They are kept
  byte-for-byte, in a file the correcting change does not open.
- **Deactivate the twelve for now.** Rejected outright: deactivating a permanent blocker case to get
  a green result is precisely what a permanent corpus exists to prevent.
- **One suite with per-case role tags and no role-scoped suites.** Rejected: it would work, and it
  would put "the invariants everyone holds" and "how this agent behaves" in one bucket again, which
  is the conflation being removed.

## Consequences

Adding an agent means adding a `behaviour.<role>` suite. That is the intended cost: an agent nobody
has written expectations for is an agent nobody can say is behaving.

⚠ Every existing role suite must be re-examined when an agent's tools change. A role suite encodes
what an agent *should* decline, and a capability change moves that line.

⚠ `safety.core` now holds cases for more than one role, so its raw case count is no longer the number
any single agent faces. The run records both, and reports skipped separately.
