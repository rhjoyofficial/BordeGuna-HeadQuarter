# ADR-047: Effective Execution Context: authority is an intersection resolved once

**Status:** accepted and **implemented** (20 Sep 2026), **nothing switched on**: `ask_hq` and
`scheduler.nl` stay off, every agent stays paused at A0, there are no grants. Written for ChatGPT's
instruction of 20 September (verbatim in `docs/80` section A, points 3, 4, 7, 10 and the last line of
14), which turned a finding about Ask HQ into a control-plane primitive.

## Context

`docs/48` E.3 says the effective permission of any gateway call is an intersection, "at every step,
never a union, and never a fallback". It was, for the two parties the code knew: the agent and its
enabling human. It had no term for a third party, because until M6 there was none: the only way to
start a run was a console command on the VPS.

Ask HQ (`docs/78` C.3) introduced the third party, a signed-in person who asks, and found the hole.
`AgentRunRequest::$operator` had one reader, the line that wrote `agent_runs.triggered_by_user_id`.
It was a label. Every tool call was built as `ActingIdentity::agent($agent, $enabling)`, the gateway's
scope check and its cross-business filter read the enabling user, and the catalogue and the business
directory the model reads were narrowed by the enabling user's rows alone. A business-restricted
operator asking the Executive would have been answered with the owner's group-wide reach.

Ask HQ closed that at its own door: only group-wide operators holding every permission the agent's
tools need could ask. That was correct and it was the wrong place. The same hole was open to every
future surface that forgot to copy the check, and the entry points were already five: Ask HQ, the
Shadow Probes the scheduler fires, the gate runner, the support agent's path, the console.

Reading the code for this decision found two more terms the design lists and nothing enforced:
`agents.business_scope` and `agents.brand_scope` were written by the seeder and read by nothing, and
`ToolRequest::$brandId` was carried to the tool and checked by nothing.

## Decision

1. **One value object, one resolver** (`app/Execution`). `ExecutionContextResolver::resolve()` is the
   single place that turns (who asks, which agent, through which door, for which business and brand)
   into an immutable `EffectiveExecutionContext`. Every AI entry point comes through it, because it is
   called by `AgentRunner` and by nothing else. A test reads the sources to hold that.
2. **Effective authority is the intersection**, each step only removing:

   ```
   effective =  requester
                AND enabling identity
                AND agent          (allowed_tools, business_scope, brand_scope)
                AND business / brand   (a store is in scope exactly when its business is)
                AND tool permission, held by BOTH people
                -- decided per call by the gateway, under this same object --
                AND runtime switch AND policy AND approval AND consent
                AND credentials and capability readiness
   ```

   The enabling user can never give the asker more than the asker has. The asker can never leave the
   enabling user's authority. The per-call terms need a call's arguments, so they stay where they
   were, in the gateway, and they are evaluated against an identity that carries the context.
3. **A requester is stated honestly, and none has authority of its own.** `human`: a signed-in person.
   `on_behalf_of`: the scheduler or the system, for the accountable person RECORDED on the thing it
   serves (`shadow_periods.opened_by_user_id`, `scheduled_jobs.owner_user_id`). `gate_runner` and
   `console`: nobody asked. Where no separate person exists, the requester is declared `as_enabled`:
   the accountable human is the agent's enabling user, the requester term equals the enabling term,
   and an intersection with itself adds nothing. There is no system requester, no super-identity and
   no bypass for being scheduled.
4. **Fail closed, and say why.** No enabling user: no run. No requester named: no run, there is no
   default. A person who no longer exists or is disabled (`users.is_active = false`): no run. No
   business in common: no run. A requested business or brand outside the intersection, or a brand
   that is not the requested business's: no run. An agent that was granted tools and is left with
   none whose permission both people hold: no run. Each refusal is an `ExecutionContextRefused`, one
   of HQ's own sentences, audited as `agent_run.context_refused`.
5. **What the agent is shown and what the gateway checks are the same object.** `AgentToolset` (the
   catalogue and every enum in it), `ContextAssembler` (the business directory) and the runner's group
   expansion take the context instead of the agent row. `ActingIdentity::agent(Agent, User)` is removed
   and replaced by `ActingIdentity::forExecution($context)`: `holds()` requires the enabling user's
   permission AND the requester's AND the agent's list AND the resolved tool set, and an agent
   identity with no context holds nothing. `ToolGateway::checkScope` and the cross-business filter
   read the context's business set, and a brand outside its brand set is a scope refusal. The
   catalogue is a convenience; the gateway is the control, and tests put calls to the gateway with
   no runner in front to prove the check is reached.
6. **A context is re-read before every call.** A run holds the models it started with. Before each
   agent call the gateway asks the resolver whether the context still stands, from the database: both
   people exist and are not disabled, the agent is active, and it still borrows the same person's
   authority. Otherwise the rest of the run's calls are refused.
7. **Recorded and audited.** `agent_runs.execution_context` (json) and `execution_context_digest`,
   additive and nullable; one audit row per run (`agent_run.context_resolved`, actor `system`). It
   holds user ids, the agent, the entry point, the environment, the scopes, the tool keys, the
   permission NAMES, every narrowing with its side and its reason, the resolver version (`eec.v1`)
   and a sha256 over the authority-bearing parts. No credential, token, address or secret exists in
   it. Runs before this column have none, and none is written back onto them.
8. **Ask HQ is narrowed, not restricted.** Its group-wide rule is removed. The page and the submit ask
   the resolver (`preview`, which writes nothing), and refuse in its words only when the person and
   the agent's enabling user have nothing in common for the agent to work with. The answer job passes
   the asker as the requester and the resolver reads them again at that moment.

## Alternatives considered

- **Keep the rule at Ask HQ's door.** Rejected by the instruction and by the code: five entry points
  already, and a check that must be copied is a check that will be forgotten once.
- **Run as the asker, drop the enabling user.** A union in disguise: a person with a permission the
  owner never meant the agent to use would hand it to the agent. The enabling user is the standing
  decision that this agent may act at all.
- **A `system` requester for scheduled runs.** The thing `docs/48` E.3 forbids by name. A scheduled
  run is somebody's, and the record says whose.
- **Narrow only in the catalogue.** A guard nothing can reach is not a guard; a model that names a
  tool it was never shown must still be refused where effects happen.
- **A per-person brand scope table.** HQ has none today and inventing one is a business rule. The
  brand term exists, fed by the agent row and the requested brand, and has one place to extend.

## Consequences

- In production's posture today (requester = enabling user = the owner, group-wide, every permission,
  agent scopes null) nothing changes, byte for byte: a test pins sha256 digests of the system prompt,
  the first-turn prompt, the second-turn message with every tool result, and the recorded calls, all
  captured from the code before this ADR. The executive stamp is a hash of the two prompt files only;
  no prompt changed, so it still reads `executive.v8+18d60e98`.
- An agent is no longer shown a tool the run cannot use: where the enabling user lacks a tool's
  permission, the tool used to be listed and refused on use; it is now left out, and still refused
  on use.
- `agents.business_scope` and `agents.brand_scope` now mean what `docs/48` E.2 says. Null or `["*"]`
  does not narrow; a list narrows, by id or slug; an empty list is nothing, as for `allowed_tools`.
- Each agent gateway call costs two small queries more (the re-read of decision 6).
- The first scheduled agent run (the M7 briefing job) has its requester ready
  (`Requester::forScheduledJob`): the owner, or a refusal when the job names none.
- The release that carries this touches `AgentRunner`. Gate 11 attempt 2 runs on `3314c54` and is not
  affected; the sequencing question (release and re-measure before the shadow opens, or open the
  shadow on `3314c54`) is put to ChatGPT in the end-of-milestone report, not decided here.

## Amended 21 September 2026 (`docs/81` C.1)

- Decision 6 re-reads business scope as well: a context stands only while its business set still
  lies inside what the enabling user and the requester may each read now. A person narrowed mid-run
  ends the run's remaining calls; a row added mid-run widens nothing until the next resolve.
  `BusinessScope` keeps no cache (it held rows for 60 seconds), so every term of the intersection is
  read from the table. The per-call cost is now up to four small queries, not two.
- The first "not decided" item below is decided: in production `hq:shadow:open` requires `--by`
  naming an enabled HQ user, and `Requester::forShadowPeriod()` never answers `as_enabled` there.

## Not decided here

Whether `hq:shadow:open` should require `--by` (a period with no recorded opener runs `as_enabled`
today, as it effectively always did). Whether an approval parked by an agent should name the
requester rather than the enabling user as `requested_by` (approval binding was out of scope).
Whether people should have a brand scope of their own. Whether the scheduler's TOOL path (one human,
no agent, no model) should carry a context too; it has nothing to intersect today.
