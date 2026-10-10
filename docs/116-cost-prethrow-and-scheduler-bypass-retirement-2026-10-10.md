# docs/116 - Cost attribution on the exception path, scheduler bypass retirement, guard/gateway disagreement alert (10-11 Oct 2026)

Authority: `docs/110-chatgpt-ruling-2026-10-10.txt` §1 (c) and §2 (f), (g); summary `docs/101` §14. Branch
`platform/cost-prethrow-and-scheduler-bypass-retirement`, worktree `hq-wt/f`, from main `a8a6410` (which holds
docs/105 S1 and docs/106 cost attribution). Three small, ruled follow-ups; nothing activates, no autonomy or prompt
change, no production access.

## 1. Plan (written before any code)

### 1.1 (c) Cost attribution when `AgentRunner::run()` throws

**The gap (docs/106 §2 decision 3).** `AgentEvaluationSubject::observe()` learns the run's `correlation_id` from the
`AgentRun` the runner RETURNS. When `run()` throws, nothing is returned, the observation carries no correlation, and the
attributor files the result as *unaccounted*: any model calls made before the throw are not summed and the run says
`complete = false`. The same loss happens on the thread path (`runOnThread`) when `ConversationSimulator::withThread`
throws after the agent ran.

**Where the id is minted and where a throw can escape.** `run()` mints the UUID (`Str::uuid()`) after the execution
context is resolved, creates the `agent_runs` row with it, then enters the model loop. The loop's own exceptions are
caught and recorded on the row (status `failed`), so a throw escapes `run()` only before the row exists (catalogue,
workflow decision, context assembly, autonomy resolution, the `agent_runs` insert) or around the loop (the audit of the
bound thread, the catch handlers' own `save()`, the final `forceFill()->save()` / `refresh()` after the loop). The
second group can follow real model calls.

**The durable evidence.** `ModelRuntime::startRun()` inserts the `ai_runs` row (status `pending`) BEFORE the provider is
called, keyed on the context's `correlation_id`; a thrown attempt is marked `failed` by `failRun()`. So, for a known
correlation: rows present = calls that started (each with its estimate), no rows = no model call was ever started. That
is evidence, not inference.

**Fix: the subject owns the correlation before the run.** `AgentRunRequest` gains an optional `correlationId` (an
evidence key, not a control-plane decision: it chooses nothing about tools, model, autonomy or prompt). `AgentRunner`
uses it when given, else mints as today. `AgentEvaluationSubject` mints the UUID itself for every run (bare and
thread path) and passes it in, so on the exception path it still knows the key every `ai_runs` row of the attempt
carries:

- `observe()` catch: the observation carries `correlationId`, `aiRunId` = the first `ai_runs` row under it (if any),
  and `modelTurns = 0` only when the ledger holds NO row for that correlation (provably no call started). With rows
  present `modelTurns` stays null; the result is linked by evidence and its cost is the sum of those rows.
- `runOnThread()` catch: the same, after `EvidenceCarryOver::restore()` has put the run's rows back.
- The attributor is unchanged: linked results are summed from `ai_runs`, `model_turns = 0` is accounted at zero, and
  anything else remains unaccounted. Nothing is fabricated; a run that threw with no correlation known (an old result,
  a subject that is not this one) still reports the gap.

Why not a typed exception: every escape point would have to be wrapped and every caller (Ask HQ, console, shadow
probes, Support wake-up) would see a new exception class in its recorded error; a caller-held key reaches the same
durable evidence with no change to the thrown types.

**Tests (watched failing first), added to `tests/Feature/Evaluation/EvaluationCostAttributionTest.php`:**
1. a real Executive run through the fixture provider (first provider down, fallback serves: two `ai_runs` rows) whose
   final `agent_runs` save throws (a `saving` listener) -> `run()` throws -> the result carries the correlation and the
   run's cost is exactly those two rows, `complete = true`;
2. a run whose `agent_runs` insert throws (a `creating` listener) before any model call -> cost 0.000000, result
   accounted as "no model call", `complete = true`;
3. the existing ten cost tests stay green; the runner honours a caller-supplied correlation and still mints one when
   none is given.

### 1.2 (f) Retire the scheduler bypass and the `scheduler.gateway` control

**Consumer audit (to be completed and recorded in §2 with every reference).** Code: `RunScheduledJob::handle()` reads
`config('hq.scheduler_gateway_flag')` through `Features::enabled()`; `config/hq.php` defines the key; no other `app/`
read. Seeders: none seed the row (`database/` has no reference). Frontend: none. Deploy scripts (`deploy/`): none.
Tests: `SchedulerGatewayTest` (`gatewayMode()` helper) and `EventTriggeredJobsTest` (one row). Docs: historical
records only (50, 54-58, 64, 95, 105). Pause: `ScheduledJobService::pause()` pauses one job (`JobState::Paused`);
nothing uses the flag to pause work (OFF meant "run directly", never "stop"), so no pause control is added.

**Removal.** `RunScheduledJob`: delete `executeDirectly()`, `mustUseGateway()`, the `Features`, `CredentialResolver`,
`ScopeResolver`, `CredentialVault` dependencies and the `$viaGateway` choice; every scheduled tool, effectful or not,
runs `executeThroughGateway()`. `config/hq.php`: delete `scheduler_gateway_flag`. Docblocks in `RunScheduledJob` and
`ToolDescriptor::hasEffect()` rewritten. No flag seeded, no migration (the production row is data; see release notes).

**Tests.** `SchedulerGatewayTest`: the flag tests become "runs through the gateway with no flag row, on the record";
"switch back to direct" is deleted; the three equivalence tests compare the gateway path's output with the tool run
directly IN THE TEST (the structural rule binds `app/`, not tests), so the equivalence check survives; the docs/105
tests lose their "flag off" framing. `EventTriggeredJobsTest`: the flag row goes. `GatewayCoverageStructureTest`:
`RunScheduledJob` may call only `$gateway->execute`; it must contain no `executeDirectly`, `mustUseGateway`,
`Features` or `$tool->`; the former exception text is gone.

### 1.3 (g) Counter and pattern alert for guard refusals the gateway would have authorized

`AgentRunner::gatewayOnGuardRefusal()` already audits `agent_run.effectful_call_refused` with
`gateway_would_authorize`. Following the `WebhookSignatureFailures` counter + rule pattern:

- `App\Observe\Alerts\GuardGatewayDisagreements` (counter): `record(agentKey, tool)` increments a cache bucket per
  agent per hour (TTL a day; a cache failure is swallowed, the audit row is the durable record); `recent(hours)`
  returns counts per agent. Recorded by the runner only when `gateway_would_authorize` is true.
- `App\Observe\Alerts\Rules\GuardGatewayDisagreement` (rule, key `agents.guard_gateway_disagreement`, not paged
  elsewhere): fires one warning per agent whose count over the window reaches the threshold. Threshold
  `hq.alerts.thresholds.guard_gateway_disagreement = ['count' => 3, 'window_hours' => 24]`: a single disagreement is
  audit only (ruling (g)); three in a day is a pattern. Registered in `hq.alerts.rules` (16 rules).
- Tests: the counter increments and windows; the rule is silent below the threshold and fires at it; the runner
  records the counter beside the audit when the gateway would authorize, and not otherwise; the rule-count test moves
  from fifteen to sixteen.

### 1.4 Process
Plan commit first; a WIP checkpoint after each part; final gate = full Pest suite (`--parallel --processes=3`,
`hq_test_f`), `vendor/bin/pint --test`, `npx tsc --noEmit`. Stage by path. Push the branch; no merge, no deploy.
