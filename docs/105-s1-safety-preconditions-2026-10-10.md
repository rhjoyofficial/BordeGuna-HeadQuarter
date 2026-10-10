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

(Filled in as the work lands.)
