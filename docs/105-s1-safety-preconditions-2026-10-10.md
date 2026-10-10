# 105. S1 safety preconditions (10 Oct 2026)

Stream S1 of the ChatGPT ruling of 10 Oct (`docs/103-chatgpt-ruling-2026-10-10.txt` §3, `docs/101` §13), the two
preconditions recorded on 6 Oct as Q4 and Q5 (`docs/101` §12). Branch `platform/s1-safety-preconditions` from main
`c12792f`, worktree `hq-wt\e`, test database `hq_test_e`. Nothing here activates anything: no provider is registered,
no agent is given a tool, no flag is switched, no autonomy is widened and no prompt text changes.

## 1. Plan (written before the code)

### 1.1 Q4: `SupportWakeUp::settle()` and an executed reply

Today `settle()` looks only for a record of kind `proposal` for `messaging.send_reply`. At A2 and above the runner
does not write a proposal for a reply the gateway executed: it writes an `evidence` record with the gateway's
`tool_call_id`. `settle()` would read that run as "no reply proposed" and hand the thread to a person as
`agent_cannot_serve` although the customer was answered. Unreachable today: ADR-024 caps every run whose context holds
a customer's words at A1, and `allowsExecution()` starts at A2.

Design:

- `settle()` first looks for an EXECUTED reply: an `evidence` record for the reply tool whose `tool_call_id` names a
  `tool_calls` row that is the reply tool, status `succeeded` or `replayed`, and whose validated input names THIS
  thread. The record alone is not trusted; the gateway's row is the verification.
- Executed reply: the event `conversation.reply_sent` (new type, registered on `ConversationEventHandler` like
  `reply_proposed`, dedupe `conversation:reply_sent:{message uuid}`), then `ai_resumed -> ai_active` when needed and
  `-> ai_waiting` (the agent answered and waits on the customer; the same state as after a proposal, ruling 9.3). No
  hand-over, no ping.
- A reply the gateway reports `uncertain` (it may have reached the customer): to a person, with the reason saying the
  delivery is uncertain and naming the tool call, never as a plain "no reply".
- A record that claims execution with no matching succeeded row: not trusted, to a person (fail closed).
- Proposal: unchanged. Neither: unchanged hand-over.
- `settle()` becomes public so the A2+ branch can be exercised directly while the cap keeps it unreachable through
  `run()`. The A1 cap is NOT lifted.

### 1.2 Q5: the other eight effectful tools

"Effectful" is the runner's own rule (`AgentRunner::hasEffect`): a descriptor that writes, or risk tier R3 and above.
The registered tools it selects, from `config('hq.tools')`:

| # | Tool | Tier | Effect |
|---|---|---|---|
| 0 | `messaging.send_reply` | R3 write | Messenger reply to a customer (already reaches the gateway on every path) |
| 1 | `demo.request_approval` | R4 write | one audit row, approval-gated demo |
| 2 | `erp.poll_overview` | R3 write | upserts `facts_erp_snapshots` from the ERP |
| 3 | `erp.poll_low_stock` | R3 write | upserts `facts_erp_snapshots` from the ERP |
| 4 | `commerce.reconcile_facts` | R3 write | inserts missing `facts_orders` from a shop |
| 5 | `briefing.compose` | R3 write | writes a `briefings` row |
| 6 | `commerce.take_order` | R4 write | ERP draft + sales order (inert: empty store list) |
| 7 | `messaging.send_whatsapp_reply` | R3 write | WhatsApp message to a customer (inert: no provider) |
| 8 | `commerce.cancel_sales_order` | R4 write | cancels an ERP order (inert: empty store list) |
| 9 | `consent.lift_restriction` | R3 write | lifts a do-not-contact in HQ's own ledger under two-person control (docs/114 §3, added after this document; every call parks for a second approver, permanently human-only) |

Where each of the eight stops short of the gateway today (found by reading every caller of `Tool::execute`,
`Tool::reconcile` and the services behind the tools):

1. **Agent runtime.** `AgentRunner::invoke()` refuses them with runtime checks BEFORE the gateway is asked
   (`AgentToolset::refusalFor`: not agent-executable, not granted, switched off; the refused role action; the bound
   thread). Nothing executes, but the decision is the runtime's, not the gateway's. This is the literal Q5 finding
   (the governance test's exemption "one the guards refuse never reaches the gateway").
2. **Scheduler direct mode.** `RunScheduledJob::executeDirectly()` calls `$tool->execute()` / `$tool->reconcile()`
   with no gateway at all (no policy, no approval, no consent, no idempotency key, no `tool_calls` row, no audit)
   whenever the `scheduler.gateway` flag is off, and a missing flag row reads as OFF (`docs/95` notes no row is
   seeded). A scheduled `commerce.take_order` would run an R4 order write without its approval on that path.
3. **`hq:facts:reconcile`.** The console command performs `commerce.reconcile_facts`'s effect by calling
   `FactsReconciler` directly, outside the gateway.

Design:

- `ToolDescriptor::hasEffect()` becomes the one definition of "effectful" (the runner's private copy is replaced),
  used by the runner, the scheduler and the structural tests.
- (1) Every effectful call an agent names is put to the gateway. The runtime's guards stay as narrowings (they can
  only remove), but for an effectful tool the gateway's `authorize()` (read-only: records nothing, spends nothing,
  executes nothing) is asked too, and its verdict is on the run's record (`gateway` key) and in the audit
  (`agent_run.effectful_call_refused`). The refusal the model reads is unchanged (no model-facing text change). A
  call the runtime refuses never executes, whatever the gateway would say; if the gateway would have permitted what
  the runtime refused, the audit says so (`gateway_would_authorize: true`), as a signal for review.
- (2) An effectful scheduled job always runs through the gateway, whatever the flag says. Direct mode remains only for
  tools with no effect (the migration rollback for reads), and refuses an effectful tool structurally.
- (3) `hq:facts:reconcile` runs `commerce.reconcile_facts` through the gateway as a console caller (`forConsole`,
  system identity, which holds `hq.tools.commerce.read`).
- Inert: no tool registration, `agentExecutable`, `allowed_tools`, store list, provider or flag default changes.

### 1.3 Tests (each watched failing on the unmodified code where possible)

- `SupportSettleExecutedReplyTest`: an executed reply (succeeded row) moves `ai_active -> ai_waiting` with
  `conversation.reply_sent` and no hand-over; `ai_resumed -> ai_active -> ai_waiting`; a replayed row counts; an
  uncertain row goes to a person naming the uncertainty; a record whose row failed, is missing, is another tool or
  names another thread goes to a person; a proposal still goes to `ai_waiting`; executed wins over a proposal.
- A1 cap still holds: a grant and agent ceiling at A3 still resolve every Support wake-up run to A1, nothing executes
  (no `tool_calls` row for the reply, no delivery, no outbound message), the thread settles via the proposal path.
- `EffectfulProposalGovernanceTest` tightened: for EVERY registered effectful tool, every record carries a gateway
  decision (no runtime-only exemption); one test per runtime guard (not granted, not agent-executable, refused role
  action) showing the gateway was asked and the refusal stands.
- `GatewayCoverageStructureTest` (structural): only `ToolGateway` invokes `Tool::execute()`, `Tool::reconcile()` or
  `PreviewableTool::preview()` on a tool, apart from the scheduler's direct path, which must check `hasEffect()`
  first; the services behind effectful tools (`FactsReconciler::reconcileStore`, `BriefingComposer::compose`, the
  ERP write methods, `FactsErpSnapshot` writes) are called only from their tools; every effectful tool class in
  `app/Tools` (registered or not) is covered by the descriptor rule.
- Scheduler: with the flag off, an effectful job runs through the gateway (a `tool_calls` row; an R4 tool parks an
  approval and does not execute); a no-effect job keeps the direct path.
- `hq:facts:reconcile` records a gateway `tool_calls` row as a console caller and keeps its output.

## 2. Record of what was built

### 2.1 Commits (branch `platform/s1-safety-preconditions`, from main `c12792f`)

| Commit | What |
|---|---|
| `00d03bf` | this plan |
| `b00506b` | part 1: `settle()` for an executed reply |
| `6ecf276` | part 2a: agent runtime, guard refusals of effectful calls put to the gateway; `ToolDescriptor::hasEffect()` |
| `6b2b299` | part 2b: scheduler, effectful tools always through the gateway |
| `fd2696e` | part 2c: `hq:facts:reconcile` through the gateway |
| `8da74ca` | part 2d: `GatewayCoverageStructureTest` |
| (this commit) | this record |

Not merged, not deployed, nothing on production touched.

### 2.2 Q4: `settle()` as built

`SupportWakeUp::settle()` (still private; one deviation from §1.1, which said public: the tests call it by
reflection, so no new public surface was added) now settles in this order:

1. **Executed reply**: an `evidence` record for `messaging.send_reply` whose `tool_call_id` names a `tool_calls` row
   that is the reply tool, `succeeded` or `replayed`, with `input.conversation_uuid` = this thread
   (`replyCall()`). Then `conversation.reply_sent` (payload: thread, message, run, level, shadow, `tool_call_id`,
   row status; dedupe `conversation:reply_sent:{message uuid}`; registered on `ConversationEventHandler` in
   `config/hq.php`), `ai_resumed -> ai_active` if needed, `-> ai_waiting` with reason "the agent sent its reply and
   waits (run ..., tool call #...)". An executed reply wins over a proposal on the same run.
2. **Proposal**: unchanged (`conversation.reply_proposed`, `ai_waiting`).
3. **Neither**: hand-over as `agent_cannot_serve`; when the reply's row is `uncertain` the reason says "a reply was
   attempted and its delivery is uncertain (tool call #N), so a person must check what the customer received".

A record the row does not confirm (row failed, denied, missing, no id, another tool, another thread, a read's
evidence) goes to a person. The A1 cap is not lifted: `AutonomyLevel::allowsExecution()`, the ADR-024 context
ceiling and every Support wake-up path are unchanged.

### 2.3 Q5: gateway coverage, before and after

| Tool | Agent runtime: before | Agent runtime: after | Scheduler: before | Scheduler: after | Other entry points |
|---|---|---|---|---|---|
| `messaging.send_reply` | gateway (A0 `authorize`, A2+ `execute`) | same; guard refusals also asked | gateway only if flag ON, else direct | always gateway | inbox send: gateway (unchanged) |
| `demo.request_approval` | refused by runtime guard (not cleared), gateway never asked | guard refusal + gateway `authorize` verdict on record and audit | direct if flag OFF/missing (R4 with no approval) | always gateway (parks approval) | approval executor: gateway |
| `erp.poll_overview` | runtime guard only | guard + gateway verdict | direct if flag OFF | always gateway | none |
| `erp.poll_low_stock` | runtime guard only | guard + gateway verdict | direct if flag OFF | always gateway | none |
| `commerce.reconcile_facts` | runtime guard only | guard + gateway verdict | direct if flag OFF | always gateway | `hq:facts:reconcile` called `FactsReconciler` directly -> now gateway (console caller) |
| `briefing.compose` | runtime guard only | guard + gateway verdict | direct if flag OFF | always gateway | none |
| `commerce.take_order` | runtime guard only | guard + gateway verdict | direct if flag OFF (R4 order write with no approval) | always gateway | workflow runner / approval executor: gateway |
| `messaging.send_whatsapp_reply` | runtime guard only | guard + gateway verdict | direct if flag OFF | always gateway | none |
| `commerce.cancel_sales_order` | runtime guard only | guard + gateway verdict | direct if flag OFF (R4) | always gateway | workflow runner / approval executor: gateway |

Every one stays inert: no `config('hq.tools')`, `agentExecutable`, `allowed_tools`, `orders.take_order_stores`,
provider, flag default or prompt change. Today the gateway refuses every one of the eight for an agent
(`action_class.human_only`), and for the two order tools and WhatsApp it would refuse every caller (empty store
list, no provider).

Agent-runtime detail: the guard's refusal text is what the model reads, unchanged. The gateway is asked with
`authorize()` (no `tool_calls` row, no approval, no quota, no execution) at every level, A2 included, so a guard
refusal can never become an execution. The record gains `gateway: {asked: authorize, guard, decision, outcome_class,
reason}` and the audit `agent_run.effectful_call_refused` (with `gateway_would_authorize`). The A0 authorize-refusal
record also carries `gateway` now, so every record of an effectful call names the gateway decision it rests on.

### 2.4 Tests

Full suite on the branch: **1,876 passed (23,178 assertions)**, `--parallel --processes=3`, `hq_test_e` (main
`c12792f` was 1,847). `vendor/bin/pint --test` passed; `npx tsc --noEmit` clean; no frontend file touched, so no Vite
build was needed.

New or changed, each watched failing on the code before its fix:

- `tests/Feature/Conversation/SupportSettleExecutedReplyTest.php` (new, 15 cases): executed reply -> `ai_waiting` +
  `reply_sent`; `ai_resumed` path; replayed counts; executed wins over a proposal and is recorded once; uncertain ->
  person with the reason; 7 unconfirmed-record cases -> person; proposal / no-proposal unchanged; handler registered;
  **A1 cap under a grant and agent ceiling of A3: run at A1, reply a proposal, no reply `tool_calls` row, no delivery,
  no outbound message**. 6 failed before the fix.
- `tests/Feature/Agents/EffectfulProposalGovernanceTest.php` (tightened): every registered effectful tool now reaches
  the gateway (was only `messaging.send_reply`); every record rests on a gateway decision; `hasEffect()` equals the
  restated rule for every tool. Failed on the old runner.
- `tests/Feature/Agents/EffectfulGuardRefusalGatewayTest.php` (new, 5): not granted, not cleared (`demo.request_approval`),
  refused part of a request, an A2 run never executes a guard-refused call, unknown keys and reads untouched. 4 failed
  on the old runner.
- `tests/Feature/Gateway/SchedulerGatewayTest.php` (+3): flag off + R4 write parks an approval and does not run; flag
  off + `erp.poll_low_stock` leaves a gateway `tool_calls` row; the direct path checks the effect first. 3 failed on
  the old scheduler.
- `tests/Feature/Facts/ReconcileAndReplayTest.php` (+2): the command's gateway row and R3 audit; switched off -> refused,
  no fact touched. 2 failed on the old command.
- `tests/Feature/Gateway/GatewayCoverageStructureTest.php` (new, 4, structural): only the gateway invokes a tool
  (reviewed receiver list for every `->execute/->reconcile/->preview` in `app/`); no concrete tool constructed outside
  `app/Tools`; the services behind effectful tools reached only from their tools; the nine effectful tools pinned. 3
  failed on `c12792f`.

### 2.5 Behaviour changes a reader should know

- `hq:facts:reconcile` now writes a `tool_calls` row and an R3 audit per store, honours the tool's runtime switch,
  and exits non-zero on any gateway refusal other than a missing admin token (still a warning).
- A scheduled effectful job runs through the gateway even when `scheduler.gateway` is off or has no row. Production
  has run gateway mode since docs/50 (flag ON), so production behaviour is unchanged if that row is still ON.
- Agent runs that name an effectful tool a guard refuses now write one extra audit row each
  (`agent_run.effectful_call_refused`); model-visible text is unchanged.

### 2.6 Decisions that may need a ChatGPT ruling

1. **Event type for an executed reply.** Built as a new `conversation.reply_sent` (recorded only, like
   `reply_proposed`). When P3-G4 wires the real outbound adapter, `ChannelSender` / the delivery ledger may emit their
   own outbound event; confirm `reply_sent` stays the settlement record, or should be folded into that.
2. **State after an executed reply.** Built as `ai_waiting` (the agent answered and waits on the customer), the same
   as after a proposal; the Q1 rule (a second inbound while `ai_waiting` goes to a person) therefore also applies after
   an executed reply. Confirm, or rule a different state for executed replies when the A1 cap is lifted.
3. **Retiring scheduler direct mode.** Direct mode now serves only tools with no effect. Proposed: retire it entirely
   in a later change (and seed the `scheduler.gateway` row, or drop the flag), since its purpose was the M2 migration
   rollback.
4. **Guard narrower than the gateway.** If the gateway ever WOULD authorize what a runtime guard refused, the refusal
   stands and the audit says `gateway_would_authorize: true`. Not reachable with today's guards (the gateway enforces
   the same catalogue, grant, availability and role checks). Confirm "refusal stands, audit only" rather than an
   alert.
