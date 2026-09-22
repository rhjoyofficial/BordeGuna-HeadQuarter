# 87 - The full-speed programme: every remaining phase built in parallel, inert, under one instruction (22 September 2026)

**Status: PROPOSED.** Written on 22 September while Gate 12 ran, to be posted to ChatGPT with the Gate 12
report so that ChatGPT can answer with ONE long, multi-stream instruction instead of one small milestone a
day. Nothing here starts before ChatGPT approves it, and by `docs/86` A ("Do not make unrelated feature
progress until this correction and Gate 12 are complete") nothing here starts before Gate 12 is recorded.
Every fact about the code was read from `main` at `8ea7650` on 22 September, not recalled.

## A. Why this document exists

**The loop that sets today's pace.** Since 12 September the work has moved as one correction, one gate,
one report, one ChatGPT instruction, and then the next correction. Each round costs most of a day. Twelve
gates have been run that way. The gates were right to be strict, but most of the remaining work does not
change agent behaviour at all, and it has been waiting behind gates that measure agent behaviour.

**Joy's direction, verbatim (22 September):** "I am thinking your are working every slow. I needs your to
work faster. ... Proceed, take ownership and do discuss with chatgpt, plan execute, anything you can done
but i want work must go on and i needs this project complete faster."

**What actually decides when each phase goes live.** Three different clocks, and code is only one of them.

| Clock | What it holds back | Can Claude shorten it? |
| --- | --- | --- |
| Engineering | Everything in section B marked "missing" | Yes: parallel streams (section C) |
| Joy's outside items (`docs/82` S1 to S7) | Meta verification and app review (Phase 3 live, Phase 5, Phase 6 publishing), the WhatsApp number, the payment gateway and courier (Phase 4 live) | No. Only starting them this week shortens them |
| Calendar time by design | The 7-day Executive shadow week, the P3-G3 week of paired proposals, P5-G4 and P6-G4 two weeks each | No. Starting them earlier is the only lever |

The plain conclusion: **the outside items, not the code, decide the live date of Phases 3 and 4.** If Joy
starts S1 and S2 this week, Meta's own figure ("up to 14 business days") puts verification near the middle
of October. The code for those phases can be finished and proven inert well before that, if it is built
now and in parallel.

## B. What is left, phase by phase (read from the code on 22 September)

| Phase | Built (inert where it acts) | Missing | Blocked by |
| --- | --- | --- | --- |
| 2 Command center | Executive and Analytics agents, briefing, Ask HQ and scheduling in words (inert), observability, retention, alerts, the A0 shadow machinery (`hq:shadow:open --by`) | Nothing in code | Gate 12, then P3-G2, then the 7-day shadow (calendar), opened only by Joy |
| 3 Customer AI | Meta webhook `GET/POST /api/webhooks/meta` with `X-Hub-Signature-256` check behind flag `channels.meta.inbound` (off); Messenger, Instagram and comment normalisers; conversation core, hand-over state machine, escalation triggers, outbound policy; inbox (take over, reply, AI toggle, verdicts, memory); `messaging.send_reply` with no provider registered; support agent (paused, A0); simulation world; shadow comparisons | A real `MessagingChannel` send adapter for Messenger and Instagram (Graph API), registered nowhere; everything WhatsApp: the Cloud API inbound normaliser, its send adapter, its own reply tool (ADR-026), template messages and the 24-hour window; delivery-receipt ingestion; the n8n copy-forward that P3-G3 needs; a staging HQ (`docs/70` J4) | S1 Meta verification, S2 app and App Review, S3 WhatsApp SIM (Joy); N3 server upgrade for the staging copy; N5 Cloudflare |
| 4 AI orders | Read tools (product, SKU, price, availability, eligibility); `commerce.take_order` R4, approval-required, requester never decides, not agent-executable, `take_order_stores` empty; ERP draft endpoint and tables on ERP master (`0fe39af`, not on ERP production) | ERP side of P4-G0: turning an approved draft into a real ERP sales order through the ERP's own approval, `quote`, read-back by reference, `cancel`; HQ side of P4-G1: `quote_order`, `get_order_status`, `cancel_sales_order` descriptors tested against a simulated ERP connector; the second approver; order status told back to the customer | A store on the ERP's website module (`docs/79` F, ADR-046 point 3, an ERP programme item); the second approver and the recipient question (Joy); S6 gateway and S7 courier for online payment and tracking (Joy) |
| 5 Ads | `AdsPlatform` contract, capability router that refuses two equal ad accounts, the gateway vocabulary, budget ledger, consent purposes; design `docs/84`, ADR-050 proposed | The Meta Marketing API adapter for reads (insights, campaigns), `facts_ad_spend`, the hourly sync, the contribution report joining ERP delivered orders and cost prices; later the write tools registered inert (P5-G2) | ADR-050 acceptance (ChatGPT); S1, the system user and token (Joy); O-5 ad account, brand and currency per account; O-8 ERP cost prices |
| 6 Content and research | `PublishingPlatform`, `ResearchSource`, `MediaGenerator` contracts; media assets; the evidence bundle and `briefing.numbers.v1`; design `docs/85`, ADR-051 proposed | The research engine (search and fetch adapters for Brave or Tavily, research artifacts with a cited source span per claim, task-bounded fetching); content drafts from ERP facts with a claims check against the brand's claims policy; the Bangla render service (headless browser, because GraphicsMagick breaks Bangla shaping); the Page and Instagram publishing adapter, registered nowhere | ADR-051 acceptance (ChatGPT); the claims policy per product line, the source list, the post approver, the search provider (Joy); S1 and the Page token for publishing |
| 7 Voice | `MediaGenerator` voice operation and `TelephonyProvider` contract only | Speech to text for Bangla voice notes arriving in a conversation (the first voice release is WhatsApp voice notes, not calls); a text-to-speech adapter interface; a voice suite | Google STT and ElevenLabs or Azure keys (an hour each, `docs/82` section 5); S3 for WhatsApp voice notes |
| 8 Avatar | `synthetic_identity` gated by its own consent class | Design only: whose face and voice, written consent, the publishing path | Joy's decision on the face and voice, with written consent |
| 9-10 Measured autonomy | Versioned evidence policies, the evidence producer, the resolver ceiling, the evidence view (`docs/81` C.4) | The numbers, by design | Owner decisions after evidence exists |

Two corrections to the suggested plan, made because the code and the settled design say otherwise:

1. **HQ never books parcels and never moves money** (`docs/79` C.8 and C.10, `docs/15`, ADR-025). A payment
   gateway and a courier account belong to the system that owns the order, which is the ERP. So Phase 4's
   payment link and courier booking are ERP work (the ERP programme, with the peer ERP session), and HQ
   only reads their status. Stream R below builds HQ's side of that and the ERP's order endpoints, not an
   HQ payment or courier adapter.
2. **A WhatsApp reply is its own tool, not the Messenger tool with a different transport** (ADR-026, the
   docblock of `SendReplyTool`). Stream Q builds it that way.

## C. The proposed programme: six streams, all inert, all at once

Every stream follows the rules that have held since Phase 3 began: nothing switched on, no grant, no flag
row, no provider registered in `PlatformRegistry`, no store admitted, no credential stored, no live call to
Meta or any provider, migrations additive and rehearsed on Postgres, stamps unchanged unless a prompt file
changes (none should), tests watched failing first, and mutations run against every guard. Worktrees and
test databases are assigned at launch; any new worktree gets its own `vendor` and `node_modules` install,
never junctions (the 21 September junction trap).

### Stream Q. Phase 3 live channel adapters, registered off

- **Branch** `p3/channel-adapters`. **Worktree** assigned at launch. **Test database** `hq_test_q`.
- **Deliverables.** (1) `MetaMessengerChannel` and `MetaInstagramChannel` implementing `MessagingChannel`
  against the Graph API send endpoint, with signed requests, idempotency, throttling and error mapping,
  tested only against recorded fixtures; (2) WhatsApp Cloud API: an inbound normaliser from Meta's
  documented payloads, a `WhatsAppCloudChannel` send adapter, and a separate `messaging.send_whatsapp_reply`
  tool with its own descriptor, consent check, the 24-hour window and template messages outside it;
  (3) delivery receipts (sent, delivered, read, failed) into `message_deliveries`; (4) the n8n copy-forward
  receiver P3-G3 needs, behind the same off flag; (5) a staging HQ stack definition (`deploy/staging`, its
  own database and domain), not started.
- **Inert guarantee.** No adapter is registered in `PlatformRegistry`, so the capability resolver finds no
  provider and every send fails for everyone, human included, exactly as today; the webhook routes answer
  404 while their flags are off; the new tool is held by no agent.
- **Proof.** Fixture tests for every payload type; a test that the registry ships empty; a test that the
  WhatsApp tool refuses outside the window without a template; mutations on the signature check, the
  window and the consent check.
- **Gate.** Brings P3-G3 and P3-G4 to "ready, waiting on Meta". P3-G3 itself runs only when Joy's Meta
  app exists (S2).
- **Needs from Joy.** Nothing to build. S1, S2 and S3 to go live.

### Stream R. Phase 4 order completion, inert on both sides

- **Branch** HQ `p4/order-capability-inert`, ERP `feature/hq-sales-order-confirm` (from ERP master).
  **Worktree** assigned at launch. **Test database** `hq_test_r`; the ERP side keeps its scratch-MySQL rule.
- **Deliverables.** ERP (P4-G0): an approved draft becomes a real sales order through the ERP's own
  approval, never directly; `POST .../sales-orders/quote`; `GET .../sales-orders/{reference}`; `POST
  .../sales-orders/{reference}/cancel` with the ERP's refusal when deliveries exist; new abilities issued
  only by name. HQ (P4-G1): `commerce.quote_order`, `commerce.get_order_status` and
  `commerce.cancel_sales_order` with the `docs/79` D descriptors, the whole ADR-031 sequence tested against
  a simulated ERP connector, including `uncertain` and reconcile; the second-approver rule in the approval
  path, so that the person who asked can never be the person who approves, owner included.
- **Inert guarantee.** `take_order_stores` stays empty; no token holds the new abilities; no agent holds any
  of the tools.
- **Proof.** Replay and 409 tests, quote equality, the approval rule under mutation, a test that the empty
  store list refuses every call.
- **Gate.** P4-G0 and P4-G1. P4-G2 (a person takes one real order) waits for a store on the ERP website
  module and the second approver.
- **Needs from Joy.** The name of the second approver, and whether a recipient may differ from the buyer
  (`docs/79` H.2, H.7). Building can start without them; the approval rule is written so that a missing
  second approver means no order can be approved.

### Stream S. Phase 5 ads read side (after ADR-050 is accepted)

- **Branch** `p5/ads-read-side`. **Test database** `hq_test_s`.
- **Deliverables.** `MetaAdsPlatform` implementing the read half of `AdsPlatform` (`read_insights`,
  `read_campaigns`), R2 read tools, `facts_ad_spend`, the hourly sync job (defined, not scheduled), the
  contribution report joining ERP delivered orders and cost prices, and the budget guard that reads (never
  writes) each ad account's spending limit.
- **Inert guarantee.** No ads credential exists, so the adapter cannot be reached; the job is not in the
  schedule; the tools are held by no agent; no write tool is registered.
- **Proof.** Fixture tests on Meta's documented insight payloads, currency handling for both BDT and USD
  accounts, the router's refusal of two equal accounts, reconciliation arithmetic.
- **Gate.** Ready for P5-G0 and P5-G1, which need Joy's system user token.
- **Needs from Joy.** O-5 (which ad account is which brand, and its currency), O-8 (ERP cost prices).

### Stream T. Phase 6 research engine and content drafting (after ADR-051 is accepted)

- **Branch** `p6/research-and-drafts`. **Test database** `hq_test_t`.
- **Deliverables.** `ResearchSource` adapters for Brave and Tavily behind the contract; research tasks with
  a bounded fetch list; research artifacts where every claim carries its source span and hash; the content
  draft pipeline that takes product facts only from the ERP or the shop and refuses a figure that is not in
  the evidence (the `briefing.numbers.v1` rule turned outward); the claims check against a brand's claims
  policy; the Bangla render service as a headless-browser container definition; the `PublishingPlatform`
  adapter for a Facebook Page and an Instagram professional account, registered nowhere.
- **Inert guarantee.** No search key, no Page token, the publishing adapter unregistered, the research and
  content tools held by no agent, the flag off.
- **Proof.** The research suite on recorded fixtures (injection inside a fetched page, an out-of-task fetch,
  a claim without a span), the content suite (a health claim the policy forbids, a price that differs from
  the ERP), the Bangla render test that fails on the two words GraphicsMagick broke.
- **Gate.** P6-G1 (research engine inert, measured on fixtures) and the build half of P6-G2.
- **Needs from Joy.** The claims policy for one brand, its source list, its post approver, the search
  provider choice.

### Stream U. Phase 7 voice, first release, and Phase 8 design

- **Branch** `p7/voice-notes`. **Test database** `hq_test_u`.
- **Deliverables.** A speech-to-text adapter interface and a Google Speech-to-Text implementation for a
  Bangla voice note arriving in a conversation, the transcript stored as untrusted content with its
  provenance, the conversation core treating it as a customer message; a text-to-speech adapter interface
  (ElevenLabs, Azure `bn-BD` as fallback), registered nowhere; a Phase 7 and 8 design document (new gates
  P7-G0 to P7-G3 and P8-G0 to P8-G2, proposed names), including the avatar consent record.
- **Inert guarantee.** No key, nothing registered, no audio is sent anywhere.
- **Proof.** Fixture tests on recorded transcripts, a test that a transcript can never be trusted content.
- **Needs from Joy.** Nothing to build. The face and voice decision for Phase 8, later.

### Stream V. The operator screens for all of the above, Bangla and English

- **Branch** `ui/phase-3-to-7-screens`. **Test database** `hq_test_v`.
- **Deliverables.** Inbox support for WhatsApp threads and delivery states; the order draft, approval and
  status screens; the ads read dashboard; the research and draft review screens with the claims check shown
  beside each draft; the voice transcript view. Every label in both languages, English digits (Joy's rule),
  screens behind the existing permissions.
- **Inert guarantee.** Screens only; every action goes through the same gateway tools, which stay refused.
- **Proof.** Screen tests plus `tsc --noEmit` and a real browser render check after each merge.
- **Order.** Starts one day behind the others and follows each stream's merged contracts.

## D. The gate plan: measure behaviour only when behaviour changes

**Two kinds of gate.** A behaviour gate (Gate 12 is one) calls the model on the production agent and is
needed only when an agent's prompt, role policy, tool list or pre-model runtime path changes. A capability
gate (P3-G3, P4-G0, P4-G1, P5-G1, P6-G1) proves a tool or adapter with tests and records and calls no model.
Streams Q to V give no agent a new tool, so they need **no behaviour gate while they are built**, and their
capability gates can pass in parallel.

**Batching.** `hq:agent:evaluate` already measures every active suite of an agent's role in one run. So the
next behaviour gate (Gate 13) is one run per agent that covers everything that changed agent behaviour since
Gate 12 at once: any stream-P follow-up ChatGPT orders (section G, question 5), the support agent's
WhatsApp scenarios, and the P4-G3 `take_order` proposal suite. One gate, several suites, each reported on
its own line.

**Activation order, unchanged.** Gate 12 passes, then P3-G2 passes, then Joy opens the Executive's A0 shadow
week by name (`--by`), seven days, no automatic promotion. **The shadow week runs while streams Q to V are
built**, so calendar time and engineering time overlap instead of queueing.

## E. Merging at speed without losing the discipline

1. Each stream on its own branch, worktree and test database; each records its own section of this
   document (C.1 onward).
2. I review every diff (the files that touch a gate read line by line), merge with `--no-ff` into `main`,
   and run the full local suite on the combined tree: Pest folder by folder, Pint, strict TypeScript, Vite;
   the deploy-safety suite on Linux only. Reported as local verification; GitHub Actions validation stays
   deferred until the 1 October reset.
3. One combined release per batch, not per stream (about every two or three days): I run the production
   preflight (`deploy.sh --verify-only`), Joy runs the release line because this session's classifier
   refuses `deploy.sh`, and I verify the release record, health and scheduler.
4. ERP-side changes follow the ERP's rules: scratch-MySQL rehearsal, backup first, and coordination with
   the peer ERP session, which owns the ERP release branches.

## F. What Joy must do, and when, and why

**This week, all at once (they are weeks of waiting on others):**

| Item | Why it matters |
| --- | --- |
| S1 Meta Business Verification | Without it only admins can message the app: no real customer conversation, no ads access, no publishing |
| S2 Meta developer app with Messenger, Instagram Messaging and Webhooks, then App Review | The only door to real customer messages; P3-G3 cannot run without the app |
| S3 a new SIM that has never been on WhatsApp | WhatsApp Cloud API needs its own number; it is the channel Bangladeshi customers use most |
| S6 payment gateway application (SSLCommerz or aamarPay, or bKash and Nagad) | Online payment for an AI-taken order; COD works without it |
| S7 courier API account (the courier the shops already use) | Telling the customer where the order is |
| S5 SMS masking (BTRC approval) | OTP and order updates by SMS |
| S4 dual-currency card | Paying Meta and OpenAI in the business name |

**Before customer AI goes live:** N3 server upgrade (a staging copy of HQ must exist before a public
webhook meets real customers), N5 Cloudflare in front of the webhook.

**Decisions, any time this week (building does not wait for them, going live does):**

1. The second person who may approve an order (none named means no order can ever be approved).
2. Whether an order may be delivered to someone other than the buyer.
3. Whether COD is the only method the assistant offers in conversation.
4. Which ad account belongs to which brand, its currency, and the monthly ads budget per business.
5. The claims policy for one brand's product lines (what may and may not be said, for example about mustard
   oil and health), its source list, and who approves its posts.
6. The search provider (Brave or Tavily).
7. Later: whose face and voice the avatar uses, with written consent; the autonomy numbers.

## G. The questions for ChatGPT, to answer in one instruction

1. Do you approve streams Q to V (section C) to run in parallel from the moment Gate 12 and P3-G2 are
   recorded, all inert, under the merge discipline of section E, with one report when all six are merged
   rather than one per stream?
2. Do you accept ADR-050 (paid media moves money only by a person's decision) and ADR-051 (research is
   evidence, never truth; content states product facts only from systems of record) as written, or with
   which changes? Streams S and T start only after your answer.
3. Do you confirm that `commerce.take_order` stays R4 (external side effect, recoverable, approval
   required), not R3 (internal write, no approval), and that payment links and courier booking are ERP
   work that HQ only reads, as `docs/79` C.8 and C.10 already say?
4. Do you agree that behaviour gates are needed only when agent behaviour changes, and that the next one
   (Gate 13) batches every such change into one run per agent, each suite reported on its own line?
5. From stream P, five open points. (a) Should every no-task, security and capability boundary move before
   the model, like the role boundary now is? (b) The Analytics agent carries the Executive's role policy;
   agree? (c) "Email me the summary" is refused as an outbound message; agree? (d) A mixed request is served
   with a runtime note rather than refused whole; agree? (e) A Bangla bare offer still reaches the model,
   because the bare-offer check is English only; fix it now, or in Gate 13's batch?
6. May the Executive's shadow week open (by Joy, `--by`) as soon as P3-G2 passes, while streams Q to V are
   being built?

## H. Rough timeline (estimates, not promises)

| Phase | Engineering done and proven inert | Earliest live, if Joy starts the outside items this week |
| --- | --- | --- |
| 2 Command center | Done | About 30 September: Gate 12 and P3-G2 today, then the 7-day shadow |
| 3 Customer AI | About 5 to 7 days (stream Q) | Mid to late October: Meta verification (up to 14 business days), App Review, then P3-G3's week |
| 4 AI orders | About 5 to 7 days (stream R, both repositories) | After a store is on the ERP website module and the second approver is named; online payment after S6 |
| 5 Ads (reads) | About 4 to 5 days after ADR-050 (stream S) | After S1 and the system user; P5-G1 needs 3 days of reconciliation |
| 6 Content and research | About 6 to 8 days after ADR-051 (stream T) | Drafting after the claims policy; publishing after S1 and the Page token |
| 7 Voice notes | About 3 to 4 days (stream U) | With WhatsApp (S3) |
| 8 Avatar | Design only now | After Joy's consent decision |
| 9-10 Autonomy | Machinery done | Earned from evidence, never on time |

All six streams together: roughly one to two weeks of engineering if they run in parallel, against the
several weeks they would take in the one-gate-a-day loop. The live dates are set by the outside items and
the calendar clocks of section A, and those only move if they start now.
