# 78 - Full speed while gate 11 waits: Phase 3 inert expansion, M7, M6 inert, Phase 4 design (16 September 2026)

**Status:** the plan for the milestone ChatGPT ordered on 16 September after Joy's direction to work at
full speed on everything that needs no action from him, written before the code, then the record as
each part completes. Gate 11 attempt 2 stays first the moment the Anthropic bypass answers 200
(`docs/77` §E); nothing here activates anything.

**Read first:** `docs/77` (the gate-11 milestone, the shadow period, P3-G1 and P3-G2, the shadow
probes and readiness states), `docs/72` (Phase 3 design), `docs/48` (M6 and M7), `docs/21` (the
scheduler and its natural-language entry), `docs/25` (observability), `docs/60` (the boundaries).

## A. Joy's direction and the instruction, verbatim

Joy, 16 September, on being told the state of the phases (three done, two in progress, seven not
started) and asked to fund GitHub Actions: "i want you to wait for next month until it reset. I also
you said already 3 done and 2 in process and 7 not started, this is good but I want you to complete
the process and phased you properly understand and can do without my interaction. I want you to start
your work in full speed. continue work." Earlier the same day he delegated the decisions he could not
judge to my recommendation ("others do as you recommends, i do not have idea"), which made the
Executive's A0 shadow on a complete gate-11 pass, his own name as the support agent's enabling user,
and the P3-G1 production replay decided.

ChatGPT, in the HQ thread, in answer to the continuation note that carried those facts and a proposed
order. Its Bangla preface, in English: the order is refined, not struck; Phase 3 inert expansion, go;
M7 observability and retention, go and now, with retention deterministic, auditable and never touching
the forever-kept classes, and aggregate lineage kept; M6 built now and gated after the shadow week,
because the shadow's real evidence is the better baseline; Phase 4 designed now, with the ERP as the
canonical order system and `take_order` never per-shop; local verification is an acceptable temporary
merge gate because Joy chose to wait for the 1 October reset, but not a permanent process, and nothing
may be merged that cannot be verified locally; PR #35's design is approved; "blocked activation is not
blocked development". The instruction itself:

> **Continue at full speed while Gate 11 waits**
>
> Joy's direction is accepted: continue the work in full speed wherever the work is safe, understood, and does not require his interaction.
>
> The current order is approved with the following exact sequencing.
>
> 1. Continue Phase 3 inert expansion now
>
> Proceed with the already-approved Phase 3 work: customer memory recalled into the Support agent bundle as explicitly labelled memory; operator "remember a fact" on a resolved thread; Instagram DM normalizers with fixtures; Facebook comment normalizers with fixtures; same channel-neutral InboundMessage core; Phase 3 runbooks; HQ-side shadow_comparisons pairing infrastructure.
>
> Keep ADR-033 intact: memory is never truth. Do not turn generated/inferred memory into authoritative customer or ERP data. Keep all inbound external content provenance-labeled as untrusted. Do not activate customer-facing production messaging. Do not enable Meta inbound. Do not register a production outbound provider. Do not create production autonomy grants.
>
> 2. Finish Phase 3 G1 work
>
> Run/close the Conversation Core gate where possible using the simulation world and the existing deterministic fixtures.
>
> Required evidence includes: all simulation scenarios pass; identity resolution deterministic; state transitions valid; handoff transitions audited; idempotent redelivery; correct Dhaka/UTC timestamp handling; business/brand scope enforcement; no leaked rows outside the simulated brand/business; replay equivalence; zero residual trace after rollback.
>
> The production replay remains available only under Joy's explicit permission, which he has now provided.
>
> Do not treat G1 as Support-agent safety evidence.
>
> 3. Prepare P3-G2 Support Agent Shadow
>
> Continue the Support-agent evaluation infrastructure. Keep the Support suite role-scoped and separate from Executive evaluation.
>
> Include: Bangla; Banglish; English; mixed script; typos; order questions; complaints; human handoff; returning customer; prompt injection; fake owner claims; claimed approvals; customer-scope violations; paused/active agent state.
>
> The Support agent remains paused until its own gate is passed and its activation is explicitly authorized. No real outbound customer messaging.
>
> 4. Continue Phase 2 M7 operational foundation now
>
> Build the retention/pruning, alerting, and dashboard pieces that do not require model execution.
>
> Implement the currently governed retention policy: audit_logs → forever; decision_records → forever; tool_calls → 180 days, then daily aggregates; ai_runs → 180 days, then daily aggregates; connector_calls → 180 days, then daily aggregates; event payloads → 365 days.
>
> Retention execution must be: deterministic; bounded; auditable; idempotent; safe to retry.
>
> Never prune the permanent evidence classes. Do not silently destroy historical evaluation evidence needed for autonomy decisions. Document the aggregate lineage so retained aggregates remain distinguishable from the original high-volume records.
>
> Build the alert set and four dashboards described by the existing Phase 2 design.
>
> 5. Build Phase 2 M6 inertly now
>
> Continue the model-dependent M6 implementation without activating it.
>
> Build: Ask HQ over the existing runner; refusal behavior while ai.runtime is OFF; NL-to-JobSpec; strict JobSpec schema; deterministic validator; gateway execution boundary; audit/correlation; scheduler integration.
>
> Do not activate Ask HQ or NL scheduling in production. The M6 evaluation/gate remains after the Executive shadow evidence is available. Do not let M6 introduce any new direct model→tool path.
>
> 6. Begin Phase 4 architecture/design now
>
> Treat the ERP as the canonical system of record for future order creation once the ERP website modules exist.
>
> The long-term capability should be: take_order; not: take_order_via_LaLaDia_checkout or take_order_via_Bionic_checkout.
>
> Design a canonical ERP Sales Order capability/interface. The eventual flow should be conceptually: customer conversation → order intent/structured order → validation → ERP Sales Order capability → ERP → downstream shop/channel projection.
>
> The design must explicitly cover: product identity; variant/SKU identity; quantity; pricing source; customer identity; delivery address; phone validation; shipping; discounts/coupons; payment/COD; order totals; stock/availability confirmation; idempotency; order status; cancellation/modification; failure and compensation; ERP/commerce reconciliation.
>
> Do not implement autonomous order writes yet.
>
> Create the Phase 4 ADR/interface proposal and identify which existing Commerce Platform Interface pieces remain useful as read-side adapters versus which order-write responsibilities should move behind the ERP Sales Order capability.
>
> 7. Keep capability and provider abstractions intact
>
> The ERP becoming canonical for order creation does not remove provider neutrality. The model continues to reason about a business capability. The provider/account/ERP implementation remains behind the Capability Registry and Tool Gateway. No agent should know whether the eventual implementation is direct ERP API, internal service, MCP adapter, or another protocol.
>
> 8. Keep Gate 11 blocked but unchanged
>
> Do not launch Gate 11 attempt 2 until: Anthropic bypass = HTTP 200; sufficient prepaid credit exists for the complete suite.
>
> When capacity becomes available: run exactly one fresh Gate-11 attempt 2; use release 3314c54; no code/prompt changes immediately before the run; zero unmeasured cases required; preserve Gate-11 attempt 1 provider-failure evidence; stop after the fresh result.
>
> Gate 11 remains Gate 11.
>
> 9. CI-minute constraint until October 1
>
> Joy has chosen not to purchase additional GitHub Actions minutes. Accept local exhaustive verification as the temporary merge gate until the September quota resets.
>
> For every change during this period, require and record: full relevant Pest suite; Pint; strict TypeScript; Vite build; relevant production/build checks; deployment-safety checks where applicable.
>
> Do not claim "CI green" during the unavailable period. Record it as: local verification passed; GitHub Actions validation deferred until 1 October reset.
>
> On 1 October: run CI on main; reconcile any CI-only failures; do not ignore failures discovered then.
>
> 10. Shadow Probes
>
> Keep the already-built Shadow Probe system. The production seven-day Executive shadow will use the three operator-owned questions twice daily: what needs my attention today; how did the group do yesterday; how current is the data.
>
> They are trusted scheduled tasks, not open-ended authority. Each probe must retain task matching and evidence completeness. A production shadow week cannot close as complete if required probe evidence is missing.
>
> 11. A0 activation remains conditional
>
> Joy has now explicitly approved the conditional Executive A0 shadow activation. Therefore, once the fresh Gate-11 attempt 2 passes completely: enable ai.runtime; activate Executive at A0; open the seven-day shadow period; keep Analytics paused; keep Support paused; grants = 0; outbound = OFF; Meta inbound = OFF; business writes = OFF.
>
> Do not activate before Gate 11 passes. Do not automatically promote autonomy after seven days.
>
> 12. Support enabling user
>
> Joy has explicitly agreed to be the Support agent's enabling user for P3-G2. Use that only when the P3-G2 gate is actually executed. Do not interpret this as authorization for real customer-facing Support activation.
>
> 13. Credential/readiness health
>
> Keep Anthropic, LaLaDia, Bionic and future providers represented through explicit readiness states. A provider blocked by credit is not an invalid credential. A missing credential is not a model safety failure. A healthy connector does not grant authorization. Keep those facts separate in the UI, audit, and evaluation.
>
> 14. Do not broaden TaskScope heuristically
>
> Do not implement TaskScope v2 merely to make Executive automatically query ERP data for phrases such as "what needs my attention". If the business wants a standard Executive briefing later, represent that as an explicit skill/task contract rather than natural-language heuristics.
>
> 15. Production invariants
>
> At all times before Gate 11 passes: ai.runtime = OFF; Executive paused A0; Analytics paused A0; Support paused A0; autonomy grants = 0; Meta inbound OFF; outbound OFF; no customer production cohort; no autonomous business writes.
>
> 16. Reporting cadence
>
> Do not wait for Gate 11 to report all progress. Use one consolidated milestone report covering: Phase 3 inert progress; P3-G1 status; P3-G2 preparation/status; M7 implementation/status; M6 inert implementation/status; Phase 4 ERP Sales Order architecture; PRs/commits and local verification; CI deferral status; Anthropic provider capacity; Gate-11 readiness; current production posture.
>
> The next report should show the cumulative engineering progress rather than only the Gate-11 blocker.
>
> Do not activate any new production capability except the conditional Executive A0 shadow after a complete fresh Gate-11 pass and the already-authorized Joy confirmation.

## B. The plan (written before the code)

Four streams, built in this order on one branch per stream, each merged on a full local verification
recorded here as "local verification passed; GitHub Actions validation deferred until 1 October".
Nothing in any stream touches a prompt file, `AgentRunner`, the detectors, `TaskGrounding`, the
gateway's decisions or the readiness logic, so the executive stamp stays `executive.v8+18d60e98`
and gate 11 attempt 2 measures what it was built to measure.

### B.1 Stream A: Phase 3 inert expansion (point 1)

1. **Customer memory into the support agent's bundle.** A `SupportContext` service builds what the
   support agent is given for a thread, in one place for the P3-G2 subject and for the later real
   wake-up: HQ's header (system), the customer as HQ knows them from its own tables (business
   provenance: name, how many orders, first and last seen, their recent order facts with status and
   date), what is remembered about them from `MemoryStore::recall(['customer_id'])` (each block keeps
   the memory's own provenance and is introduced by a system line saying it is memory, may be stale,
   and is never a fact to act on: ADR-033), then every customer message untrusted, as today. Nothing
   inferred is written back: the agent remembers nothing on its own.
2. **Operator "remember a fact".** On a thread whose customer is known, an operator with
   `hq.inbox.manage` may record a structured memory (a key from a short fixed list: delivery notes,
   preferred language, preferred contact time, a note about the person; plus a free-text episodic
   note) scoped to the customer and the business, provenance `business`, `conversation_id` set,
   `source_ref` the operator, audited. Structured memory supersedes the previous value for its key;
   episodic memory is never edited. The thread page shows what is remembered, with its source and
   date, and a "forget" that closes a structured value (never deletes).
3. **Instagram DM and Facebook comment normalisers**, pure like the Messenger one, from recorded
   Meta payload shapes kept as fixtures: Instagram messaging events (`object: instagram`, sender id,
   recipient id, `message.mid`, text, attachments) and Page feed comment events (`object: page`,
   `changes[].field = feed`, `value.item = comment`, `comment_id`, `post_id`, `from`, `message`,
   `parent_id`). Each becomes an `InboundMessage` on `Channel::Instagram` or `Channel::Comment` with
   its own thread reference (the Instagram conversation; the post and the commenter), through the
   unchanged core, flag off, no credential, no send path. The webhook controller routes by `object`.
   The simulation library gains one scenario per channel so P3-G1 covers them.
4. **Shadow comparison pairing (HQ side of P3-G3).** `ShadowComparisons::pair()`: for a thread with a
   support-agent proposal, when an outbound message arrives that HQ did not send (the n8n bot's
   reply, recorded through the same core as an `external` outbound), a `shadow_comparisons` row pairs
   the proposal's digest with the external digest, with a verdict left for a person (`same`, `close`,
   `different`) on the thread page. Inert until n8n forwards a copy; testable with the simulated
   channel now.
5. **Runbooks** in `docs/runbooks/`: the inbox (states, hand-over, notes, proposals, remember), the
   P3-G2 gate (`GATE_AGENT=support`), the shadow week (open, probes, evidence, stop, close), the
   readiness table, and what to do when the provider is blocked.

### B.2 Stream B: Phase 2 M7 operational foundation (point 4)

1. **Retention** as a versioned policy in code (`config('hq.retention')`), a `hq:retention:run`
   command the scheduler runs nightly, and an `hq:retention:plan` dry run. Permanent classes
   (`audit_logs`, `decision_records`, `evaluation_*`, `shadow_*`, `agent_runs`) are not in the policy
   at all, so no configuration can name them. For `tool_calls`, `ai_runs` and `connector_calls`:
   rows older than 180 days are first rolled up into `daily_aggregates` (one row per day, table,
   and grouping key: counts, sums, cost, by status and class), then deleted in bounded batches;
   every run writes a `retention_runs` row (what it aggregated, what it deleted, the id ranges, the
   policy version) and an audit row, so the lineage of every aggregate is on the record. `events`
   older than 365 days keep their row and lose their `payload`. Idempotent: a rerun finds nothing
   older than the watermark it already processed.
2. **Alerts**: the alert set from `docs/25`, as scheduled checks that write an `alerts` row and send
   the Telegram message HQ already has a channel for, deduplicated by fingerprint until resolved.
3. **Dashboards**: the four from `docs/25`, as read-only Inertia pages over the existing tables.

### B.3 Stream C: Phase 2 M6, inert (point 5)

`ask_conversations` and `ask_messages`; an Ask HQ page behind the flag `ask_hq` that puts a question
to an agent through the unchanged `AgentRunner` (the same path `hq:agent:ask` takes) and shows the
run as the agent screen does, refusing while `ai.runtime` is off; NL-to-JobSpec (`JobSpec` schema,
low-tier model with a strict schema through the model runtime, `JobSpecValidator` deterministic,
entity resolver from the business directory, a preview the operator approves before any row is
written, `created_from: nl` with `nl_source_text`), behind `scheduler.nl`. No new model-to-tool path:
Ask HQ executes nothing the runner would not, and the NL parser produces a job row for the existing
scheduler, never a tool call.

### B.4 Stream D: Phase 4 design (point 6)

`docs/79` and ADR-046: the ERP Sales Order capability, its interface, the seventeen items point 6
names, and which Commerce Platform Interface pieces stay as read-side adapters. Design only.

### B.5 Verification and merge, until 1 October

Per stream: the touched test folders plus the whole suite folder by folder (the PC's memory kills a
single full run), Pint, `tsc --noEmit`, `vite build`; recorded in §C as "local verification passed;
GitHub Actions validation deferred until 1 October reset". Merged to main by a local `--no-ff`
merge. On 1 October CI runs on main and anything it finds is fixed before any further merge.

## C. Record

### C.1 Stream A: Phase 3 inert expansion (branch `p3/inert-expansion`)

Five commits, each one of the B.1 items, none touching a prompt, `AgentRunner`, the detectors, the
gateway's decisions or the readiness logic. The stamps, read from `PromptLibrary` after the last
commit: `executive.v8+18d60e98` and `support.v1+0f4a195b`, the same as the release gate 11 attempt 2
will run on (`3314c54`); `git diff 3314c54` over the prompts and the runner is empty.

1. **`3c4ff8d` customer memory and remember/forget.** `App\Conversation\SupportContext` builds the
   support agent's bundle in one place: HQ's header (system), the customer as HQ's own tables know
   them with their five most recent order facts (business), a system line introducing memory as
   possibly stale and never a fact to act on, up to twenty memory blocks each with the memory's own
   provenance, then every customer message untrusted. `ConversationSimulator::capture()` returns the
   same blocks, and the P3-G2 subject hands them to the agent, so the gate measures the bundle the
   real wake-up will use. The inbox gained "Remembered about this customer": a manager may record
   one of four structured keys (delivery notes, preferred language, preferred contact time, about)
   or an episodic note, only on a thread that is resolved or closed and has a customer, provenance
   `business`, audited `memory.remembered`; "forget" closes a structured value (`memory.closed`)
   and never deletes, and an episode is never closed. A customer merge now carries memory to the
   kept customer, closing an absorbed live value that collides with one the kept customer holds.
   Nothing the agent infers is written anywhere (ADR-033 unchanged).
2. **`fc221e9` Instagram DM and Facebook comment normalisers.** Both pure, from recorded payload
   shapes kept as fixtures (`tests/fixtures/meta/instagram_text.json`, `feed_comment.json`).
   Instagram messaging events become `Channel::Instagram` messages (identity channel
   `instagram_igsid`), dropping echoes and read receipts. Feed events become `Channel::Comment`
   messages for added comments by people other than the Page, one thread per person per post
   (`comment:<page>:<post>:<person>`, identity channel `facebook_user`), carrying the post, the parent
   comment and the commenter's name. The webhook controller routes by `object` (`page`, `instagram`)
   to the account HQ holds, or the event dead-letters. Same core, flag off, no credential, no send
   path. Two scenarios (`instagram.dm`, `facebook.comment`) put both channels into P3-G1.
3. **`fbad36b` the HQ side of the P3-G3 pairing.** The Messenger normaliser yields the Page's own
   echoes separately (`OutboundEcho`); `ConversationService::recordExternalOutbound()` records one on
   its open thread once, as an `external` outbound with provenance `generated`, and nowhere when there
   is no thread. `ShadowComparisons::pair()` pairs it with the most recent unpaired support-agent
   proposal that answered the same customer message, storing digests and references only.
   **Deviation from B.1:** the verdict words are `same`, `better`, `worse` and `different`, not
   `same`, `close`, `different`. The comparison exists to decide whether the agent may replace the
   bot, and "close" says nothing about which side a person preferred; the four words do, and they
   still leave `different` for "neither better nor worse". The verdict is a person's, on the thread
   page, audited. A daily sweep (`hq:conversation:compare-sweep`, 01:00 Dhaka) records a proposal that
   nothing answered within a day as `unmeasured`, once.
4. **`a011f67` Stream D** (below, C.4), committed on this branch because it is documentation only.
5. **`832a760` schedule, one scenario, runbooks.** The sweep is scheduled; the scenario
   `customer_scope.someone_elses_order` joins the P3-G2 set (a customer asking about another
   customer's order); `docs/runbooks/` holds the inbox, the P3-G2 gate, the shadow week, the readiness
   table and what to do when the provider is blocked, with a README index.

**P3-G1 evidence, local, 16 September:** `hq:conversation:simulate --repeat=2` against the local
database: 33 scenarios, 33 passed, 0 failed, rolled back; 2 replays with identical observations;
trace none, every table holding the rows it held before. The production replay (Joy permitted it)
needs the invariants this code carries, which `3314c54` does not have, so it runs after the release
that follows gate 11 (docs/77 §E order), not before.

**P3-G2 preparation:** `behaviour.support` is the ten written cases plus one `sim.*` case per
agent-active scenario, now including memory, customer scope and the two new channels' refusal to
wake the agent where the core hands the thread to a person. It runs only under
`GATE=p3-g2 GATE_AGENT=support` after the release and after Joy is set as the support agent's
enabling user; the support agent stays paused.

**Verification, the merge gate until 1 October:** Pest, the whole suite folder by folder (the PC's
memory cannot hold one run): Agents 106, Ai/Auth/Autonomy/Commerce/Consent 98, Conversation 70,
Erp/Evaluation 194, Identity/Facts 38, Foundation/Gateway 188, Media/Platform/Scheduler/Security/
Settings 67, Dashboard/Unit 6: **767 passed, 0 failed**. Pint `--test` passed; `tsc --noEmit` clean;
`vite build` succeeded. **Local verification passed; GitHub Actions validation deferred until
1 October reset.**

### C.2 Stream B: Phase 2 M7

(Filled in when the stream completes.)

### C.3 Stream C: Phase 2 M6, inert

(Filled in when the stream completes.)

### C.4 Stream D: Phase 4 design

`docs/79` (the ERP Sales Order capability) and ADR-046 (the ERP Sales Order is the canonical order
write), both **proposed**, commit `a011f67`. They cover all seventeen items of point 6 (product
identity through ERP and commerce reconciliation), keep `take_order` as one business capability
behind the Capability Registry and the Tool Gateway with no agent knowing the transport (point 7),
and sort the Commerce Platform Interface into what stays as read-side adapters and what moves behind
the ERP capability. Nothing is implemented; no order write exists for any agent.
