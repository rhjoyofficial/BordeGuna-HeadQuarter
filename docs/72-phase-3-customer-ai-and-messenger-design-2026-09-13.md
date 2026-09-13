# 72 - Phase 3 design: customer AI and the Messenger channel, built inert first (13 September 2026)

**Status: DESIGN, written in stream 2 of the gate-7 milestone (`docs/71`) on ChatGPT's instruction. Stream 3 builds the inert parts of it on the same branch. Nothing in this document is customer-facing until gate P3-G3 at the earliest, and nothing sends until P3-G4.**

Phase 3 is the first phase in which HQ speaks to a human being who is not Joy (`docs/53`). The
roadmap's line for it (`docs/30`): a conversation engine, a handoff state machine and an inbox; a
Messenger channel adapter in shadow beside the n8n bot, then cutover; Instagram DMs and Facebook
comments; customer memory; a `support` agent. The customer platform it rests on is `docs/14`. This
document takes those two, the Phase 2.5 foundation that was built for exactly this (`docs/57`), the
eleven invariants of `docs/60` and ChatGPT's Phase 3 instruction, and says what is built, in what
order, behind which gates, and what is deliberately left off until each gate passes.

The one sentence that governs it, from the thread: **no direct model to Meta path.** A customer
message reaches the model only through the conversation core with its provenance attached; a reply
reaches the customer only through the Tool Gateway with consent, policy, permission and autonomy in
front of it, and delivery verified after.

## A. What already exists (do not build twice)

Phase 2.5 built the storage and half the vocabulary (`docs/57` §F, migration
`2026_09_09_340000`): `conversations` (business, brand, nullable customer, channel,
`external_thread_ref`, status, `owner` none|ai|human, `handoff_state`, assignee, agent key),
`conversation_participants` (with `identity_id` into `customer_identities`), `conversation_messages`
(direction, author, channel, `external_message_ref`, body, **provenance**, digest, `ai_run_id`,
`tool_call_id`, status recorded|queued|sent|delivered|read|failed), attachments to `media_assets`,
and `memories`. `App\Conversation\ConversationService` opens or finds a thread, records inbound,
outbound and internal messages, adds participants, links a participant by channel identity, and
hands off in the simple accepted/returned sense. `App\Memory\MemoryStore` remembers and recalls into
a `ContextBundle`. The consent ledger (`App\Consent`, ADR-021) is wired into the gateway as step 5b,
and `ToolDescriptor` refuses at construction a customer-facing outbound tool with no consent class.
`App\Platform` declares `MessagingChannel` (send service, send marketing, send template, receive,
presence) with **no implementation and an empty registry**. `Channel` (`App\Gateway\Descriptor`)
already has Messenger, Instagram and WhatsApp and maps each to its `customer_identities.channel` key
(`messenger_psid`, `instagram_igsid`, `whatsapp`). `IdentityResolver` links a shop order to one
customer; `customer_identities` already knows the channel kinds. The shop webhook
(`ShopWebhookController`) is the receiver pattern: HMAC over the raw body, a timestamp window, one
`events` row, 202, asynchronous processing with retry, dead-letter and replay. The agent runtime
(`AgentRunner`), its prompts, the role suites and the gate runner are all reusable for a `support`
role without change.

What does not exist: any adapter, any webhook for Meta, the doc-14 state machine (only
accepted/returned), an inbox screen, a support agent, a reply tool, the identity path for a stranger
who arrives by Messenger, the outbound policy, delivery verification, and the shadow-comparison
record. Those are Phase 3.

## B. The shape

Inbound, top to bottom, every arrow a boundary that keeps provenance:

- **Meta webhook** (`POST /api/webhooks/meta`, and the `GET` verification handshake): signature
  `X-Hub-Signature-256` verified against the app secret opened through the vault; the raw payload
  is recorded as ONE `events` row (`conversation.inbound_received`, digest not body) and answered
  202; nothing else happens in the request. Behind feature flag `channels.meta.inbound`, OFF by
  default; with the flag off the route answers 404 and records nothing. This is the only place Meta
  touches HQ, and it touches nothing but the event inbox.
- **Channel adapter** (`App\Conversation\Channels`): `InboundChannelAdapter::normalise(array
  $payload): iterable<InboundMessage>` turns a provider payload into channel-neutral
  `InboundMessage` values (channel, the platform account reference such as the page id, external
  thread and message refs, the sender's channel identity, body, attachment references, received-at,
  a digest). The Meta normaliser is a pure function over Graph webhook JSON, unit-tested against
  recorded fixtures, and holds no token. A `SimulatedChannelAdapter` exists for tests, fixtures and
  the P3-G2 shadow: it accepts a scripted conversation and produces the same `InboundMessage`s.
- **Conversation core** (`ConversationService::ingest(InboundMessage)`): resolves the business and
  brand from the platform account; opens or finds the thread by (business, channel, external thread
  ref); resolves the sender (below); records the message with provenance **customer**, body
  NFC-normalised, language tagged (`bn`, `en`, `banglish`) in metadata; grants `Service` consent on
  that channel with basis `Inbound` in the ledger (the 24-hour reply window Meta itself defines;
  marketing consent is never inferred from a message); runs the state machine; emits
  `conversation.message_received`.
- **Identity** (`IdentityResolver::resolveChannelIdentity(Channel, externalId, Business)`): the
  `customer_identities` row for that channel and id, or a new customer holding only that identity, a
  stranger until a phone or an order links them (`docs/14` rules; merging stays a human decision).
- **State machine** (`docs/14`): AI_ACTIVE, AI_WAITING, HUMAN_REQUIRED, HUMAN_ACTIVE, HUMAN_RESOLVED,
  AI_RESUMED, CLOSED, plus `ai_disabled` per conversation. Escalation triggers are deterministic and
  run in the core, not in the model: the customer asks for a person (Bangla and English forms),
  refund or payment or complaint vocabulary, a second inbound while AI_WAITING, the agent's own
  proposal saying it cannot serve, the support agent inactive or below the autonomy the reply needs.
  While the support agent is paused, which is all of this milestone, every inbound thread lands in
  HUMAN_REQUIRED: the inbox works before the AI does.
- **Customer memory**: `MemoryStore::recall(customer)` into the support agent's bundle, labelled
  memory (not trusted); after HUMAN_RESOLVED the operator may remember a fact; the agent remembers
  nothing on its own in Phase 3 (ADR-033).
- **Support agent** (`agents.key = support`, role `support`, paused, A0, read tools only plus the
  reply tool): given the thread, the customer 360, order facts and memory, it answers the customer's
  question by **proposing** one `messaging.send_reply` call with the reply text, in the customer's
  script and register. Its prompt inherits the whole `hq-core`: the task is the customer's trusted
  message as the operator's proxy only through the conversation core; untrusted content cannot
  widen anything; no task is invented; a refused request has no safe approximation; clarify is a
  verdict. Bangla first: reply in the language the customer wrote; never transliterate names; taka
  as `৳`; no promises about delivery dates or refunds beyond what a tool returned.

Outbound, top to bottom:

- **Proposal** (`messaging.send_reply`, R3, write, `customerFacing`, `consentRequired` Service on the
  thread's channel, `agentExecutable`): at A0 the runner records it as a proposal and runs nothing
  (`hasEffect()`); the inbox shows it as "the agent suggested this reply". A human sends it or
  discards it. That is P3-G2 shadow, and it needs no provider.
- **Gateway**: policy, consent (step 5b: `Service` on this channel for this customer, not expired,
  no do-not-contact), permission, autonomy, then the outbound policy (`OutboundPolicy`: one reply
  per inbound unless a human is the author; a per-thread hourly cap; quiet hours for anything that
  is not a reply to an inbound within the window; suppression list), then the capability resolver:
  the platform account must hold `MessagingChannel::SEND_SERVICE`. **In this milestone the registry is
  empty, so the resolver says the capability is absent and the tool cannot execute for anyone**, human
  included. That is the inert guarantee, and it is structural, not a flag.
- **Provider adapter** (`OutboundChannelAdapter::send(OutboundMessage): DeliveryReceipt`): the Meta
  Send API in P3-G4, never before; the simulated adapter for tests.
- **Delivery verification** (ADR-031): `message_deliveries` (message, attempt, provider ref, status,
  verified-at); a send is real when Meta's delivery webhook says so, not when the request returned
  200; reconciliation re-reads unverified sends.
- **Audit and memory**: every message, state change, proposal, send and verification is an audit
  row with the conversation's correlation id.

## C. The operator inbox

Route `/inbox`, permissions `hq.inbox.view` and `hq.inbox.manage`, sidebar under Today with a badge
of HUMAN_REQUIRED threads the user may see (`NavBadges`, scoped by `BusinessScope`). The list: state,
channel, business and brand, customer (or "stranger"), last message and its age, assignee. The
thread: the transcript with a provenance badge on every message (customer, agent, human, system),
attachments, the customer 360 in a side column, and the actions: take over (HUMAN_ACTIVE), resolve
(HUMAN_RESOLVED), return to the agent (AI_RESUMED, only when the agent is active), close, assign,
disable the agent for this thread, add an internal note. The composer sends through the gateway
tool and, until a provider exists, shows "Sending is not enabled yet; gate P3-G4" and records the
draft as an internal note if the operator asks. An agent proposal shows as a card with "send as is",
"edit and send", "discard", each an audited decision (`DecisionRecord`).

## D. The five gates

- **P3-G1 Conversation Core**: persistence, normalisation, identity, provenance, state machine,
  handoff, events; the simulated channel drives a hundred scripted threads through the core with no
  model and no provider; every transition audited; a stranger becomes a customer on a linking order;
  the Meta normaliser reproduces the recorded fixtures byte for byte in `InboundMessage` form. CI
  only.
- **P3-G2 Support Agent Shadow**: the `support` agent at A0 on simulated inbound conversations,
  graded by its role suite (in role: answer from thread and facts in the customer's language;
  propose exactly one reply; escalate on the deterministic triggers; out of role: never widen, never
  send, never promise what no tool returned; safety.core inherited). Same gate runner, same posture
  discipline, provider quota checked first.
- **P3-G3 Channel Shadow**: the real Messenger inbound, no autonomous send. Meta's webhook still
  points at n8n; n8n forwards a copy to HQ's webhook (one HTTP node), so no Meta configuration
  changes. HQ ingests, the agent proposes, humans reply through n8n as today. A `shadow_comparisons`
  record pairs each HQ proposal with what n8n actually sent, for at least a week (`docs/22` ladder).
  Requires Meta app in at least development mode, the page subscribed, the flag ON, and the app
  secret in the vault.
- **P3-G4 Outbound Safety**: the Meta send adapter registered; consent, scope, suppression, frequency,
  policy, audit, idempotency and delivery verification each tested against the real provider in a
  test page; the ladder's comparison satisfied. Requires Meta Business Verification and App Review
  (`docs/70` C2, C3).
- **P3-G5 Controlled Cutover**: Meta's webhook pointed at HQ; n8n paused, not deleted; a limited
  share of real conversations; explicit rollback (repoint the webhook, unpause n8n) rehearsed; daily
  reconciliation of sends against deliveries. Only after G5 may the support agent's autonomy widen
  above proposing, and only by the autonomy plane's evidence.

## E. What stream 3 builds now, and what it does not

Builds (all inert): the `InboundMessage`, `OutboundMessage`, `DeliveryReceipt` values and the two
adapter contracts; `SimulatedChannelAdapter`; the Meta normaliser as a pure class with fixtures; the
Meta webhook controller and route behind the OFF flag; `ConversationState`, `HandoffStateMachine`
and the deterministic escalation triggers; migration adding `state`, `ai_disabled`, `language` to
conversations and creating `message_deliveries` and `shadow_comparisons` (empty); `ConversationService::ingest`
and the channel-identity path on `IdentityResolver`; the three conversation events and their
handler; the inbox pages, routes, permissions and nav badge; the `messaging.send_reply` tool
descriptor and a `conversation.read_thread` read tool; the `support` agent row, `support.v1.md` and
`SupportBehaviourSuiteSeeder`; the outbound policy as code with its tests; tests for all of it.

Does not build: any Meta credential, any send path, any platform account, any registry entry, any
n8n change, any autonomy grant, any customer-facing text on any public surface. The support agent
is seeded paused at A0 with no enabling user, exactly like the other two were.

## F. External prerequisites this phase waits on

`docs/70` §C: the Meta developer app (C1), Business Verification (C2, weeks), App Review for the
messaging scopes (C3), the WhatsApp number (C4, later gate), a payment method Meta accepts (C5), the
e-mail provider (C6), the SMS masking (C7, for OTP identity linking), object storage (C8), and
Cloudflare in front of the webhook (C9). J1 (VPS step up) and J4 (a staging HQ) before P3-G3, because
a public webhook with real customers must not be first exercised on production.
