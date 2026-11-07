# 143. Owner console: decision record for the "not built yet" items (31 October 2026)

Agent M2 of the HQ completion programme. Branch `feat/complete-owner-console` (from `integration/ui-and-scope`, merged with `hq-complete/base`).
Scope: docs/137 section 6, items Q1, Q8, Q9, Q10, Q11, Q12 (and Q13 verified). Pause All AI (Q7) is agent M1's and is not touched here.

**Every item ships inert.** Six feature flags are seeded OFF by migration `2026_10_31_100000_create_owner_console_tables`. Missing or unreadable means OFF. Nothing here flips a flag; changing one is the ordinary flag toggle, which already needs `hq.flags.manage` AND group-wide authority (docs/138 ruling 13).

| Flag | Item |
|---|---|
| `console.do_mode` | A, Do mode |
| `console.batch_approval` | B, batch review |
| `console.one_click_approve` | C, one-click approve |
| `console.telegram_brief` | D, Telegram brief |
| `support.reply_options` | E, A/B/C reply options |
| `console.event_watches` | F, event watches |

## Important: three of these items disagree with a standing ChatGPT ruling

docs/138 section 4 already ruled on these. Joy's task asked for them to be built behind OFF flags, so they are built, but to the **narrowest reading** of each ruling, and the conflict is stated so ChatGPT can confirm or withdraw the item before any flag is switched on.

| Item | docs/138 ruling | What was built (the default) | Question for ChatGPT |
|---|---|---|---|
| A, Do mode | 1: NOT NOW. Future Do mode "may create structured proposals", consequential effects through the gateway; not before Phase 9 evidence and a separate activation ruling | The mechanism only, flag OFF. Plans are structured proposals; every effect is a gateway call and waits for its own approval | Keep the mechanism dark until the Phase 9 activation ruling (our default), or remove it |
| B, batch approval | 8: one approval per message; a batch VIEW may list and open them in sequence; approving one never approves siblings | The batch is a review page plus one act, but each message is its own decision (see B) so ADR-018 binding holds. The "open in sequence" reading is always available through the normal approval page | Is "one act, each message separately bound and re-checked" acceptable, or only the sequential view |
| C, one-click approve | 9: NO. A decision card must not approve in place; the reviewer must see action, scope, destination, payload and evidence first | Built OFF. The card first shows the full payload; the click posts the hash of what was shown; below tier R5 only. Same executor, same guards | This directly contradicts ruling 9. Default: it stays OFF and is deleted if ChatGPT does not reverse the ruling |

## A. Do mode (Q1)

- **Plan** = `do_plans` + `do_plan_steps` (HQ-owned rows): business, title, 1 to 5 steps, each a tool key, validated arguments and a summary. Proposed by a person, or by an agent acting under a named person (`DoPlanService::propose`).
- **Eligible tools**: a plan may name only (a) a read at tier below R3, which runs on its own, and (b) a tool of tier R4 or R5, which policy parks on an approval. An R3 write is refused at proposal, because policy would run it without asking.
- **Start** needs `hq.do.start` and the business in scope. Starting approves nothing. It creates a run of the new HQ-native workflow type `do.plan` (no tool step, as docs/120 item 17 requires; it only holds the plan open) and puts step 1 to the gateway as the person who started it, caller kind `workflow`.
- **Effectful step**: before it is submitted, the gateway's read-only `authorize()` must report "awaits approval"; otherwise the plan halts (policy would run it unasked). The step then waits on an ordinary approval, decided in the Approval Center by `ApprovalExecutor` (separation of duties, hash, single use unchanged). `DoPlanService` never decides an approval.
- **Order**: step N+1 is submitted only after step N succeeded. Rejected, expired, cancelled or failed halts the plan; the rest are marked skipped and never run. Cancelling withdraws the pending approval and cancels the run.
- **Advance**: `hq:do-plans:advance` (every minute; a no-op while the flag is OFF) reads decided approvals back. A plan stands still if the flag is turned off mid-plan.
- **Shown**: `/do-plans`, `/do-plans/{plan}` (steps, state, the approval each waits on), a console attention row, sidebar entry (only while the flag is on).
- **Default taken where no ruling exists**: plans are limited to 5 steps; a plan whose starter loses access to the business halts.

## B. Batch approval of customer messages (Q8)

- `ApprovalActs::batch`: up to 25 approvals of **customer-facing tools** (descriptor `customerFacing`). Per message, in order: visible to this person (else "not found", no hint another business exists); the payload hash the person was shown equals the approval's (a regenerated variant has a new hash and is never covered); still pending; the person may decide it (decide permission, separation of duties, delegation); the gateway's read-only `authorize()` (scope, permission, consent, policy) passes **before** the approval is claimed, so a message that would be refused is left pending, not spent; then `ApprovalExecutor::approveAndExecute`.
- Each message writes its own audit row `approvals.batch_item` (approval id, hash, result; never the text) and the act writes one `approvals.batch` row and one `approval_batches` record. A failed check removes that message only.
- Review page `/approvals/batch` lists only pending customer messages the person may see, in full, with a checkbox each.

## C. One-click approve on a console card (Q9)

- `ApprovalActs::oneClick` is the same single-item path (visibility, shown hash, pending, authority re-read server-side, gateway pre-check, executor). It refuses tier R5 and sends it to its own page.
- The console card carries `quick` (payload and hash) only when the flag is on, the tier is below R5 and the viewer may decide it. The card shows the payload inside "Show what will run" and the button posts the hash.

## D. Morning brief on Telegram (Q10)

- New gateway tool `briefing.send_owner_telegram`: tier **R4**, so every call parks on an approval (and a scheduled job that runs it is created waiting for approval); permission `hq.briefing.deliver`; not agent-executable; scope Business with `business_slug` required, so the gateway refuses, for the person who asks, a business they may not act for, **before** anything is parked (an approval runs under the approver's authority). The group-wide brief has no tool in v1.
- **No destination argument**; `additionalProperties` is false. The chat comes from configuration and `TelegramOwnerChannel` accepts only a private chat id (digits, positive; groups and channels have negative ids) that the owner confirmed (`HQ_OWNER_TELEGRAM_CHAT_VERIFIED=true`).
- Text built by `BriefMessage` from the **stored** briefing only: stale and missing sources first; business or group totals only (no per-store row, no text or identifier items); the narrative only when `narration_state` is `narrated` AND the stored unsourced-number check passed AND every figure it found is marked sourced, otherwise the evidence figures alone. One delivery per briefing (unique idempotency key); an approved retry replays and sends nothing. A timeout is **uncertain**, never reported as delivered.
- Transport: interface `OwnerChannel` with `UnavailableOwnerChannel` (shipped binding `HQ_OWNER_CHANNEL=none`), `FakeOwnerChannel` (tests) and `TelegramOwnerChannel`. The token is never logged. The briefing page gets "Ask to send to my Telegram", which only parks the approval.
- **Needs from the owner to make it real**: a Telegram bot token, the owner's private chat id, `HQ_OWNER_CHANNEL=telegram`, `HQ_OWNER_TELEGRAM_CHAT_VERIFIED=true`, flag `console.telegram_brief` ON, and an approved job pair (compose, then send).

## E. Support reply options A/B/C (Q11)

- `ReplyOptionService`: 1 to 3 drafts per thread, labelled A, B, C, read from the proposals a Support run already recorded (no prompt or corpus change). The outbound policy (consent, handover state, one reply per inbound) is asked at proposal time too.
- A person picks a label and may edit it. The exact final text is sent through the existing gateway call `messaging.send_reply` as that person (`hq.inbox.manage`), the same call the inbox's proposal decision makes, so script, consent and policy checks are the gateway's. The set is single use (one conditional update `open` to `chosen`); the other labels can never be sent; a failed send puts the set back to open. Every pick writes a decision record (label, edited or not) and an audit row.
- **Default taken**: "through approval" is read as the existing human-decides model of the inbox (the person who picks and sends is the approver, as in `InboxController::decide`), not a second parked approval. If ChatGPT wants a separate approver, this is the one place to change.

## F. Event watches from the chat (Q12)

- `EventWatchService`: a watch is one event type of ONE business, up to 5 conditions (eq, neq, gt, gte, lt, lte, contains), an optional action `{tool_key, args}`, an hourly and a daily limit (max 20 and 100). Born `proposed`; enabled, paused or rejected by a named person with `hq.watches.enable` who may act for its business.
- **It can only propose**: the action must be a tool of tier R4 or above; at fire time the gateway's `authorize()` must say "awaits approval" or the firing is recorded as `proposal_refused`; the proposal is an ordinary approval requested as the person who enabled the watch. The arguments are fixed when the watch is proposed and nothing from the event is copied in.
- Fired from the event drain (`ProcessDomainEvents` calls `EventWatchService::deliver`, never throwing into the drain). Once per (watch, event) by a unique index; beyond a limit a firing is recorded `rate_limited` and nothing is asked of the gateway. An event of no business matches no watch.
- The screen proposes notify-only watches; an action is set by the service (a person with the API, or an agent under a named person). **Default**: no free-text natural language parser was added; the chat produces the structured fields and a person confirms them.

## G. Group-wide authority (Q13)

Already implemented (docs/138 ruling 13, `GroupWideAuthority`, middleware `group.wide`). Verified for the new switches: each of the six flags can be toggled only through `flags.toggle`, which a restricted operator gets 403 on (test `OwnerConsoleFlagsTest`), with scope, before and after in the audit row. The new actions themselves are per business, not global: a person limited to business A cannot plan, watch, batch-approve or send options for business B (404 or refusal, tests in `tests/Feature/OwnerConsole`). No new global switch was added.

## Structural pins updated (deliberate, reviewed)

New effectful tool in `GatewayCoverageStructureTest`, `ToolNamespaceTest`, `PolicyEngineTest`, `ToolGatewayTest`, `ApprovalCenterTest`; new gateway call sites (`DoPlanService`, `EventWatchService`, `ReplyOptionService`, `BriefingController::sendTelegram`) in the coverage allow-list; new controllers in the business-scope audit; new write routes in the posture allow-list and the approvals route test; sidebar count 26 to 29; the briefing page now has exactly one write route.

## Not done, on purpose

No real Telegram call, no live customer send, no production flag, no deploy. Voice, media, email, ERP bill and courier items are other agents'.

## Applied ruling 31 Oct (ChatGPT final ruling, items 1 to 6)

Builder R1, branch `ruling/r1-approvals-console` from `hq-complete/release-candidate` (`b721c9a`). The ruling text is binding and was applied as written, never more permissively. Where it left a choice, the most conservative reading was taken and is named below. The three conflicts in the table above are settled by the ruling: Do mode stays dark, batch is sequence only, one-click is removed.

**1. Do mode: kept in code, DARK.**
- New activation gate `App\OwnerConsole\Do\DoModeActivation`. Do mode is available only with BOTH a separate activation record (`ACTIVATION_RULING`, a constant in code, `null`) AND the flag `console.do_mode` ON. Switching the flag row on alone changes nothing; setting the record is a code change through review, tests and a release.
- Every door checks it: all `/do-plans` routes are a 404 (middleware `EnsureDoModeActive`), `DoPlanService` refuses to propose, start or continue, a running plan stands still, `hq:do-plans:advance` does nothing, and the sidebar share `console_do_mode` reads the gate.
- No automatic continuation: the scheduler only reads decided approvals back and runs read steps; the next effectful step is put to the gateway only by a named person's explicit act (start, or "Continue to the next step"), at most one effectful step per act.
- Per-step binding: a step's approval must be for the same tool and business with its payload intact, or the plan halts; a step never reuses an approval and a replayed or shared approval id halts the plan.
- Before each effectful step: activation gate, the continuing person's permission and business scope, the starter's standing, then the gateway's read-only `authorize()` (scope, permission, consent, policy, execution context), then `ToolGateway::execute()` only.
- No self-approval: `Approval::separationOfDutiesReason` now refuses a Do step's approval to the person who started the plan and, for an agent-proposed plan, to the person the agent acted under, whatever the tool's `requesterMayDecide` says. Default taken: the starter is barred even on a human plan (starting must never act as an approval).
- Tests: `tests/Feature/OwnerConsole/DoModeGateTest.php` (new), `DoPlanTest.php` (runs the mechanism under a test-only stand-in activation, `tests/Support/OwnerConsole/TestDoModeActivation.php`).

**2. Batch approval: sequence only.**
- Removed: `ApprovalActs` (the multi-approval executor), `POST /approvals/batch`, the `approval_batches` table and the `ApprovalBatch` model.
- Kept: `GET /approvals/batch`, now a read-only overview (flag `console.batch_approval`, OFF) linking each message to its own approval page with `?queue=messages`. That page shows the queue and offers "Next message" only after the current one is no longer pending. Each message is approved through the one `approve` action: one approval, one payload hash, one destination, one gateway pre-check, one `ApprovalExecutor` call, one audit result.
- Structural tests (`ApprovalPathStructureTest.php`): exactly one route reaches the executor (`POST approvals/{approval}/approve`), only `ApprovalController` calls it in all of `app/`, once, with no loop; every approval write route is about one route-bound approval; extra ids in a request approve nothing else; no multi-approval route, class, table or flag remains.

**3. One-click approval: removed.**
- Removed the route `approvals/{approval}/quick-approve`, its handler, the flag `console.one_click_approve`, the `quick` field from `ConsoleReader` and the card UI. A console decision card only links to the full approval page.
- The narrow exception M2 added to `tests/Feature/Observe/ConsoleTest.php` is reverted to the base wording. `ApprovalPathStructureTest` adds that cards carry no hash, payload or action and no console source posts to an approval decision.

**4. Support A/B/C reply options: approved with conditions, now held by tests.**
- The send request now carries the thread's language requirement (resolved by HQ from the conversation and the active language policy, never the model), so the gateway's `reply_script` pre-execution check refuses a wrong-script reply, edited or not. Before, a person's request carried none and the script was not checked.
- The audit row now records the actor, `text_sha256` of the exact text sent, the time, the tool call and the result (never the text).
- The screen shows the recipient (customer, channel, business) and states that the exact text in the box is what is sent, as that person.
- Tests added in `ReplyOptionsTest.php`: audit content, script refusal, gateway-only as the person, only the inbox controller calls `choose()` (alternatives never sent by themselves), recipient shown.

**5. Telegram owner brief: business-scoped v1 only.**
- `business_slug` is required AND non-empty (`minLength: 1`), and `execute()` refuses any call without a business, so not even a group-wide person can send a group-wide brief. The group-wide branch is gone.
- Tests added: empty or missing business refused with no approval and no delivery; no group brief tool; no argument names a destination; `OwnerChannel::send` takes text only; the message goes to the configured, verified chat even when the stored narrative names another chat.

**6. Event watches: approved with conditions.**
- Bounded, versioned definitions: `EventWatchDefinitions` (order, payment, shipment and customer-registered events, version 1 each, listed condition fields only). `conversation.*` events are deliberately not watchable. The watch stores `event_version`; a changed definition stops it firing (`definition_changed`) and blocks re-enabling.
- Rate limits enforced server-side per watch, per BUSINESS (all its watches) and per RESPONSIBLE ACTOR (the person who enabled it, across businesses), 20 an hour and 100 a day, counted under row locks on the business and the person.
- The screen creates notify-only watches; an action posted to the route is ignored. An action watch is proposed only through the service with fixed, validated arguments, re-validated at every firing.
- The action cannot be rewritten: `EventWatch` refuses any change to its business, event, version, conditions, action or proposer after creation.
- Watch creation needs the existing approval step: an action watch is enabled only by a person other than its proposer (default taken; notify-only watches may be enabled by their proposer).
- A trigger only parks an approval (R4+ tool, `authorize()` must say it awaits approval); the approval and the gateway decide any effect. A customer-facing tool, or one whose role action is a customer reply, can never be a watch action.

**Migration.** `2026_10_31_100000_create_owner_console_tables` was edited in place, because it has never run outside a test database: no `approval_batches`, no `console.one_click_approve` row (five flags now, all OFF), `event_watches.event_version` added, `do_plan_steps.approval_id` indexed. Flag rows in a fresh database: 15 (was 16).

**Shared pins touched (minimal):** `bn.json` (10 entries appended), `PostureScreenTest` allow-list (2 write routes removed), `ApprovalScreenTest` route list (batch POST and quick-approve removed), `ConsoleTest` (M2 exception reverted), `OwnerConsoleFlagsTest` (five flags). Seeder, AppShellTest count and structural-searches.php were not touched.
