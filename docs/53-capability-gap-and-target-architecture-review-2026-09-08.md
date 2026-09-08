# 53 - Capability gap and target architecture review (8 September 2026)

**Status: review only. No code was written for anything in this document, no roadmap phase was
replaced, and no existing safety control was weakened or relaxed.**

This answers the question "does what we have built actually lead where the business wants to go",
asked against the full long-term target: HQ as the AI operating system for the group, with AI as the
primary operational worker and humans auditing, supervising exceptions, holding permanent human-only
boundaries, and intervening.

The short answer is that **the control plane is the right shape and does not need rebuilding**, and
that **six shared subsystems are missing which every later phase would otherwise invent for itself,
each in the shape of whichever phase built it first.** Those six are the recommendation.

---

## Method, and why some of this contradicts older documents

Everything below was checked against the repository and the running production stack on 8 September
2026, not against the architecture documents. That distinction has cost this project twice already:
`docs/45` recorded an ERP hostname taken from a config default rather than the live environment, and
`docs/48`, `docs/49` and the dossier all stated Telegram was unconfigured when production had both
alerting variables set. Both were written from what the design said rather than what the system did.

Evidence used: the 39 tables actually in the schema, the tool descriptors actually registered, the
enums actually defined, the production containers actually running, and the CI suite actually green.
Where this document contradicts `docs/00` to `docs/48`, the live state wins and the contradiction is
named.

One such contradiction is worth stating immediately. **`docs/48`'s M1 dependency list promises a
`policies`, `tools`, and `evaluations` migration. Only the first two exist.** There is no
`evaluations` table anywhere in the schema. This matters more than a missing table normally would,
for a reason developed in section O.

---

## Master capability map

Sixteen capability areas from the target vision, against what is true today. "Designed" means an
architecture document specifies it; "built" means code exists and runs.

| # | Capability area | Designed | Built | Verdict |
| --- | --- | --- | --- | --- |
| 1 | Omnichannel conversations | `docs/14`, `docs/18` | identity only | **Partly. Identity is ready, conversation is absent.** |
| 2 | Customer operations | `docs/14`, `docs/09` | identity, merges, order facts | **Partly. No conversation, memory, leads or cases.** |
| 3 | Commerce operations | `docs/15` | adapters, facts, reconciliation | **Mostly. Reads are live; writes are not built.** |
| 4 | Telephony and voice | `docs/19` | nothing | **Designed only.** |
| 5 | Marketing | `docs/16`, `docs/23` | nothing | **Designed only.** |
| 6 | Content factory | `docs/17` | nothing | **Designed only.** |
| 7 | Synthetic identity | `docs/20` | nothing | **Designed only. Consent records are the gap.** |
| 8 | Research and intelligence | scattered | nothing | ⛔ **Not designed anywhere. Genuine gap.** |
| 9 | AI workforce | `docs/08`, `docs/48` | gateway, policy, approval, router | **Foundation complete. No agent exists.** |
| 10 | Continuous improvement without fine-tuning | ADR-013, `docs/29` | `decision_records` only | **Partly. No evaluation store.** |
| 11 | Autonomy control | `docs/48` Q.1 | one enum, one column | ⛔ **Structurally insufficient. See K.** |
| 12 | Platform capability abstraction | `docs/15`, `docs/16`, `docs/18` | commerce only | ⛔ **Pattern proven, never generalised. See P.** |
| 13 | Media and object storage | `docs/05` defers it | nothing | ⛔ **Not designed. Genuine gap.** |
| 14 | Consent and compliance | scattered across 8 docs | one jsonb column | ⛔ **Structurally insufficient. See N.** |
| 15 | Evaluation and learning | `docs/29` | nothing | ⛔ **Nothing built. Blocks the autonomy ladder.** |
| 16 | Existing architecture protection | ADR-001 to ADR-019 | enforced by tests | ✅ **Intact. See section 8 of the summary.** |

---

## A. What the vision already has architecturally

More than the phase numbering suggests, because M1 to M4 built the control plane rather than a
feature.

**The Tool Gateway is agent-agnostic.** `ToolGateway::execute(ToolRequest): ToolOutcome` does not
know or care whether its caller is the scheduler, a console command, a browser request or, later, an
agent. ADR-016 made one door mandatory for every caller and M2 proved it by routing the six live
scheduled jobs through it. A customer support agent in Phase 3 and a campaign agent in Phase 5
inherit schema validation, business scoping, permission intersection, policy, approval, idempotency,
credential pre-flight and recording without any of it being rebuilt. **This is the single most
valuable thing already in place**, and it is why the vision does not require an architectural reset.

**Risk tiering already anticipates customer contact.** `RiskTier::R5` is defined as "material or
irreversible: money, **a customer-visible message**, a publication, a price, a deletion". The moment
an agent sends a Messenger reply it is an R5 action by the definition already written, and Joy's
V.1 decision means every R4 and R5 action requires approval with no value threshold. The safe
default for customer AI is therefore already the default.

**`ActionClass::HumanOnly` already exists and cannot be lifted by approval.** This is the mechanism
the vision asks for when it says permanent human-only classes must remain possible. It is checked
before everything else. Section R qualifies this: the mechanism exists, the *distinction* it needs
does not.

**Unified customer identity is built and already omnichannel-shaped.** `customer_identities.channel`
enumerates `phone|email|shop_user|shop_order|erp_customer|messenger_psid|instagram_igsid|whatsapp`
today, with a per-identity `confidence`, a `verified_at`, and a merge-candidate workflow. A Messenger
PSID arriving in Phase 3 has a home that was designed for it in Phase 1. This is genuinely ahead of
where the roadmap claims to be.

**The event backbone is production-grade.** `events` carries a UUID, a unique `dedupe_key`, a
direction, an aggregate reference, attempts, a next-attempt time, a dead-letter status and a schema
version. Inbound omnichannel messages are exactly this shape. The table needs no change to carry
them.

**Cost, provenance and reproducibility are solved generally, not for one case.** `ai_runs` with the
M4 router, `tool_calls` with `policy_id` and `policy_version`, `connector_calls`, immutable
`audit_logs` and append-only `decision_records` mean any future agent's behaviour is reconstructable.
The vision's "improvement without fine-tuning" depends entirely on that record existing, and it does.

**The credential boundary holds under a new provider class.** M4 added a model provider and the M2
rule survived unchanged: `CredentialVault` is the only decryption point, enforced by a
filesystem-scanning test. Adding Meta, WhatsApp, telephony and ads credentials is more rows, not a
new mechanism.

---

## B. Designed but not implemented

These need building, not deciding. The architecture exists and remains sound.

Conversation engine and handoff state machine (`docs/14`, with the state machine already specified).
Customer memory in its three forms (`docs/09`). Skills (`docs/10`). Marketing and attribution
(`docs/16`, `docs/23`). Content factory (`docs/17`). Social publishing with a per-platform capability
matrix already tabulated (`docs/18`). Voice (`docs/19`). Avatar and voice cloning (`docs/20`).
Workflow automation (`docs/22`). AI evaluation at a high level (`docs/29`).

The per-platform matrix in `docs/18` deserves particular note: it already records, row by row, that
Instagram supports caption-only publishing with media, that Facebook supports
`scheduled_publish_time` and Instagram does not, and which scopes need App Review. That is the
content of a capability registry sitting in a Markdown table instead of in the system.

---

## C. Missing and must be added

Five things are absent from both the code and the design.

1. **A Research Engine.** Web research, source provenance, freshness, credibility metadata, and the
   separation of fact from inference appear only as passing mentions inside `docs/17` and `docs/19`.
   Nothing designs them. Section L.
2. **A Media Asset subsystem.** `docs/05` records "Object storage: none in Phase 0 (local disk); an
   S3-compatible store is a later phase when media volume needs it" and stops there. Section M.
3. **A Consent and Compliance subsystem.** Consent appears in eight documents as an attribute of
   something else and exists in code as one jsonb column. Section N.
4. **An Evaluation subsystem.** Section O.
5. **An Autonomy Control Plane.** Section K.

Two further gaps are structural rather than missing components:

6. **A conversation and message store**, with threading, channel provenance, attachments, and the
   human/AI handover state. Designed in `docs/14`, absent from the schema.
7. **A lead and case model.** Lead capture, scoring, lifecycle, segmentation, support cases and
   outcome tracking have no tables and no design beyond the customer profile read model.

---

## D. Roadmap phases needing expansion

The existing ten-phase roadmap in `docs/30` is sound in ordering and wrong in two assumptions: that
Phase 3 can begin directly after Phase 2, and that autonomy widening in Phases 9 and 10 can rely on
"evaluation data from Phases 2-6" that nothing is currently recording.

| Phase | Expansion needed |
| --- | --- |
| **2** | Unchanged. M4 finishes as specified; M5 to M7 unchanged. |
| **new 2.5** | The shared foundation. Section H. |
| **3 Customer AI** | Gains consent enforcement on every outbound message, capability negotiation per channel, media handling for attachments, and conversation-level autonomy resolution. Loses nothing. |
| **4 AI orders** | Gains monetary-value autonomy bands and an explicit reversibility declaration per tool. |
| **5 Marketing** | Gains suppression, frequency capping and consent-derived audience eligibility as **enforced** rules rather than campaign settings. |
| **6 Content** | Gains the media subsystem as a hard dependency, plus asset rights and licensing metadata. |
| **7 Voice** | Gains recording consent as a precondition, and the telephony abstraction split into local Bangladesh providers and international CPaaS as separate implementations of one interface. |
| **8 Avatar** | Gains synthetic identity consent records with scope, expiry and revocation as a hard precondition. |
| **9 and 10** | Rewritten to consume evaluation evidence rather than assume it. Autonomy widening becomes a function of measured agreement, which requires section O to exist from 2.5 onward. |

---

## E. What must become a shared platform service

The test applied: would two or more later phases each need this, and would the first one to build it
bend it to its own shape? Everything below fails that test if left inside a phase.

| Service | Needed by | What goes wrong if a phase owns it |
| --- | --- | --- |
| **Autonomy Control Plane** | every agent, every phase | Phase 3 would encode autonomy as a conversation setting, and Phase 5 could not express a campaign budget bound in it. |
| **Consent and Compliance** | 3, 5, 6, 7, 8 | Phase 3 would build messaging consent; Phase 7 would then build recording consent separately; nothing could answer "may we contact this person at all". |
| **Platform Capability Registry** | 3, 5, 6, 7 | Phase 3 would build it Messenger-shaped and Ads would not fit. |
| **Media Asset service** | 3, 6, 7, 8, invoices | Phase 6 would build it for generated images and customer attachments in Phase 3 would land somewhere else. |
| **Evaluation** | every agent, and both autonomy phases | Built per agent, cross-agent regression is invisible and autonomy cannot be earned on comparable evidence. |
| **Conversation and Memory core** | 3, 4, 7, and every agent that needs context | Phase 3 would make it Messenger-shaped and voice would not fit into it. |
| **Research Engine** | 6 primarily, 5 and 2 secondarily | Built inside the content factory, the executive agent cannot cite a source. |

---

## F. External services and accounts to prepare in advance

Ordered by lead time, because several have clocks that start when someone files a form.

**Longest lead, start earliest.** Meta App Review for messaging and content scopes; the roadmap
already says the clock starts in Phase 1 and it has not started. Meta Business verification. A
WhatsApp Business Account with a Meta-approved message template library and a verified display name.
A Bangladesh SMS masking or sender-identity registration, which is a regulated approval and not a
signup. A telephony provider with Bangladesh numbering, which is the one item `docs/30` already
flags as "provider lead time".

**Ordinary signups, but needed before the phase.** Per-brand Facebook Page tokens; `docs/18` records
that only Bionic's exists, in n8n. Instagram professional accounts linked to each Page, with
`docs/18` recording it is **unknown** which brands have them. A Meta ad account and an ads-scoped
system user token. Google Ads with a product feed. TikTok Ads. LinkedIn API access. An
S3-compatible object store. A voice and avatar provider for Phase 8.

**Already held.** The Anthropic provider account for M4, which still needs its key installed as an
`integration_credentials` row before the M4 safety gate can run.

---

## G. What platform approval can block

This is the most likely source of a phase stalling on something no amount of engineering fixes.

Meta messaging scopes gate Phase 3 entirely; without approval the app stays in Dev Mode and can
message only Joy, which is useful for a shadow-mode rehearsal and useless for customers. Instagram
messaging, comment management and private replies are separately scoped from Facebook and can be
approved independently. WhatsApp adds constraints no other channel has: outside a 24-hour customer
service window only pre-approved templates may be sent, templates are approved individually, and
opt-in must be demonstrable. Bangladesh SMS sender identity is a regulatory registration. Call
recording legality is jurisdictional and must be settled before Phase 7 rather than during it. Ads
platforms enforce their own review on creative and on automated account access.

The architectural consequence is section P: **the system must be able to run with a capability
absent, and say so**, because for months at a time some capabilities will be pending approval. A
design that assumes approval has been granted produces an agent that promises a customer something
the platform will not let it do.

---

## H. The recommended new Phase 2.5

**Name: shared foundation. Position: after Phase 2 completes, before Phase 3 begins. Estimated size:
2 to 3 focused weeks.**

The argument for its existence is section E. The argument for its *position* is narrower and worth
stating plainly: Phase 3 is the first phase in which HQ speaks to a human being who is not Joy. Every
control that makes that safe is currently missing, and each one is cheaper to build before there is a
conversation table to retrofit it into.

Contents, in dependency order:

1. **Platform Capability Registry**, because it is small, has no dependencies, and every adapter
   afterwards is written against it.
2. **Consent and Compliance ledger**, because Phase 3's first outbound message must consult it.
3. **Autonomy Control Plane**, because it decides whether that message is sent, proposed or refused.
4. **Evaluation subsystem**, because autonomy above A1 must be earned against it and Phases 9 and 10
   currently assume data nothing is collecting.
5. **Media Asset service**, because a customer attachment arrives in Phase 3 and has nowhere to go.
6. **Conversation and Memory core**, last, because it consumes all five.

The Research Engine is explicitly **not** in 2.5. It has one real consumer (content) and no safety
role, so it belongs with Phase 6.

---

## I. Does the data model support Customer 360, omnichannel identity, memory, leads and long conversations?

**Identity: yes, and better than expected.** Covered in section A.

**Customer 360: partly.** `customers` carries `orders_count`, `lifetime_value`, `first_seen_at`,
`last_seen_at`, and `facts_orders` carries order history reconciled against the shops with an
integrity check. What a 360 view cannot show today is any interaction that was not an order: no
conversations, no cases, no campaign touches, no calls.

**Memory: no.** pgvector is installed and the migration deliberately degrades to a warning if the
extension is unavailable, so the capability is present and unused. There is no embedding column, no
memory table, and no distinction between structured, episodic and semantic memory. `agents` has a
`memory_scope` column pointing at something that does not exist.

**Leads: no.** No table, no scoring, no lifecycle, no segmentation.

**Long-running conversations: no.** This is the largest single data-model gap, and the one with the
most downstream consequences, because conversation is the substrate for support, sales, voice and
handover.

One caution for whoever builds it. `customers.primary_phone_canonical` carries the comment "never
unique (one household, one number)". A conversation model keyed on a phone number will merge two
family members into one thread. The identity layer already knows this; the conversation layer must
inherit the knowledge rather than rediscover it.

---

## J. Does the Gateway, Policy and Approval model scale to an autonomous AI workforce?

**Structurally yes. The descriptor's vocabulary is too small.**

What already scales: one mandatory door, deterministic first-match policy with versioning, permission
intersection, an approval that binds a canonical payload hash and a tool version and is single use,
idempotency, and a credential boundary that survived a new provider class in M4. None of that changes
when the caller becomes an agent instead of the scheduler. The approval flow was proven on production
at 9 approvals, 13 gateway requests and exactly 1 execution.

What does not scale is `ToolDescriptor`, which today declares key, version, risk tier, permission,
audit action, scope, read/write, `agentExecutable`, supported businesses and stores, policy class,
scope source, credentials, idempotency, timeout, retry, cost hint, redaction and output schema. For
an AI workforce that talks to customers it needs, additionally:

- **`channel`**, because sending on WhatsApp and sending on SMS are different actions with different
  consent, capability and cost rules, and today they would be one tool or two unrelated ones.
- **`monetaryImpact`**, a value band. `CostHint` describes what the call costs HQ, not what it moves
  in the business. Autonomy by monetary value cannot be expressed without it.
- **`consentRequired`**, naming which consent class must be valid. Otherwise consent checking lives
  in each tool's body, where it will eventually be forgotten in one of them.
- **`customerFacing`**, because a customer-visible action is reputationally irreversible even when it
  is technically reversible, and that is a different question from `readWrite`.
- **`reversibility`** and, where one exists, the compensating action. R4 is defined as "correctable
  without loss" but nothing names what the correction is.
- **`dataClass`**, so PII-bearing calls can be routed and retained differently.
  `RoutingContext::$sensitivity` already exists as a null placeholder described as "reserved for
  Phase 3", which is exactly this.

These are additive fields on a readonly class with defaults. None of them weakens an existing
control, and each one makes a rule expressible that today would have to be written inside a tool.

---

## K. Is an explicit Autonomy Control Plane required?

**Yes, and this is the most important recommendation in this document.**

Today autonomy is `AutonomyLevel` (A0 to A4), one column `agents.max_autonomy_level` defaulting to
`a0`, and `ToolDescriptor::$agentExecutable` as a boolean. That is enough for two shadow-mode agents
and it cannot express the target at all.

The target requires autonomy to vary across business, brand, channel, agent, tool, action, monetary
value, customer segment, environment, schedule, confidence and compliance state. A single column
cannot say "this agent may answer order-status questions on Messenger for returning customers during
business hours, but never on WhatsApp, never about refunds, and never above five thousand taka".

### Architecture

**A grant lattice resolved to a single effective level, combining by MINIMUM, fail-closed.**

```
  request (agent, tool, business, brand, channel, customer segment,
           value, environment, time, evaluation confidence)
        |
        v
  +----------------------------+
  | AutonomyResolver           |
  |  collect every grant whose |
  |  scope matches the request |
  +----------------------------+
        |
        v
  effective = MIN(all matching grants, agent cap, tool cap, channel cap,
                  compliance ceiling, evaluation-derived ceiling)
        |
        +--> HUMAN_ONLY declared anywhere  -> refuse, and no approval can lift it
        |
        v
  A0 shadow | A1 propose | A2 per-action approval | A3 bounded | A4 review-after | A5 orchestration
        |
        v
  handed to the Tool Gateway as an INPUT to the existing policy step,
  never as a replacement for it
```

Four design rules matter more than the table shape:

**Minimum wins, and it is not first-match.** This is the reason it must be a separate resolver rather
than more rules inside `PolicyEngine`. Policy is deliberately *first match wins, no merging*, which
is correct for "which rule governs this call" and wrong for "how much freedom does this call have",
where every applicable constraint must bind simultaneously. Bolting the second semantics onto the
first would corrupt a working engine. The two must sit side by side.

**Absence means A0, not permission.** The existing `tools` table narrows and its absence means
enabled, which is right for a capability the deployed code already contains. Autonomy is the opposite
case: an unlisted combination has never been evaluated, so it gets shadow. Note that these two
defaults point in opposite directions deliberately, and that is worth a comment in the code, because
a maintainer who knows the `tools` convention will assume this one matches.

**A5 exists in the vision and must not be implemented yet.** Add the case so the ladder is complete
and the enum stops being edited later; leave it unreachable.

**Autonomy never bypasses anything.** The resolver produces an input to the gateway. It cannot skip
policy, permission, approval, the credential boundary, audit or idempotency. An A4 grant means fewer
human interruptions, never a different code path. Whether that holds should be enforced the way M4
enforced the model boundary: a test asserting the autonomy resolver has no route to execution.

Storage: an `autonomy_grants` table, versioned and superseded the way `policies` already are, so a
past decision remains reproducible; plus `HUMAN_ONLY` as a first-class declaration rather than an
inference. Section R explains why the inference is the current weak point.

---

## L. Is an explicit Research Engine required?

**Yes, but it belongs with Phase 6, not in the shared foundation.**

It has one primary consumer, no safety-control role, and can be deferred without blocking customer
AI. What it must not do is arrive as an undesigned appendage of the content factory, because it
introduces a threat the rest of the system does not have.

```
  query -> source discovery -> fetch -> extract -> claims -> artifact -> content brief
                                 |         |          |
                                 |         |          +-- every claim carries: source, retrieved_at,
                                 |         |              credibility, and FACT or INFERENCE
                                 |         +-- untrusted content, quarantined (see Q)
                                 +-- provenance: url, publisher, first_seen, fetched_at
```

The engine stores research artifacts, not opinions: a claim, its source, when it was retrieved, how
credible the source is judged and by what rule, and an explicit fact-versus-inference marker so that
a caption never presents a model's guess as a retrieved fact. Freshness is a property of the artifact
and stale artifacts expire rather than being silently reused.

The critical constraint is that fetched web content is **untrusted input**, addressed in section Q.

---

## M. Is an explicit Media Asset subsystem required?

**Yes, and its interface should exist well before its storage backend does.**

Nine content types need it: generated images, generated video, audio, voice recordings, avatars,
documents, invoices, customer attachments and research artifacts. They arrive across Phases 3, 6, 7
and 8, and the first of them arrives as a customer attachment in Phase 3.

```
  media_assets (Postgres)          object store (driver)
  ---------------------            --------------------
  id, business, brand              local disk now
  kind, mime, bytes, checksum  ->  S3-compatible later
  storage_driver, storage_key      (a driver swap, not a migration)
  source: uploaded | generated
  generator + model + prompt_ref   (provenance for generated media)
  rights: licence, attribution,
          expires_at
  consent_id (nullable)            -> synthetic identity consent, section N
  retention_until
  created_by, correlation_id
```

Two decisions to take now rather than later. **Metadata stays in Postgres and bytes never do**, which
is the rule `docs/05` already implies and which nothing currently enforces because nothing stores
media. And **the storage driver is an interface from the first line of code**, with local disk as its
first implementation, so that the move to an S3-compatible store is a configuration change. Building
directly against a local path and migrating later is the expensive version of the same work.

The capacity dimension is real and belongs in section S: the VPS is single-disk with local-only
backup by ADR-014, and generated video is the first thing HQ would store that is large, growing and
not reconstructable from a system of record.

---

## N. Is an explicit Consent and Compliance subsystem required?

**Yes. This is the second most important recommendation, and the current design has a specific defect
rather than merely a gap.**

Consent today is `customers.consent`, a jsonb column documented as
`{marketing_sms, marketing_whatsapp, marketing_messenger, source, at}`.

⚠ **A current-state blob answers "may we contact this person now". Compliance requires answering "was
consent valid at the moment we sent that message", months later, about a message someone is
complaining about.** A mutable column cannot answer that, because the value that was true at send
time has been overwritten. This is the same argument that made `audit_logs` immutable and
`decision_records` append-only, and it applies here for the same reason.

### Architecture

An append-only consent ledger, with a derived current-state projection for fast reads.

```
  consent_events (append-only, never updated)
    customer, business, channel, purpose (service | marketing | research),
    state (granted | withdrawn | expired), basis, evidence_ref,
    occurred_at, recorded_at, source

        |  projected into
        v
  consent_state (current, rebuildable from the ledger at any past instant)

        |  consulted by
        v
  ConsentGate  <- called by the Tool Gateway for any tool declaring consentRequired
```

It must also carry what messaging consent alone does not: do-not-contact at the person level rather
than per channel, channel-specific restrictions such as the WhatsApp 24-hour service window,
recording permission for Phase 7, synthetic voice and face consent for Phase 8 with an allowed-use
scope, brand and business restriction, expiry and revocation, retention policy per data class, and
platform-specific rules that differ by connected account.

Two rules worth fixing in the design now. **Withdrawal takes effect immediately and is never
inferred**, so a suppression is a recorded event and not the absence of a grant. And **consent is
checked at the gateway, not inside each tool**, so that adding a channel cannot accidentally add a
channel that forgot to ask.

---

## O. Is an explicit Evaluation subsystem required?

**Yes, and it is a prerequisite rather than a later addition, which is a change to the current
roadmap's assumption.**

`docs/30` has Phase 9 widening autonomy using "evaluation data from Phases 2-6". Nothing in Phases 2
to 6 currently records evaluation data. There is no `evaluations` table despite `docs/48` listing one
in M1's dependency detail. The vision states that evaluation evidence is the prerequisite for
increasing autonomy, and the mechanism that would supply that evidence does not exist.

⚠ **The consequence is concrete: without it, autonomy can only be widened by opinion.** Every
argument this project has made about earning trust through measurement rather than schedule depends
on a table nobody has created.

### Architecture

```
  evaluations                     evaluation_runs
  -----------                     ---------------
  suite, case_key, input_ref      suite, model, prompt_version, skill_version,
  expected (tool calls, refusal,      policy_version, autonomy_level,
    citation, outcome)                started_at, scores, regression_vs
  grader: deterministic | rubric
  business, channel, agent_key
```

What it must measure, taken directly from the target: answer quality, tool-call correctness,
business-policy compliance, hallucination rate, customer satisfaction, handover correctness, order
correctness, campaign decisions, content quality, autonomy agreement rate, false positives and
negatives, and regression after any prompt, skill, model or routing change.

Two properties matter most. **Agreement rate is the currency of the autonomy ladder**: the measured
rate at which a human, reviewing what the agent proposed, agrees. That number is what section K's
evaluation-derived ceiling reads. And **the safety corpus is permanent**, covering injection attempts,
credential requests, cross-business data requests and requests for raw SQL, with expected refusals.
It runs on every change and a regression in it blocks a release rather than filing a ticket.

Because M4 already writes `prompt_version`, `model`, `model_tier` and `routing_reason` onto every
`ai_run`, and `decision_records` has existed since Phase 0, the evidence needed to *attribute* a
regression is already being captured. Only the measurement is missing.

---

## P. Provider abstractions to define before any provider-specific integration

**The pattern already exists and is proven; it has simply never been generalised.**

`App\Commerce\Dto\Capabilities` carries a flag map with `supports(string $operation): bool`.
`CommercePlatform::capabilities()` requires every adapter to declare its own. `FactsReconciler` gates
real behaviour on `$adapter->capabilities()->supports('list_orders')`. The LaLaDia adapter's flags
carry a verification date and mark pending shop features explicitly false.

⚠ Two weaknesses stop it serving the vision. It is **hard-coded in PHP**, so a capability that varies
by connected account cannot be expressed. And `platform_accounts` already has exactly the columns for
that variation, `capabilities`, `scopes`, `approval_status`, `quotas` and `restrictions`, **and
nothing in the application reads or writes any of them.** The table was built in Phase 0 for this
purpose and has never been used.

Recommended: promote the DTO to a shared `App\Platform\Capabilities`, resolve capabilities per
connected account from `platform_accounts` with the adapter's static declaration as the ceiling, and
expose to any agent only the capabilities that account actually has right now.

The interfaces to define before provider work begins, each with capability negotiation and each
provider-neutral in the way ADR-019 made the model router provider-neutral:

`MessagingChannel` (send, template send, media, typing, read receipts, reply-window state)
· `CommentChannel` (comment, hide, private reply, which Instagram has and Facebook expresses
differently) · `TelephonyProvider`, with Bangladesh-local providers and international CPaaS as
separate implementations rather than one interface bent to fit both · `AdsPlatform` (audience,
campaign, budget, insight) · `PublishingPlatform` (publish, schedule, metrics; Facebook supports
scheduling and Instagram does not, per `docs/18`) · `MediaGenerator` (image, video, voice, avatar)
· `ResearchSource` · `StorageDriver`.

The rule that makes this worth doing: **an agent may only be offered capabilities the connected
account actually holds today.** Where approval is pending, the capability is absent and the agent
must be able to say so rather than promise it.

---

## Q. Security threats that become real once AI talks to customers and acts

Today the threat surface is small because no model has ever been called and no tool is
agent-executable. Both facts change in Phase 3, and one threat class changes character completely.

⭐ **Indirect prompt injection is the dominant new risk.** A customer message, a Facebook comment, a
product review and a fetched web page are all text the business does not control, and all of it would
be placed in a model's context. The attack is not "make the model say something rude"; it is "make
the agent call a tool", and the payload can arrive weeks before it is read, through memory or a
research artifact. Three mitigations follow, and they are architectural rather than prompt-level.
First, **content provenance labelling**: every context block carries whether it is trusted business
data or untrusted third-party content, and the boundary is structural rather than a sentence in a
prompt. Second, **untrusted content can never raise autonomy**, which is a rule section K's resolver
must enforce by treating any request whose context contains untrusted content as capped. Third,
**tool arguments derived from untrusted text stay subject to schema validation and the approval hash**,
which M1 and M3 already provide.

The rest, briefly. **Impersonation and reputational harm**, because an agent speaking as the brand
can commit it; R5 already classifies a customer-visible message as material. **Cross-customer and
cross-business leakage**, the confused-deputy case where a customer persuades an agent to reveal or
act on another customer's order; `user_business_scopes` and permission intersection address the
business axis and nothing yet addresses the customer axis. **PII handling and retention**, now that
conversations will contain names, addresses and phone numbers, which is why section J proposes
`dataClass`. **Credential and quota abuse** through induced high-volume outbound, needing per-channel
frequency caps that are enforced rather than configured. **Consent circumvention**, addressed by
section N placing the check at the gateway. **Media risk**, both what customers upload and what
generated media asserts, which is why section M records generator provenance. **Synthetic identity
misuse**, the reason Phase 8 must not begin before consent records exist.

---

## R. What must never become autonomous

The mechanism exists: a tool declaring `agentExecutable: false` reads as `ActionClass::HumanOnly`,
checked before everything else, and no approval can lift it.

⚠ **The defect is that the mechanism cannot distinguish "never" from "not yet".** All six current
tools declare `agentExecutable: false` because none has been cleared, not because none may ever be.
When there are fifty tools and someone is widening autonomy, the permanently forbidden ones look
exactly like the merely uncleared ones. **This should be split before the tool count grows**, into a
`HUMAN_ONLY` declaration that is permanent and a separate "not yet agent-executable" state that is a
stage. It is a small change now and an audit later.

The permanent list, proposed for confirmation:

Moving money in any direction. Changing credentials, permissions, roles or scopes. **Changing
autonomy itself**, since a system that can widen its own autonomy has none. Overriding, back-dating
or deleting consent. Granting synthetic identity consent, or publishing under a real person's face or
voice beyond a recorded scope. Deleting or altering audit logs, decision records or the consent
ledger, which the database already refuses. Legal, regulatory and tax statements. Final pricing
policy. Employment decisions, and anything in payroll. Mass outbound above a human-set threshold.
Accepting liability or issuing a guarantee on the business's behalf. Any write to a system of record
where HQ is not authoritative, which ADR-015 and ADR-007 already establish.

Everything else is a candidate for autonomy earned against evaluation evidence, which is the argument
for section O.

---

## S. Infrastructure to design for without over-engineering

ADR-012 chose the current VPS first, with scaling triggered by measured evidence rather than
anticipation. Nothing here changes that, and the recommendations are all interface-shaped rather than
capacity-shaped: they cost nothing now and prevent a migration later.

**Keep media out of the relational store from the first byte**, behind the storage interface in
section M. **Design conversation tables for volume from the start**, since messages are the first
table that grows without bound, indexed for the access pattern that matters, which is one customer's
recent thread rather than a global scan. **Treat the event backbone as the throughput ceiling**,
because omnichannel inbound arrives there and it is already the right shape. **Keep the model spend
ledger authoritative**, which M4 delivers, so that the first real scaling signal is measured rather
than felt.

⚠ One risk grows quietly and should be named now rather than discovered. ADR-014 accepts local-only
backup with a documented not-disaster-proof limitation. That was a reasonable trade when HQ held
derived data reconstructable from the ERP and the shops. **Conversations, consent records and
generated media are none of those things.** A consent ledger that cannot be restored is a compliance
problem, not an inconvenience. This does not require action today; it requires a decision before
Phase 3 stores its first customer conversation, and `docs/31` already carries an open question about
whether the Hostinger weekly snapshot is even enabled.

---

## Dependency graph

```
  Phase 2 (M1 gateway, M2 policy, M3 approval, M4 model router)   [M1-M3 live, M4 merged pending]
        |
        v
  PHASE 2.5  SHARED FOUNDATION                              <-- recommended, new
        |
        +-- Platform Capability Registry ---------+
        +-- Consent and Compliance ledger --------+
        +-- Autonomy Control Plane ---------------+---> all consumed by everything below
        +-- Evaluation subsystem -----------------+
        +-- Media Asset service ------------------+
        +-- Conversation and Memory core ---------+
        |
        v
  P3 Customer AI ---> P4 AI orders ------------------+
        |                                            |
        +--> P5 Marketing AI --------------------+   |
        |         (needs consent, capability,    |   |
        |          evaluation)                   |   |
        |                                        |   |
        +--> P6 Content factory -----------------+---+---> P9 Skills and autonomy --> P10
        |         + Research Engine              |   |        (consumes evaluation
        |           (belongs here, not in 2.5)   |   |         evidence, which only
        |                                        |   |         exists if 2.5 built it)
        +--> P7 Voice --------------------------+    |
        |      (needs recording consent,             |
        |       telephony abstraction)               |
        |                                            |
        +--> P8 Avatar and voice clone --------------+
               (BLOCKED until synthetic identity
                consent records exist in 2.5's ledger)

  Meta App Review ---- gates P3 channels and P6 publishing; clock has not started
  Storage decision --- gates P6 volume; interface needed in 2.5
```

---

## Summary and answers

### 1. Documents changed

Created `docs/53` (this document). **No existing document was edited**, because none of the above is
approved yet and rewriting `docs/30` before Joy has agreed to Phase 2.5 would put a plan in the
roadmap that nobody has accepted. On approval the edits are: `docs/30` gains Phase 2.5 and a rewritten
Phases 9 and 10; `docs/48` gains a correction that no `evaluations` migration was delivered in M1; new
ADRs for the autonomy plane, the consent ledger, the capability registry and the media boundary.

### 2. Architectural gaps identified

Autonomy is one column where twelve dimensions are needed, combining by minimum rather than
first-match. Consent is a mutable blob where an append-only ledger is needed, and cannot answer the
only question compliance ever asks. No evaluation store exists, which makes the vision's
evidence-earned autonomy unimplementable and contradicts `docs/48`. `HUMAN_ONLY` cannot be
distinguished from "not yet cleared". Capability negotiation is proven in commerce, hard-coded, and
never generalised, while `platform_accounts` holds five purpose-built columns nothing reads. No
conversation, message, memory, lead or case model. No media abstraction and no storage interface. No
research engine, and no threat model for the untrusted text one would ingest. `ToolDescriptor` lacks
channel, monetary impact, consent requirement, customer-facing, reversibility and data class.

### 3. Recommended roadmap changes

Insert Phase 2.5 between Phase 2 and Phase 3. Rewrite Phases 9 and 10 to consume evaluation evidence
rather than assume it. Attach the Research Engine to Phase 6. Make synthetic identity consent a hard
precondition of Phase 8. Leave phase ordering otherwise unchanged.

### 4. New shared foundation modules

Platform Capability Registry, Consent and Compliance ledger, Autonomy Control Plane, Evaluation
subsystem, Media Asset service, Conversation and Memory core. The Research Engine is a Phase 6 module,
not a foundation one.

### 5. External services to prepare

Start now because of lead time: Meta App Review and Business verification, WhatsApp Business Account
with template library, Bangladesh SMS sender identity registration, telephony provider with local
numbering. Before their phases: per-brand Page tokens, Instagram professional accounts linked to
Pages, Meta ads system user token, Google Ads with product feed, TikTok Ads, LinkedIn, an
S3-compatible object store, voice and avatar providers. Immediately outstanding: the Anthropic API
key for the M4 safety gate.

### 6. Blocked by platform approval

Phase 3 messaging in full; Instagram messaging, comments and private replies separately; WhatsApp
templates and opt-in individually; Phase 6 publishing scopes; Bangladesh SMS sender identity; ads
automation review. The architectural response is section P: capabilities are resolved per connected
account, and an agent is offered only what the account holds today.

### 7. Deferred deliberately

The Research Engine to Phase 6. A5 strategic orchestration defined but unreachable. Object storage
backend, though not its interface. Any relaxation of the R4 and R5 approval requirement. Cross-business
executive delegation. Fine-tuning, permanently, per ADR-013.

### 8. M3 safety guarantees, confirmed intact

Verified against the running system, not recalled. Production HQ is `ff34d7d` with seven healthy
containers and was not modified by this review. No tool is agent-executable and a test asserts it.
Nothing is enabled at R4 or above; the only R4 tool, `demo.request_approval`, remains disabled at
runtime. No model has ever been called and the M4 runtime ships behind the `ai.runtime` flag, off by
default. `CredentialVault` remains the only decryption point, enforced by a filesystem-scanning test.
Approvals still bind a canonical payload hash and a tool version, remain single use, and are still
guarded in Postgres by a partial unique index, a status check constraint and a conditional claim
update. `audit_logs` remains append-only and the database refuses updates and deletes.
`ActionClass::HumanOnly` still cannot be lifted by any approval. **Nothing in this review weakens any
of it, and nothing in it was implemented.**

### 9. What M4 should implement next

**Nothing new.** M4 is code-complete and green at 283 passed and 1377 assertions, with both CI jobs
including the production image build succeeding. Its remaining work is not implementation:

1. Merge PR #6, which is `MERGEABLE / CLEAN`.
2. Deploy on Joy's explicit approval, after a backup, and run `ModelTierSeeder`.
3. Install the Anthropic API key as an `integration_credentials` row (`anthropic_api_key` / `hq`).
4. Run the safety gate: `hq:ai:probe` dry, then `--live`, then confirm the `ai_runs` row and
   `hq:ai:cost`.

The M4 scope stands exactly as approved: model router, provider-neutral drivers, fallback, circuit
breaker, structured output, AI run accounting, cost accounting, budget enforcement, a real provider
probe, no autonomous business writes, no R4 or R5 enablement, and no model id in business code.

⚠ If one thing from this review were to be pulled forward into M4, it should be the smallest: **split
`HUMAN_ONLY` from "not yet agent-executable"** before the tool count grows. It is a descriptor field
and a test. Everything else belongs in Phase 2.5 and should wait for approval of this document.
