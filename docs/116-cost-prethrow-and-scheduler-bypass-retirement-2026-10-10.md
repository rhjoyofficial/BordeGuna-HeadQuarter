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

## 2. What was built (record)

### 2.1 Commits (branch `platform/cost-prethrow-and-scheduler-bypass-retirement`, from main `a8a6410`)

| Commit | What |
|---|---|
| `fd277af` | §1, this plan |
| `5b2d0c6` | part 1, (c): the correlation id survives the throw |
| `57cae7e` | part 2, (f), partial: direct path and config key removed (checkpoint at a session change) |
| `f566b47` | part 2, (f): tests on the single gateway path |
| `59924d8` | part 3, (g): counter and pattern alert |
| (this commit) | this record; DashboardsTest rule count 15 -> 16 |

Not merged, not deployed; production not touched.

### 2.2 (c) How the correlation id now survives the throw

- `AgentRunRequest` gained an optional `correlationId` (the last argument, so no existing caller changes). It is an
  evidence key only; it decides nothing about tools, model, autonomy or prompt.
- `AgentRunner::run()` uses it when given and otherwise mints a UUID as before. Ask HQ, console, shadow probes and the
  Support wake-up pass none, so their behaviour is unchanged.
- `AgentEvaluationSubject` mints the UUID itself before every run and passes it in. That holds on the bare path and
  on the thread path, where it is minted outside the rolled-back transaction. When `run()` throws, the catch reads
  the `ai_runs` ledger under that key. `aiRunId` becomes the first row. `modelTurns = 0` is set only when there is NO
  row: `ModelRuntime::startRun()` inserts the row before the provider is called, so no row means no call started.
  When rows exist the turn count is left null and the result is linked by evidence. `notReplayed()` on the thread
  path does the same after `EvidenceCarryOver::restore()`.
- `EvaluationCostAttributor` is unchanged. A result with no known correlation (older rows, other subjects, a
  scenario that is not keyed) still counts as unaccounted, and still sets `complete = false`.
- A typed exception was considered and not used. Throws can escape from many places, and every other caller would
  see a new exception class in its recorded error. A key the caller holds reaches the same durable evidence.

### 2.3 (f) Consumer audit for `scheduler.gateway` (every reference, before removal)

| Where | Reference | Verdict |
|---|---|---|
| `app/Scheduling/Jobs/RunScheduledJob.php` | `Features::enabled(config('hq.scheduler_gateway_flag'))` chose direct or gateway | the only reader in code; removed |
| `config/hq.php` | `'scheduler_gateway_flag' => 'scheduler.gateway'` | removed |
| `app/Gateway/Descriptor/ToolDescriptor.php` | docblock of `hasEffect()` | rewritten (history only) |
| `database/` (migrations, seeders) | none; no row was ever seeded (docs/95 #12) | nothing to do; no flag seeded now |
| `resources/js`, routes, controllers | none by name. The generic Flags admin (`/admin/flags`) lists whatever rows exist | the production row still shows there (see release notes) |
| `deploy/` (deploy.sh, gate, staging, monitor, compose, runbooks) | none | nothing to do |
| `tests/Feature/Gateway/SchedulerGatewayTest.php` | `gatewayMode()` helper, flag tests, equivalence by flipping the flag | rewritten |
| `tests/Feature/Scheduler/EventTriggeredJobsTest.php` | set the row ON | row removed |
| `tests/Feature/Gateway/GatewayCoverageStructureTest.php` | allowed the direct path as the one exception | exception removed |
| docs 50, 54, 55, 56, 57, 58, 64, 95, 105 | historical records (production row ON since docs/50; rollback rehearsal) | left as history |

**Pause control.** Nothing used the flag to pause work: OFF meant "run directly", never "stop". Pausing already exists
per job (`ScheduledJobService::pause()`, `JobState::Paused`; the scheduler never fires a paused job), so no new pause
control was added.

### 2.4 (f) What was removed

- `RunScheduledJob`: `executeDirectly()`, `mustUseGateway()`, the `$viaGateway` choice, and the `ToolRegistry`,
  `Features`, `CredentialResolver`, `ScopeResolver` and `CredentialVault` dependencies. `handle()` calls
  `executeThroughGateway()` for every job. The `job_run.finished` audit keeps `via_gateway` (always `true`) so its
  rows read the same as before.
- `config('hq.scheduler_gateway_flag')`.
- **Behaviour change**: a scheduled tool with NO effect now goes through the gateway too. It therefore needs a job
  owner who holds the tool's permission, or the narrow system identity for ownerless jobs (which is what the live jobs
  use). Production has run gateway mode since docs/50 with the row ON, so production behaviour does not change.
- Two test changes not in the §1 plan, both test-only. `SchedulerAcceptanceTest` (Phase 1) created owners with no
  permission and relied on the direct path; its owners now hold the `owner` role. Its thrown-tool case now asserts the
  gateway's recorded message (`test.fail threw on attempt 1`) instead of the exception class name.

### 2.5 (g) Counter and rule

- Counter `App\Observe\Alerts\GuardGatewayDisagreements`: `record(agentKey, tool)` increments a per-agent, per-hour
  cache bucket (TTL 48 h; a cache failure is swallowed). `recent(hours)` returns counts per agent.
- `AgentRunner::gatewayOnGuardRefusal()` records it right after the `agent_run.effectful_call_refused` audit, and only
  when `gateway_would_authorize` is true. The audit is unchanged and stays the durable record.
- Rule `App\Observe\Alerts\Rules\GuardGatewayDisagreement`, key `agents.guard_gateway_disagreement`, severity
  warning, not paged elsewhere. It raises one condition per agent (`agent:{key}`) when that agent's count in the
  window reaches the threshold. Config `hq.alerts.thresholds.guard_gateway_disagreement = ['count' => 3,
  'window_hours' => 24]`, both clamped to at least 1. One refusal never alerts. Registered as the sixteenth rule in
  `hq.alerts.rules`.
- Reachability finding. docs/105 §2.6 Q4 said a disagreement could not happen with today's guards, but one can. The
  `bound_thread` guard refuses a tool that requires `conversation_uuid` when the run is bound to no thread. The
  gateway knows nothing of thread binding, so it would authorize a granted, agent-executable write. No registered tool
  hits this today, because the effectful tools are refused for agents as `action_class.human_only`. The new test uses
  this path with a test tool, so the counter is proven on a real run rather than a stub.

### 2.6 Tests

Each new or changed test was watched failing before its fix. Part 1: both new tests were red on `fd277af`. Part 3: all
seven were red before the classes existed.

- `tests/Feature/Evaluation/EvaluationCostAttributionTest.php` (+2, 12 in total). First: a real Executive run (fixture
  provider, first provider down, fallback serves, so two `ai_runs` rows) whose closing `agent_runs` save throws.
  Exactly those two calls are attributed and `complete = true`. Second: a run whose `agent_runs` insert throws before
  any call. Cost is 0.000000, the result is accounted as "no model call" and `complete = true`. The ten docs/106 tests
  are unchanged and green.
- `tests/Feature/Gateway/SchedulerGatewayTest.php`:
  - the flag tests are replaced by "no flag row: through the gateway" and "a leftover OFF row is ignored";
  - the "switch back to direct" test is removed;
  - the equivalence tests compare the gateway path with the tool run directly by the test (`referenceOutput()`, in
    test code only);
  - a structural test checks that no direct path is left.
- `tests/Feature/Gateway/GatewayCoverageStructureTest.php`: `RunScheduledJob` may call only `$gateway->execute`, with
  exactly one `->execute(`, and contains no `executeDirectly`, `mustUseGateway`, `$tool`, `ToolRegistry` or
  `Features`. No file in `app/` and no key in `config/hq.php` reads `scheduler.gateway`.
- `tests/Feature/Scheduler/EventTriggeredJobsTest.php` and `SchedulerAcceptanceTest.php`: see §2.4.
- `tests/Feature/Observe/GuardGatewayDisagreementTest.php` (new, 7 tests):
  - the counter's windows;
  - a cache failure is swallowed;
  - the rule is silent at 2 and fires at 3 (default 3 in 24 h), then clears after the window;
  - the threshold comes from config and is clamped;
  - the rule is registered;
  - the runner counts a `bound_thread` refusal the gateway would authorize, and nothing runs (no `tool_calls` row);
  - the runner does not count when the gateway refuses too.
- `AlertsTest` and `DashboardsTest`: rule count 15 -> 16.

### 2.7 Gate (local, 11 Oct)

- Full Pest suite `DB_DATABASE=hq_test_f php artisan test --parallel --processes=3`: **1,949 passed (25,996 assertions)**, exit 0 (main `a8a6410` side: the first run on this branch had one failure, the DashboardsTest rule-count pin 15 vs 16, fixed in the record commit).
- `vendor/bin/pint --test`: passed. `npx tsc --noEmit`: exit 0. No frontend file touched, so no Vite build.

### 2.8 Release notes

- Code only: no migration, no seeder, no new env key. Rollback = the previous release, with data unchanged.
- (c) changes nothing outside evaluation runs. The docs/106 backfill (`hq:evaluation:attribute-cost`) still reports
  `complete = false` for historical results that threw, because their correlation was never recorded. Only runs made
  after this release benefit.
- (f) Production's `feature_flags` row `scheduler.gateway` (ON since docs/50) becomes inert. Nothing reads it, and the
  scheduler runs through the gateway either way. It still shows on the Flags admin page, where it looks as if it
  governs a bypass. The admin UI has no delete button for flags. **Joy line, to run only after the release is
  verified and accepted.** Do not run it earlier: if HQ is rolled back, the previous code reads a MISSING row as OFF
  and would send tools with no effect down the old direct path again.
  `docker compose -f deploy/docker-compose.yml --env-file deploy/.env exec -T app php artisan tinker --execute="App\Models\FeatureFlag::where('key','scheduler.gateway')->delete(); Illuminate\Support\Facades\Cache::forget('hq:feature_flags');"`
  Recommendation: delete the row once the release is final.
- (g) adds one alert rule, expected to stay silent on production: no agent-executable effectful tool exists, so the
  disagreement cannot happen with today's catalogue. Telegram is used only if it fires.

### 2.9 Open questions

1. Delete the inert production `scheduler.gateway` row after the release, as recommended above, or keep it for one
   release as a rollback cushion? Joy's call.
2. The `bound_thread` guard/gateway disagreement exists by design, because thread binding is a runtime concept. Should
   the gateway learn thread binding so the two layers agree, or is "guard narrower, audited and counted" the intended
   end state? This is an architecture question for ChatGPT, and not urgent.
3. The default threshold of 3 in 24 h is a conservative first guess made with no production data. Revisit it after
   the first real occurrences.
