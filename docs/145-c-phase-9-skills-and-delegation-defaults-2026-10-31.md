# 145-c. Phase 9 skills and delegation: decisions taken by default (31 October 2026)

Agent M4. docs/10 describes the skill record and the proposal loop; docs/95 says delegation must be "a governed start-workflow, never a direct agent call"; ADR-047 makes authority an intersection. Defaults below; post to ChatGPT.

## Q1. What is a skill in HQ today?

**Default built:** a row in `skills` (key, version, name, purpose, required tools, business or group-wide, proposer person or agent, status). Status moves only by people: `proposed > approved > active`, `suspended` (the safe direction, from active or approved), `retired`. One active version per key (a partial unique index); activating a new version retires the old one. A skill may not list an unregistered tool or a permanently human-only tool, and the tools are re-checked at activation. Rows are never deleted.

**A skill grants nothing.** Its tool list is used only to narrow a delegation's tools. The declarative step list and skill tests of docs/10 are not built (no runtime executes a skill yet).

## Q2. Who approves and activates?

**Default built:** `hq.skills.propose` records a proposal and may suspend; `hq.skills.activate` approves, activates and retires. Both owner and operator only. A self-approval is allowed and shown ("approved by the person who proposed it"), as follow-ups do. A group-wide skill needs group-wide authority. An agent-proposed skill is recorded with `proposer_type = agent` and never activates itself.

**Applied ruling 31 Oct (item 15):** self-approval of a skill is no longer allowed. The author (the person who proposed it or recorded an agent's proposal, and the named owner) can never approve or activate it, not even as the only reviewer; a separate eligible reviewer must, and until one exists the skill stays an inactive draft. Activation also needs an approval by a non-author, so an older self-approval does not count.

## Q3. What may a delegation do?

**Default built:** `ExecutionContextResolver::resolveDelegated()` resolves the target agent by the same intersection as any run, for the **same accountable person** who asked the parent (never "as enabled", which would swap in the target's own enabling user), then:

- businesses = target AND parent run;
- brands = target AND parent run;
- tools = target's tools AND the skill's required tools, whose permission the **parent's** enabling user also holds.

So the result is inside requester AND both enabling users AND both agents' scopes AND the skill AND every tool permission. Nothing is ever added; an empty result refuses. A test walks 16 combinations of two people's scopes and permissions across three business choices and asserts no widening on each resolved one.

## Q4. Does a delegation run anything?

**Applied ruling 31 Oct (item 16):** record-only until Phase 9 evidence and a separate activation ruling. `DelegatedRunStarter` is not built and must not be. A structural test pins that nothing in HQ starts another agent's execution from a delegation.

**Default built:** no. `Delegations::delegate()` resolves and records (append-only `agent_delegations` + an audit line) and returns. Starting the delegated work is a later, human-approved workflow step (stub name: `DelegatedRunStarter`, not built, not bound). The flag `agents.delegation` has no row, and Pause All AI refuses delegation as well. No route starts a delegation.
