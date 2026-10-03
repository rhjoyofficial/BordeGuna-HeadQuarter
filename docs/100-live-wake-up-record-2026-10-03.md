# 100. The live wake-up, the hand-over ping and the inbox context package (stream W2a, 3 October 2026)

Branch `p3/live-wake-up-and-handover-ping` from `main` `70f957e`, worktree `hq-wt/f`, database `hq_test_f`.
What docs/95 §5 "Customer AI" listed as missing under "Wake-up" and "Hand-over", and §16 NOW item 4
asked for first: "Support wake-up path behind `channels.meta.inbound` (emit `conversation.reply_proposed`,
run the agent at A0, proposal to the inbox), hand-over Telegram ping + reason + order card". Built inert;
nothing in production's posture changes.

## What was built

**1. The live wake-up (`App\Conversation\WakeUp`, `SupportWakeUpHandler`).** `ConversationService::ingest()`
already emitted `conversation.message_received`; it now has a second handler beside the no-op one.
`SupportWakeUpHandler` runs in the event drain and asks `SupportWakeUp::refusal()` whether the agent may be
woken for this message. Every precondition is read fresh from the database, in this order, and the first
that fails is the recorded reason: a run already exists for the message; `channels.meta.inbound` is off;
`ai.runtime` is off; the `support` row is not `active`; it names no enabling user; the thread is
`ai_disabled`; the thread is not `ai_active` or `ai_resumed`; the message is not an inbound message on
that thread. A refusal is one audit row, `conversation.wake_up_skipped`, with the reason, the stage
(`event` or `job`), the thread's state and the message uuid, and never the customer's words. When every
precondition holds, `WakeSupportAgent` is queued on the `ai` queue (config `hq.inbox.wake_up_queue`,
listed in `config/horizon.php`) carrying only the two uuids. The job reads everything again when it
runs, takes a cache lock on the message, checks `agent_runs.conversation_message_uuid` (new nullable
column, migration `2026_10_03_110000`), and runs `AgentRunner` exactly as gate P3-G2 does
(`AgentEvaluationSubject::runOnThread`): `SupportContext::blocksFor()` as the context, the question
`Answer the customer on this thread.` word for word from the support suite, `conversationUuid` bound,
`SupportWorkflow::decide()` before the model, requester `Requester::asEnabled(OnBehalfOf, ...)` so the run
borrows the enabling user's authority and nothing more, trigger `conversation` (a new value on
`agent_runs.trigger`, which is a plain string with no CHECK; the six values HQ writes are listed on
`AgentRunRequest::$trigger`). The resolved autonomy is the agent's: A0 today, so `messaging.send_reply` is
a proposal record, no tool call, no delivery, no outbound message.

After the run: a proposal exists, so `conversation.reply_proposed` is emitted (payload: thread, message,
run uuid and id, autonomy level, shadow; dedupe key `conversation:reply_proposed:<message uuid>`; the
registered no-op handler is kept) and the thread moves `ai_active`/`ai_resumed` -> `ai_waiting` with the
run uuid in the reason (docs/72 §B: "the agent proposed a reply and waits"; the core's existing rule
then hands a second customer message to a person). No proposal (the runtime's silent hand-over with zero
model turns, a refusal, a failure, an answer with no reply) moves the thread to `human_required` with
reason `the agent could not serve this thread: ...` (code `agent_cannot_serve`). A run the resolver
refuses to start (the agent paused or unenabled between the event and the job) is audited as skipped and
the thread is handed to a person the same way: a customer is never left in silence with nobody told.

**2. The hand-over ping (`HandoverNotifications`, `Notifier::handoverRequired`, `HandoverReason`).**
`HandoffStateMachine::transition()` to `human_required` from any state, and `ingest()` opening a new
thread straight into `human_required` (the agent paused: no transition exists to hook), both call
`HandoverNotifications::threadNeedsPerson()`. It sends through the existing `Notifier` to every
available `NotificationChannel`: Telegram (the alerts' bot and chat from config) and in-app (an audit row
`notification.raised`). The message: business and brand, channel, reason code, `/inbox/{uuid}`, and a
line saying the customer's words are not in it. No message text, no name, no phone. `HandoverReason`
maps the state machine's reason sentence to one of six codes (`asks_for_person`, `money_or_complaint`,
`second_inbound_while_waiting`, `agent_paused`, `ai_disabled`, `agent_cannot_serve`); a sentence none
matches (the simulator's own "simulation: a person took over" never reaches `human_required`) is
shown as "not recorded" rather than guessed. The sentences the core writes became constants on
`EscalationTriggers`. Once per thread per entry: the key is the state-change audit row id (or `opened`),
written into the in-app row's `after.handover_key` and read before any send, so a second message, a
redelivered event or a second process cannot send twice. Sent with `DB::afterCommit`, so the conversation
simulator's rolled-back replays send nothing and a transition the database refuses is never announced.
`hq.inbox.handover_notify` (default true) switches it off without code.

**3. The inbox context package (`InboxController::show`, `inbox/show.tsx`).** Two new props: `handover`
(null unless the thread is `human_required`): the latest `conversation.state_changed` audit row into
`human_required`, as the reason code, its English label, the sentence, the state it came from, who moved
it (a person's email, or null for the core) and when; a thread that opened there with no transition
reports `agent_paused`. `orders`: the last five `facts_orders` rows for the thread's customer, newest
first (ref, store, status, payment status, placed at, gross), the same five `SupportContext` shows the
agent; empty for an unlinked thread. The page shows an amber "Why a person is needed" card above a
"Recent orders" card; a stranger reads "No known orders". Every new string goes through `useT()` and
has a Bangla entry in `bn.json` (English digits, NFC, no em dash), including the six reason labels.

**4. Readers that find a thread's runs.** `InboxController::proposalsFor()` and `decide()`,
`ShadowComparisons::pair()` and `sweepUnmeasured()` matched a run to a thread by `correlation_id`, a
convention from before docs/90 bound the thread on the run. `AgentRunner` gives every run its own
correlation id, so a real wake-up's proposal would have been invisible on the thread page. They now match
`conversation_uuid` OR `correlation_id`, so rows written either way are found; nothing written before is
lost.

## What keeps it inert, each held by a test

- `channels.meta.inbound` and `ai.runtime` ship off; the support row ships paused with no enabling user;
  the handler and the job refuse on the first of these that fails and audit the reason (`SupportWakeUpTest`:
  "keeps production inert", "does nothing and audits why when any one precondition is false", "does
  nothing for a paused agent").
- No flag default, agent row, provider registration or `config('hq.tools')` entry changed; no prompt text
  added (the question is the suite's existing sentence); `PostureScreenTest` still passes (no new web route).
- At A0 nothing executes: no `tool_calls` row for `messaging.send_reply`, no `message_deliveries` row, no
  outbound `conversation_messages` row (`SupportWakeUpTest` first case).
- One run per message whatever is redelivered (the event marked pending again, the job dispatched again).
- A thread the runtime reads as a silent hand-over never reaches the model: zero model calls, the run
  refused with the verdict, the thread to a person as `agent_cannot_serve`, the ping with the code and no
  customer text.
- The ping carries no customer text, no phone, no sender id; once per entry; nothing when the config is
  off; nothing for the simulator's rolled-back replays (`HandoverNotificationTest`).

## Choices made that docs/95 did not specify (for review)

1. **After a proposal the thread moves to `ai_waiting`.** docs/72 §B defines the state as "the agent
   proposed a reply and waits", and `EscalationTriggers` already hands a second message in that state to a
   person. At A0 the proposal is unsent, so "waiting" is on a person as much as on the customer. If the
   shadow beside n8n (P3-G3) should keep the thread `ai_active` so every customer message wakes the agent
   again, change `SupportWakeUp::settle()`; one line.
2. **No proposal means a person.** Any run that ends without a `messaging.send_reply` proposal (silent
   hand-over, refusal, failure, an answer with no reply, a resolver refusal) moves the thread to
   `human_required` as `agent_cannot_serve` and pings. The alternative, leaving the thread `ai_active` in
   silence, was rejected because nobody would be told.
3. **The silent hand-over is declared by the wake-up from the runtime's classifier** (`SupportWorkflow::
   recommend()`), so the live path does what docs/90 A.1 step 5 ruled for the gate: zero model turns.
   `greeting_first` is NOT declared from the classifier (docs/91 A.2: it only recommends); a live greeting
   runs `context_first` with the recommendation on the record. If the ruling for the live path should
   differ from the gate's, it is one condition in `SupportWakeUp::wake()`.
4. **A new thread opened while the agent is paused is announced** (reason `agent_paused`) although no
   transition happens; it is one entry into the state and the only way a thread opens there. Keyed
   `opened`, once.
5. **`by` on the hand-over card resolves the audit actor to an email** when a person moved the thread;
   the core's transitions show nobody.
6. **The reason-code vocabulary is exactly the six named in the brief**; the inbox's own "the agent was
   switched off for this thread" (from `toggleAi`) maps to `ai_disabled`.
7. **Idempotency key is a new column** (`agent_runs.conversation_message_uuid`) rather than a lookup by
   time or a marker on the message row, with a cache lock for the in-flight window.
8. **Requester kind is `OnBehalfOf` as enabled**, the same shape `Requester::forShadowPeriod()` uses off
   production for a period with no opener; no new `RequesterKind` case.
9. **The `trigger` value `conversation` is documented on `AgentRunRequest`**; no screen enumerates trigger
   values today (the agents screens print whatever the row holds, `ShadowEvidence` counts by value), so
   there was no listing to extend.

## Not built here, on purpose

- No provider registration, no credential, no platform-account row: a proposal a person sends still meets
  "no messaging provider is registered" (P3-G4).
- No change to `EscalationTriggers`' vocabulary, `SupportWorkflow`'s rules or the Support prompt.
- No delivery reconciliation, IG/WhatsApp account seeding, event-kind jobs or briefing schedule (the rest
  of W2).

## Verification (local; CI dark until the 1 October reset is used)

- `DB_DATABASE=hq_test_f php artisan test --compact tests/Feature/Conversation/SupportWakeUpTest.php tests/Feature/Conversation/HandoverNotificationTest.php tests/Feature/Conversation/InboxContextPackageTest.php`: 21 passed.
- Migration `2026_10_03_110000_add_conversation_message_uuid_to_agent_runs` rehearsed on `hq_test_f`:
  `migrate`, `migrate:rollback --step=1`, `migrate` (column present after).
- Full suite `DB_DATABASE=hq_test_f php artisan test --compact`, two runs on 3 October. Run 1 (about 22:40
  Dhaka): 1,702 passed, 1 failed, the one-path guard `ExecutionContextOnePathTest`, which lists every file
  allowed to build an `AgentRunRequest`. The wake-up was added to that list on purpose, with an assertion
  that it asks as the enabling user; the guard then passed. Run 2 (from 23:51 Dhaka): 1,701 passed, 2 failed,
  both in `SendWhatsAppReplyToolTest`, which hit the outbound quiet-hours rule (23:00 to 08:00 Dhaka) first
  and so did not reach the window and template messages it expects. They passed in run 1. Both fail the
  same way on unchanged `main` `70f957e` at the same hour (stash, run, pop), so the bug is already on main and
  this stream did not cause it. Fixed in its own commit on this branch (`deb15ae`): the file's `beforeEach`
  travels to 12:00 Dhaka; checked by mutation (frozen at 23:30 the same two fail, at noon all six pass).
  Run 3 (4 October, after the renames and that fix): **1,703 passed, 20,032 assertions, 0 failed**.
  Every test this stream touched passed in both runs.
- `vendor/bin/pint --test` passed; `npx tsc --noEmit` clean; `npm run build` built.
