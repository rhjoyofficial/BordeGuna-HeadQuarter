# ADR-016: One tool gateway for every caller, including the scheduler

**Status:** proposed (Phase 2, docs/48)

## Context
Phase 0 built a `Tool` interface, a `ToolRegistry` and a `ToolContext`, and `RunScheduledJob` calls
`$tool->execute()` directly: no policy, no permission check, no `tool_calls` row. That was correct
for Phase 0, where every registered tool is read-only or writes only HQ's own read models and only a
human holding `hq.jobs.manage` can create a job. It stops being correct the moment either a write
tool or an agent exists, and Phase 2 introduces both.

The tempting alternative is to build the gateway for agents and leave the scheduler as it is, on the
grounds that the scheduler is already trusted. That produces two execution paths, two sets of
recording behaviour, and an audit trail with a hole in it exactly where the automated, unattended
work happens.

## Decision
`ToolGateway::execute` is the only way any tool runs in HQ. Every caller goes through it: the agent
runtime, `RunScheduledJob`, the event processor, an operator UI action, and any future API consumer.
The gateway owns registry lookup, schema validation, identity resolution, permission, policy,
approval, budgets, idempotency, credential resolution, execution and recording. Callers own their
own bookkeeping (a job run record, a conversation turn) and nothing else.

Reconcile-on-retry (ADR-011) moves out of `RunScheduledJob` and into the gateway, so every caller
inherits it rather than only the one that happened to need it first.

A scheduled job with no `owner_user_id` resolves to the narrow `system` identity, which holds only
R0 to R2 read tools. The five Phase 1 poll and reconcile jobs fit inside it; anything wider must
name an owning human.

## Alternatives
- **Gateway for agents only.** Rejected: two paths, two behaviours, an incomplete audit trail, and
  the unattended path is the one least likely to be watched.
- **Middleware pipeline the caller assembles.** Rejected: a caller that can assemble the pipeline is
  a caller that can omit a stage.

## Consequences
Migrating the scheduler is the gateway's acceptance test: the six live production jobs must produce
byte-identical output through it. A feature flag returns the scheduler to direct execution if that
comparison fails. Every tool execution in HQ becomes visible in `tool_calls`, which is the first time
that table is written to at all.
