# 102 - The HQ-native WorkflowEngine (4 October 2026)

Status: BUILT on branch `platform/workflow-engine` (worktree `hq-wt/i`, from `main` `4149baa`). Not merged, not pushed, not deployed. Production posture unchanged: `config('hq.workflows.definitions')` is empty, so no workflow type exists and nothing can be started; no flag added or switched on.
Asked by: docs/101 §11, ruling 9.1 (option B approved). The durable-workflow spike (docs/99, branch `spike/durable-workflow-evaluation`) is kept intact and unmerged; that package is NOT a dependency of this branch.

## 1. What it is, in one paragraph

A workflow is a small PHP class that declares ordered steps. A run of it is three kinds of rows: one `workflow_instances` row (identity, status, where it waits, a worker lease), one `workflow_steps` ledger row per step (attempts, idempotency key, the tool call and approval it produced, the persisted outcome) and `workflow_signals` rows (buffered signals). Horizon advances a run (`AdvanceWorkflow` on the existing `scheduler` queue); the existing scheduler tick sweeps for due timers, decided approvals, arrived signals and dead workers (`reconcile()`); the existing event drain turns domain events into signals. Every effect is a Tool Gateway call as the run's named requester with the key `wf:{run uuid}:{step}`; a step whose policy requires approval parks the run on the ordinary `approvals` row the gateway created. The engine adds order and durability, never permission.

## 2. The seam and the authoring model

`App\Workflow\WorkflowEngine` (the only thing callers depend on):

| Method | Does |
|---|---|
| `start(type, instanceId, input, ?requestedBy, ?businessId)` | Creates the run under a caller-chosen business key (`INSERT ... ON CONFLICT DO NOTHING`: a second start returns the same run) and dispatches an advance. Unknown type or inactive requester throws. |
| `resume(instanceId)` | Dispatches an advance. Changes no state by itself. |
| `signal(instanceId, name, payload, ?dedupeKey)` | Buffers a signal (unique per run + dedupe key) and dispatches. False for a finished/unknown run or a duplicate. |
| `cancel(instanceId, ?by, ?reason)` | Conditional move to `cancelled`; any still-pending approval the run parked is cancelled through `ApprovalService::cancel()`. |
| `retry(instanceId, by, acceptUncertain=false)` | Only for `failed`: the failed step gets a fresh attempt budget; `acceptUncertain` is the person accepting re-execution of an uncertain call older than the replay ceiling (§5). |
| `inspect(instanceId)` | `WorkflowRunState`: status, current step, wait (kind/for/until), output, error, every step row, pending signal names. |
| `reconcile(?now)` | The sweep (§4). Returns how many runs it dispatched. |

Authoring (`WorkflowDefinition` + `Step`, no DSL): `type()`, `version()`, `steps()`, optional `listensTo()` (event types) and `correlationKey(input)` ("order:1234"). Three step kinds only:

- `Step::tool(name, toolKey, fn (WorkflowState) => args)`, with `->retries(n, backoffSeconds)`;
- `Step::awaitSignal(name, signal, ?timeoutSeconds)` / `Step::awaitEvent(name, eventType, ?timeoutSeconds)` (= signal `event:{type}`);
- `Step::sleep(name, seconds | fn (WorkflowState) => seconds)`.

`->when(fn (WorkflowState) => bool)` skips a step; that is the only control flow. Closures read only `WorkflowState` (input + earlier steps' persisted status/outcome): never the clock, the DB, the network. Approval is not a step kind; it is what the gateway's policy decides for a tool step.

## 3. Schema (migration `2026_10_04_100000_create_workflow_tables`)

**`workflow_instances`**: `id`, `uuid` (run id, unique), `instance_key` (business key, unique), `type`, `definition_version`, `status`, `current_step`, `input` jsonb, `output` jsonb, `wait_kind` (signal | timer | approval), `wait_for`, `wake_at` (indexed), `correlation_key` (indexed), `correlation_id` (uuid, carried into every tool call), `business_id` FK, `requested_by_user_id` FK (null = the narrow `system` identity), `lease_token`, `lease_expires_at`, `last_error`, `started_at`, `finished_at`, timestamps.

**`workflow_steps`**: `id`, `workflow_instance_id` FK, `step`, `seq`, `kind` (tool | signal | sleep), `status`, `attempt`, `max_attempts`, `idempotency_key` (unique, `wf:{uuid}:{step}`), `tool_key`, `tool_call_id` FK, `approval_id` FK, `outcome` jsonb, `error`, `wake_at` (sleep end, signal deadline or retry time; persisted on first visit), `uncertain_accepted_at`, `started_at`, `finished_at`, timestamps; unique (`workflow_instance_id`, `step`).

**`workflow_signals`**: `id`, `workflow_instance_id` FK, `name`, `payload` jsonb, `dedupe_key`, `consumed_by_step`, `consumed_at`, timestamps; unique (`workflow_instance_id`, `dedupe_key`).

Nothing duplicates existing records: effects stay in `tool_calls`, decisions in `approvals`, history a human reads in `audit_logs` (`workflow.started/completed/failed/cancelled/retried`).

## 4. State machine and how a run moves

Run (`WorkflowInstance::TRANSITIONS`, enforced by one conditional `UPDATE ... WHERE status IN (allowed sources)`):

```
pending -> running | cancelled
running -> running | waiting | completed | failed | cancelled
waiting -> running | cancelled
failed  -> running (retry) | cancelled
completed, cancelled: final
```

Step: `pending -> running -> succeeded | waiting | pending/uncertain (retry backoff) | failed`; `waiting -> succeeded | timed_out | declined | failed`; `skipped` by `when()`. Done = succeeded, skipped, timed_out, declined; a done step is never run again.

Advance (`WorkflowRunner::advance`): claim the lease (conditional update, `hq.workflows.lease_seconds` = 300, above the scheduler queue's 120 s job timeout); walk the declared steps in order, skipping done ones; per step:

- **tool**: an existing `running` row means the previous worker died mid-call (we hold the lease): its `started` tool call(s) are recorded `uncertain` through the gateway's own `ToolCallRecorder`. Then the latest tool call under the step's key decides before the gateway is asked: succeeded/replayed -> the step is done from that record; awaiting approval on a still-open approval -> wait on it; uncertain and older than the replay ceiling -> fail for a person (§5). Otherwise the requester must still be active, the arguments are built, the row is written `running`, and `ToolGateway::execute()` runs with `CallerKind::Workflow`, the requester's identity, the run's correlation id and business, and the step key. Outcome: ok -> succeeded; awaiting approval -> waiting on that approval; failed/uncertain with budget left -> backoff `backoff * 2^(attempt-1)` (cap 1 day); any refusal (denied, invalid, unavailable) or spent budget -> the run fails.
- **approval wait**: pending/approved -> keep waiting; consumed -> the step takes the approved call's outcome (`resulting_tool_call_id`), or fails if that call did not succeed; rejected/cancelled/expired -> `declined`, and the run continues (a later step can test it).
- **signal**: deadline persisted on first visit; the oldest unconsumed matching signal is consumed atomically; past the deadline -> `timed_out`.
- **sleep**: end time persisted on first visit.

`reconcile()` (called from `hq:scheduler:tick` every minute, inside the tick's lock, failure-isolated so it cannot cost the jobs their heartbeat) dispatches every open run with no live lease that is `pending` or `running` (lost dispatch, dead worker), or whose `wake_at` is due, or whose awaited approval is decided, or whose awaited signal has arrived. The event drain (`hq:events:process`) calls `WorkflowEvents::deliver()` after handlers and event-triggered jobs: open runs whose `correlation_key` equals `{aggregate_type}:{aggregate_id}` (business-scoped like event jobs) and whose definition lists the type get signal `event:{type}` with dedupe key `event:{event uuid}`.

## 5. Guarantees and non-guarantees

Guaranteed (each proven in `tests/Feature/Workflow/WorkflowEngineTest.php`):
1. One run per business key; restart-safe: all state is in the database (a rebuilt container resumes it).
2. At-least-once step execution, at-most-one external effect per step **for tools that reconcile**: a retry reuses `wf:{uuid}:{step}`, so the gateway replays a recorded success or reconciles an uncertain call (ADR-011); a dead worker's `started` call becomes `uncertain` and is reconciled, never blindly repeated (crash test: body ran, one effect).
3. **The 24 h replay ceiling does not reach a completed step**: a step's outcome is read from the ledger, and from the tool call recorded under its key, so it is never re-sent however old (test: forced back to `running` two days later, zero new gateway calls). An *uncertain* step retried after the ceiling is refused automatically and waits for a person: `retry(..., acceptUncertain: true)` after checking the far side by hand (test).
4. No authority bypass: every step is a gateway call as the named requester (or `system`, which holds only its fixed narrow list); a requester deactivated mid-run fails the next tool step; a policy refusal is not retried; approval-gated steps park on the ordinary approval, decided and executed only by `ApprovalExecutor` (requester/decider separation, hash, single use all unchanged). No second approval mechanism.
5. Deterministic transitions: conditional updates for run status, lease for single writer, atomic signal consumption, deadlines persisted once.
6. Definition drift fails the run visibly rather than replaying old runs through new steps.

Not guaranteed (by design, written down):
- Exactly-once for a tool that cannot reconcile: the gateway refuses an uncertain non-idempotent retry ("a human must decide"); after the budget the run fails for a person.
- Latency: timers, approvals and lost dispatches are noticed by the minute tick (up to ~1 min late). Signals dispatch immediately.
- Lease expiry assumes the worker is dead after 300 s; a tool that legitimately runs longer than the lease must not be a workflow step (Horizon kills jobs at 120 s on this queue anyway).
- No parallel branches, child workflows, compensation (saga) steps, continue-as-new or versioned replay; a changed definition needs a new `version()` and old runs finished or cancelled.
- An approval left `approved` but never consumed (the guard refused after the claim, docs/48 G.3) keeps its run waiting until a person cancels the run; the approval lifecycle, not the engine, owns that state.
- No UI and no console command yet; `inspect()` is the read path.
- Retention: `RetentionPolicy` now keeps any `tool_calls` row a `workflow_steps` row points at (the RetentionTest column guard caught the new reference). The three workflow tables themselves have no pruning rule yet; add one when the first real workflow ships.

## 6. Decisions taken here

- **ConnectorRegistry: NOT registered.** The engine is an internal orchestrator, not an external capability with providers; it has nothing to resolve. It reaches the outside world only through tools, and tools reach connectors through the gateway (and the registry, once the gateway is wired to it). Registering it would make the registry describe something that is not a connector.
- New `CallerKind::Workflow` (`'workflow'`, fits `tool_calls.caller_kind` varchar 16), so a trace says which door a call came through; the acting identity stays the human requester.
- Approval decline is a step outcome (`declined`), not a run failure: "the owner said no" is a business result.
- No `*Registry` class name (the docs/98 structural test reserves that suffix): the catalogue is `WorkflowDefinitions`.
- Structural test `WorkflowStructureTest`: a definition imports only `App\Workflow\*` and calls no `execute`, `app()`, `DB::`, clock or I/O; the engine imports no connector/adapter/provider/tool/credential/HTTP namespace and its only `->execute(` is `$this->gateway`.

## 7. Mapping to ruling 9.1

| Ruling element | Where |
|---|---|
| stable seam start/resume/signal/cancel/retry/inspect/reconcile | `WorkflowEngine` |
| on the existing scheduler + events/outbox + Horizon | tick -> `reconcile()`; drain -> `WorkflowEvents`; `AdvanceWorkflow` on `scheduler` queue |
| instance identity, correlation/run ids | `instance_key`, `uuid`, `correlation_id`, `correlation_key` |
| durable step/execution state | `workflow_steps` ledger |
| wait/checkpoint state | `wait_kind/wait_for/wake_at` + step `wake_at` |
| event/signal handling | `workflow_signals`, `awaitSignal/awaitEvent` |
| retry/attempt metadata | `attempt`, `max_attempts`, backoff, `retry()` |
| idempotency keys | `wf:{uuid}:{step}` (unique column) |
| deterministic transitions | `TRANSITIONS` + conditional updates + lease |
| every effect through the gateway, not an authority bypass | §5 item 4; structural test |
| APPROVAL_REQUIRED parks the workflow; reuse approvals | approval wait on `approvals` |
| small, no DSL, steps as PHP classes | §8 |

## 8. Size

Production PHP for the engine: **1,083 lines** physical (`app/Workflow` 948 in 10 files + 3 models 135), **746** excluding comments and blank lines; migration 101; wiring 39 lines across `CallerKind`, `AppServiceProvider`, `SchedulerTick`, `ProcessDomainEvents`, `config/hq.php`, `RetentionPolicy`. Tests: 845 lines (17 tests). Compare docs/99: ~127,000 lines and 29 tables for the package.

## 9. Upgrade path to durable-workflow/workflow

Reconsider only on evidence (ruling 9.1): long-running workflows with complex timers/signals, large fan-out/fan-in, substantial replay/versioning needs, multi-day/month coordination beyond this engine, or external orchestration the HQ state model cannot carry cleanly. Concrete triggers to watch: a definition needing parallel branches or child runs; more than a few hundred open runs per tick; the need to change a definition while runs are parked (`version()` drift failures appearing in practice).

How: keep the `WorkflowEngine` interface; write a `DurableWorkflowEngine` adapter (prototype on the spike branch); translate each `WorkflowDefinition` into an orchestration class whose activities call the same Tool Gateway with the same `wf:{instance}:{step}` keys; apply the six docs/99 §6 conditions (exact pin, lazy-loading exemption scoped to package models, explicit `DW_*` config and Horizon queue, activities under the job timeout, `getVersion()`/replay-verify before deploys, failed-job alerting ignoring package jobs); let HQ-engine runs drain, then switch the binding. Business data stays in HQ tables either way.

## 10. Verification

- `tests/Feature/Workflow/WorkflowEngineTest.php` (14 tests) and `WorkflowStructureTest.php` (3) on PostgreSQL `hq_test_i`: start, persist, resume after a simulated worker restart, event from the outbox, signal (buffered, deduplicated, refused late), timeout, cancel (approval withdrawn), retry of a failed step (backoff then a person's retry), idempotent replay (no double effect), approval wait (approve and reject), inspect, reconcile of an uncertain step, a dead worker's orphaned call, the replay-ceiling cases, authority (no permission, deactivated requester), definition drift.
- Full suite on `hq_test_i` (PostgreSQL, `--parallel --processes=3`): **1,814 passed, 21,337 assertions** (main's 1,797 + these 17). Pint clean. No TypeScript touched. The first full run caught one real gap (RetentionTest: `workflow_steps.tool_call_id` was a new reference retention did not know), fixed by the keep condition in §5.
