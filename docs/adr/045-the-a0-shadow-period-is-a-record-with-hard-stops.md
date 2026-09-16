# ADR-045: The A0 shadow period is a record, opened on database evidence, with hard stops

**Status:** accepted and **implemented** (16 Sep 2026), **not yet used**: no period has been opened.
Written for ChatGPT's instruction of 16 September ("Gate 11 retry, A0 activation, and Phase 3
execution", 18 points, verbatim in `docs/77` §A), which authorises a *conditional* activation of the
Executive at A0 in the same milestone as a passing gate 11, with a seven-day shadow period, daily
evidence and a list of safety conditions that must hold throughout.

## Context

Until now the shadow period existed in two places: a sentence in `docs/48` ("both Phase 2 agents
launch at A0 shadow for seven days") and the `shadow` flag on each `agent_runs` row. Neither could say
when a period began, on what evidence, who decided it, what the agent was asked, or what each day
showed; and nothing would notice if, during the week, the agent wrote something, a grant appeared, or
another agent started working. The only switches were the gate runner's `on` and `off`, whose exit
trap turns everything off, and which would therefore end a shadow period silently if a gate were run
during it.

Four facts shaped the decision:

1. **"Gate 11 passed" must be read, not remembered.** Eleven gates have shown how a sentence in a
   report and the rows in `evaluation_runs` can disagree (gate 9's row that never saved, gate 11's
   74 unmeasured cases). An activation that rests on a report inherits every such gap.
2. **A release after the gate can move the prompt.** The evidence is about one prompt stamp. If the
   stamp in force at activation differs, the evidence is about a different agent.
3. **In M5 nothing asks the agent a question except a console command on the VPS.** A week of shadow
   driven only by that would collect almost nothing.
4. **Switching off is always the safe direction**, and waiting for a person to read a violation
   before switching off would not be A0.

## Decision

1. **A shadow period is a row** (`shadow_periods`): the agent, the level (constrained to `a0` in the
   database), opened, planned end (seven days) and closed times, who opened it and why, the evidence
   it rests on, the agent's tool set at that moment, the questions it asks, and how it ended. One open
   period per agent, by a partial unique index.
2. **It opens only through `hq:shadow:open --apply`**, which refuses unless, read from the database at
   that moment: the agent is one this milestone may shadow (the Executive only, by configuration
   ChatGPT's instruction fixes); its ceiling is A0 and it has an enabling user and is paused; the
   latest run of every suite that applies to its role passed, with zero unmeasured cases, on the
   prompt stamp in force now; its tools hold nothing above R3 and nothing customer-facing; there are
   no autonomy grants; every other agent is paused; `ai.runtime` is off; the Meta inbound flag is off;
   no messaging provider is registered; no period is open. It then turns `ai.runtime` on, sets the
   agent active, and writes the row, each step audited under the named person.
3. **The period carries its own questions.** A short operator-owned list, stored on the row when it
   opens, asked by `hq:shadow:tick` at 09:00 and 17:00 Dhaka through the ordinary runner as scheduled
   operator questions, once per slot. Nothing chooses a question, a tool or a scope. The tick never
   switches anything on: if the runtime is off or the agent paused, it asks nothing.
4. **Every complete day is snapshotted once** (`shadow_evidence_days`), with ChatGPT's point 7 list,
   derived from `agent_runs`, `tool_calls` and `ai_runs`, which stay the ledger. Two metrics are
   defined here because no ledger holds them: unnecessary tool calls (calls in a run that did not end
   answered, plus repeated identical calls in one run) and discrepancies (runs a reviewer edited or
   rejected, and failed runs).
5. **The A0 safety conditions are checked every hour and before every tick, and any violation stops
   the period at once**: an effectful call by the agent that succeeded; an R4 or R5 tool the agent
   named; any outbound message or delivery row; an audit row with the agent as actor on permissions,
   roles, users, autonomy, agents, credentials, audit, flags, consent, policies or tool toggles; a
   consent event by an agent; any autonomy grant; the agent's ceiling above A0 or its tool set
   changed; a run by another agent that is not an evaluation. A stop pauses the agent, turns the
   runtime off when no other period is open, and records the violations on the row.
6. **The period ends by itself after seven days**, with the same switches, and the end reason says
   that the period is evidence and not a promotion. Nothing in this ADR can raise a ceiling, create a
   grant, or touch another agent.
7. **A gate and an open period cannot share the switches.** The gate runner refuses to start while a
   period is open, and the toggle refuses `on` and `off` in that state; it also refuses to switch on
   an agent that names no enabling user, so the runner can serve gate P3-G2 (`GATE_AGENT=support`)
   without ever naming a person for the agent.

## Consequences

- The activation is one command whose dry run prints every precondition and the evidence it would
  rest on, so the decision can be read before it is made. It cannot be performed on a report.
- A shadow week produces at least six executive runs a day for a person to review, at roughly the
  cost of one gate every four days at today's prices.
- A violation ends the week immediately and visibly; the evidence up to that point is kept.
- Running a gate during a shadow period requires closing the period first, on the record.
- The scheduled questions are a design choice ChatGPT has not yet confirmed; they add no scope rule
  and no heuristic, and TaskScope v1 still fails closed on them.

## Not decided here

Whether a passing shadow week leads to anything: that is the next explicit autonomy decision
(ChatGPT's point 16). Whether the support agent ever gets a shadow period of its own: P3-G3 onwards.

## Addendum, 16 September: Shadow Probes (ChatGPT's continuation, docs/77 §E)

ChatGPT kept the scheduled questions and named them: **Shadow Probes are trusted workload, a shadow
period is a controlled observation window, and autonomy is earned capability; the three do not mix.**
Decision 3 above is refined accordingly:

- Each probe is a `{key, question}` pair stored on the period (`attention_today`, `group_yesterday`,
  `data_freshness` by default). The tick runs it as trigger `shadow_probe` through the unchanged runner,
  and writes a `shadow_probe_runs` row: the slot, the run, its verdict, and whether the runtime's
  canonical task stayed exactly the question. One row per period, date, slot and probe; a slot the tick
  could not ask is written `skipped`.
- A production period must carry probes: `--no-schedule` is refused on production and remains for
  manual and test use. When a period ends, HQ writes `probe_summary` and `evidence_complete`: complete
  only when every probe of every slot inside the window reached a verdict with its task unchanged. A
  stopped period is never complete. A probe run whose task changed is listed as a discrepancy.
- The hourly check also closes a period whose seven days are over.
