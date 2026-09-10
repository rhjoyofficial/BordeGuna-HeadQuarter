# ADR-030: Evaluation measures six dimensions, and autonomy consumes them

**Status:** accepted and **implemented** (10 Sep 2026).

## Context

`autonomy_grants.min_agreement_rate` has read a single agreement rate since Phase 2.5 (ADR-023). One
number is the right shape for "did this suite pass". It is the wrong shape for "has this agent earned
more freedom", because it averages six things that fail for different reasons, are fixed by different
people, and matter differently depending on what is being unlocked:

| Dimension | What it measures | Who is wrong when it fails |
| --- | --- | --- |
| `reasoning` | is the answer right, honest, within the evidence | the prompt or the model |
| `tool_selection` | did it ask for the right tools, and not the wrong ones | the prompt, the catalogue, or the model |
| `policy` | did the Policy Engine decide correctly | a policy rule |
| `enforcement` | were permission, scope and consent actually applied | the control plane |
| `execution` | did the tool that ran actually do the thing | the adapter or the provider |
| `outcome` | did the postcondition hold afterwards | the assumption that a 200 means a completed effect |

An agent can select tools perfectly and reason badly. It can reason perfectly and propose something
policy correctly refuses - which is a *good* outcome scored as a failure if the dimensions are
merged. A tool can succeed while the postcondition it was meant to establish never held.

Averaged, all of that reads as "94%". Widening autonomy on that figure is widening it on an average
of unrelated quantities, and the dimension that matters for the action being unlocked may be the one
holding the average **up** rather than the one being measured.

There is a second conflation underneath it. `EvaluationObservation` recorded one list of tool calls.
Collapsed like that, **"the model asked for something dangerous" and "something dangerous happened"
read identically** - and those are the two facts a safety corpus most needs to tell apart.

## Decision

**Every case declares the dimension it measures, every result records it, and every run reports a
rate per dimension alongside the overall one.**

**An autonomy grant may name the dimension its evidence must come from.** `min_agreement_dimension`
null keeps the previous meaning exactly - the overall rate - so nothing existing changes.

⛔ **A grant naming a dimension nothing has measured does not fall back to the overall rate.** It
does not match. Falling back is precisely how an unmeasured dimension comes to be treated as a
measured one, and "no measurement is not a passing measurement" is the rule this subsystem already
lives by.

**A proposal and a permitted call stay distinguishable.** The observation now carries what the model
named, what the gateway executed, and what it refused with the reason. ADR-028 is unchanged: a tool
the agent *named* still counts as called for `must_not_call`, because that grades the model. The new
fields let `enforcement` be graded separately against what the control plane then did.

The seeded safety corpus is `tool_selection` throughout, because every case there asks the same
question: did the model reach for something it must not, or refuse when it must. Enforcement, policy,
execution and postcondition get their own suites against their own components, because a failure in
each blames somebody different.

## Alternatives

- **Keep one number and interpret it carefully.** Rejected. "Interpret it carefully" is not a control;
  it is a hope about whoever reads it next, at the one moment - widening autonomy - when being wrong
  is expensive.
- **Weight the dimensions into a composite score.** Rejected. A weighting is a policy decision
  disguised as arithmetic, and it re-creates the original problem with an extra step of laundering.
- **Grade dimensions per case rather than declaring them.** Rejected: it would make the grader guess
  what a case was for, and a grader that infers intent is measuring its own inference.

## Consequences

Autonomy widening can now be argued from evidence about the thing being widened. "Tool selection is
1.00 across 40 cases and reasoning is 0.82" is a decision somebody can make; "94%" is not.

⚠ Most dimensions have **no cases yet**, and that is the honest state. `execution` and `outcome` in
particular cannot be measured until something executes, which is ADR-031's territory and a later
phase. A grant naming them therefore does not match, which is the correct behaviour: an unmeasured
dimension blocks rather than permits.

⚠ The overall rate is kept and still gates a suite's pass or fail. This adds a lens; it removes
nothing.
