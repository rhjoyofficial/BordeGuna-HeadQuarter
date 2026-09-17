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

### C.2 Stream B: Phase 2 M7 (branch `m7/retention-alerts-dashboards`)

**Retention, done (`c5a3f21`).** `App\Observe\Retention`:

- `RetentionPolicy` names exactly three detail tables (`tool_calls`, `ai_runs`, `connector_calls`,
  180 days each) and the event payloads (365 days), read from `hq.retention` with no environment
  override and pinned by a test. The permanent classes (`audit_logs`, `decision_records`, agent runs,
  approvals, grants, evaluation, shadow, consent, customer merges) and the tables whose retention is a
  separate personal-data decision nobody has taken (conversations, messages, attachments, memories)
  cannot be named: the policy throws for any other table, and a configuration key for one does
  nothing.
- **What is kept forever inside the detail tables.** Agent detail is the evidence autonomy decisions
  rest on, so retention prunes only unattended detail. A tool call is kept when it has an agent, a
  model run or an approval, when an approval, a conversation message or a consent event points at it,
  when it shares an agent run's correlation id, or when it never finished (`started`, `uncertain`,
  `awaiting_approval`). A model run is kept when it has an agent, is still pending, or when an
  evaluation result, an approval, a conversation message or a kept tool call points at it, or it
  shares an agent run's correlation id. A connector call is kept when it is `uncertain` or shares an
  idempotency key with a kept tool call. A test reads the live schema and fails if a new column ever
  points at a detail row without a matching keep condition.
- `RetentionRunner` works in Asia/Dhaka calendar days: a row is eligible only when its whole day is
  older than the window. Each chunk selects eligible ids under a row lock, rolls them up into
  `daily_aggregates` and deletes exactly those ids in one transaction, and throws (rolling both back)
  if the counts differ. An aggregate row is one pass's contribution for one day, table and grouping,
  with the sums, the id range and the pass id, so it is always distinguishable from the detail it
  replaced and traceable to the pass that wrote it. `retention_watermarks` records each fully drained
  day; the next pass starts after it. Bounds: 500 rows per transaction, 50,000 rows and 31 days per
  table, 600 seconds per pass; a pass that reaches one says which. One pass at a time (a cache lock);
  a pass whose process died is marked `abandoned` by the next. Every pass is a `retention_runs` row
  with the policy it applied and an audit row (`retention.completed` or `retention.failed`).
- Processed events past 365 days keep a digest in place of the payload (`_retention`: policy, pass,
  time, sha256 and size of the original, its top-level key names, and the `_store` and
  `_correlation_id` routing values); dead, failed and pending events keep their payload, and
  `hq:events:replay` refuses a digest. A partial index holds exactly the payloads still eligible.
- `hq:retention:plan` shows what a pass would do and writes nothing; `hq:retention:run` runs nightly
  at 08:15 Dhaka, an hour after the 01:00 UTC backup, so every removed row is still in the previous
  dump. Production's oldest detail rows are from 6 September 2026 (connector calls; tool and model
  calls from 8 September), so nothing is eligible before March 2027 (event payloads: September
  2027); until then each pass records that it found nothing. `ai_runs` stores its times without a
  zone; production pins both the application and the database session to UTC (read on 16 September),
  so the Dhaka day bounds compare correctly on every table.
- Tests: 9, 184 assertions (policy pins, schema guard, plan writes nothing, lineage, day boundary at
  Dhaka midnight, bounded days, idempotent reruns, cost and token sums, connector keys, event digest
  and replay refusal, one pass at a time, abandoned passes).

**Alerts, as designed before the code.** The `alerts` table is in the same migration. The design:

- `hq:alerts:check` every five minutes over a rule list in `hq.alerts.rules`. A rule returns its
  current conditions, or null when it cannot tell (an unreadable host file), in which case its open
  alerts are left alone. One open row per fingerprint; `occurrences` counts checks; a condition must
  be absent on two consecutive checks before its alert resolves; an alert HQ pages is sent again once
  after 24 hours. Opening and resolving are audited; messages go through two new `Notifier` methods
  (in-app always, Telegram where configured), never with a secret or customer data.
- **Already paged elsewhere, so recorded but not sent again** (`paged_by`): the host monitor
  (`deploy/hq-monitor.sh`, root cron every five minutes, verified installed on 16 September) pages
  RAM, disk, the scheduler, Horizon, the database, Redis and both backups on state change; the model
  runtime sends the budget warning once a month per scope; the approvals sweep sends each overdue
  approval. Backups are not visible from the application container at all, so they stay with the
  host monitor.
- The fifteen rules: scheduler tick stale (over 180 s; host monitor); Horizon down (host monitor);
  provider circuit open; AI budget at 80 and 100 percent (model runtime); approvals past their
  deadline (approvals sweep); repeated policy denials for one agent (3 in 60 minutes, or a run
  stopped for repeated denials); fact-integrity discrepancy (the latest succeeded
  `commerce.check_fact_integrity` output, the daily 03:30 job); events dead-lettered in the last 7
  days (production's six known dead events are from 7 September and do not re-alert); a credential
  expiring within 14 days or expired while active; an ERP snapshot not polled for 26 hours (the polls
  are daily at 04:00 and 04:05 Dhaka, so the tool's two-hour label would alert all day); a readiness
  incident (`CapabilityReadiness::incidents()`, including `provider_blocked_by_credit`); a shadow
  period stopped in the last 7 days with no newer period for the agent; disk over 80 percent (90
  critical; host monitor); available memory under 1 GB (host monitor); webhook signature failures
  (10 in two hours, counted by the two webhook controllers).

**Dashboards, as designed before the code.** Four read-only pages per `docs/48` N.3, each over a 24-hour,
7-day or 30-day window (always inside the 180-day detail window, so they read detail rows, not
aggregates), gated by permissions that already exist, so no production seeding is needed: AI health
(`hq.agents.view`), Cost (`hq.ai.cost.view`), Control plane (`hq.tools.view`), Platform
(`hq.horizon.view`), plus an alerts page (`hq.audit.view`), all under a sidebar "Observe" group.

**Alerts, done (`b4be821`).** `App\Observe\Alerts`, checked every five minutes by `hq:alerts:check`:

- `AlertEngine` keeps one open alert per fingerprint (rule key plus subject), counts each sighting,
  resolves an alert once its condition has been absent on two consecutive checks, sends an open
  alert again when it gets more severe or once a day while it stays open, and audits every opening
  and resolution (`alert.opened`, `alert.resolved`). A rule that throws, or answers "cannot tell"
  (a host file it cannot read, Redis it cannot ask), is skipped for that check, so its open alerts
  are neither counted nor cleared; the log names the rule and the exception class, never the message.
- Messages go through three new `Notifier` methods (`alertRaised`, `alertStillOpen`,
  `alertResolved`): in-app always, Telegram where configured. A condition another component already
  pages carries `paged_by` and is recorded and shown without a second message: the host monitor
  (`deploy/hq-monitor.sh`, verified installed as a five-minute root cron on 16 September) for the
  scheduler tick, Horizon, disk and memory; the model runtime for the budget; the approvals sweep for
  overdue approvals. Backups are not visible from the application container at all and stay with
  the host monitor.
- The fifteen rules, as designed above: scheduler tick stale; Horizon down or paused; provider
  circuit open; AI budget at 80 and 100 percent; approvals past their deadline; repeated policy
  denials; the latest fact-integrity result; dead letters from the last seven days; credentials
  expiring within 14 days or expired while active; ERP snapshots not polled for 26 hours; readiness
  incidents (a credit block says credit and "the key itself is not judged"); a shadow period stopped
  in the last seven days with no newer period; disk; memory; webhook signature bursts, counted per
  hour in the cache by both webhook controllers. Disk and memory are read through `HostProbe`, so a
  test never depends on the machine it runs on.
- A blank HQ opens exactly one alert, `readiness.incident:hq:hq:anthropic_api_key`, because it has
  no model provider key; that is the only thing on such a system a person must act on.
- Tests: 19 (engine lifecycle, paging elsewhere, unknown and failing rules, re-notification, every
  rule against controlled state, the webhook counter through the real shop webhook).

**Dashboards, done (`af2c713`).** Four read-only pages per `docs/48` N.3 and the alert record, under
a sidebar "Observe" group, each behind a permission that already exists so no role needs reseeding:
`/observe/ai-health` (`hq.agents.view`), `/observe/cost` (`hq.ai.cost.view`),
`/observe/control-plane` (`hq.tools.view`), `/observe/platform` (`hq.horizon.view`) and
`/observe/alerts` (`hq.audit.view`, with a nav badge counting only what HQ itself sends). The pages
read a 24-hour, 7-day or 30-day window, always inside the 180-day detail window, and read rows inside
the viewer's business scope. Cost buckets days in Dhaka over `ai_runs`' zone-less UTC times, and
every query bound is taken in UTC. What each page shows:

- AI health: how runs ended per agent, model calls and tool calls that did not succeed (with whose
  fault), model latency p50 and p95 per tier, policy refusals by policy, why the runtime stopped
  runs, approvals waiting and overdue, shadow runs waiting to be read, open repeated-denial alerts.
- Cost: spend by Dhaka day, provider and model, agent, business and cost source; per model call, per
  agent run and per conversation (per briefing is shown as none, because no briefing exists yet: it
  needs the model and comes after the gates); the month's budget, share and pace; degraded calls.
- Control plane: tool calls by risk tier and by caller, policy outcomes, approval decision times
  (median and 95th percentile), tools switched off, feature flags, agents and ceilings, active grants,
  open shadow periods.
- Platform: Horizon supervisors and queue workload, scheduler tick age, events waiting and dead,
  model provider circuits, connector calls in the last day, readiness, credentials expiring within
  30 days, the latest integrity result, ERP snapshots with their age, host disk and memory, the last
  retention pass and the watermarks, open alerts by severity.
- Tests: 9, 320 assertions (routes and gates, which roles open which page, every panel against seeded
  rows, the business scope, the window).

**Deviation from the plan.** B.2 named `docs/25` for the dashboards; the pages follow `docs/48` N.3,
which is the Phase 2 design ChatGPT named ("described by the existing Phase 2 design"). `docs/25`'s
"Business impact" dashboard needs assisted orders, which do not exist until Phase 4, and its host
panel is folded into Platform.

**Found on the way.** `ScheduledJobService::create()` reads a `reason` attribute for its audit row but
also passes it into the model, which throws outside production (discarded attributes are refused
there). No caller passes one today; Stream C does, so it is fixed there.

**Verification, the merge gate until 1 October:** Pest, folder by folder: Agents 106,
Ai/Auth/Autonomy/Commerce/Consent 98, Conversation 70, Erp/Evaluation 194, Identity/Facts 38,
Foundation/Gateway 188, Media/Platform/Scheduler/Security/Settings 67, Observe 37, Dashboard/Unit 6:
**804 passed, 0 failed**. Pint `--test` passed; `tsc --noEmit` clean; `vite build` succeeded.
**Local verification passed; GitHub Actions validation deferred until 1 October reset.**

### C.3 Stream C: Phase 2 M6, inert (branch `m6/ask-hq-and-nl-scheduling`)

One commit, `210b93c`. Both surfaces sit behind flags that are off everywhere (`ask_hq`,
`scheduler.nl`), and both ask nothing while `ai.runtime` is off, which on production it is. No prompt,
`AgentRunner`, detector, `TaskGrounding` or gateway file changed in any stream: `git diff 3314c54` over
them is empty, and the stamps still read `executive.v8+18d60e98` and `support.v1+0f4a195b`.

**Ask HQ (docs/48 K).**

- `ask_conversations` and `ask_messages`, separate from the customer conversation tables on purpose
  (trusted operator input against untrusted customer input). **Deviation from K.2:** an answer names
  the agent run that produced it and the run's correlation id, not one `ai_run_id` and one
  `tool_call_id`; an agent run makes several of each, and the correlation id reaches all of them, so
  the trace docs/48 N.1 asks for runs from an `ask_messages` row to every model call, tool call and
  connector call without a broken link.
- `/ask`, behind `hq.ask.use` (held only by the owner and operator roles) and the flag. The only
  write is a question: the words, an agent from `hq.ask.agents` (executive and analytics; the support
  agent answers customers, not operators), a business, and a conversation of the person's own. A
  test posts a tool key, arguments, tool calls, a model and an autonomy level alongside a question
  and shows none of them has any effect, and that the controller validates exactly those four fields.
- While the runtime is off, the question is recorded with a system line saying nothing was asked,
  and the audit says `ask.refused`. Otherwise a queued `AnswerAskTurn` puts the question to the
  unchanged `AgentRunner` (trigger `operator`, the asker recorded on the run) and records the answer,
  the run, whether it was shadow, its autonomy level and its tool calls; the page labels every A0
  answer as a proposal, not HQ's answer. The runtime switch is read again at the moment of asking.
- **A finding, and the rule it produced.** The runner calls tools under the agent's enabling user and
  does not narrow them to the person asking. Ask HQ therefore never lends an agent's reach: the asker
  must be group-wide (no business rows) and must hold every permission the agent's tools require, or
  the question is refused with the reason. Narrowing a run to the asker's own businesses is a runtime
  change and belongs to the M6 gate, not to this surface.
- **Not built, on purpose:** server-sent streaming (the page refreshes itself while an answer is
  pending) and inline approval cards (at A0 no agent can act, and proposals are on the run page).
  Both belong to the M6 gate's version of this screen.

**Scheduling in words (docs/48 L).**

- `JobSpecSchema` sits in the same narrow subset as the agent reply schema (only `type` and `enum`,
  every property required, no nullable fields, the tool arguments as JSON text), because one schema
  goes to the provider's strict mode and to `ArgumentValidator`; a test holds it there.
- `NlJobParser` asks the low tier (task `nl_to_job_spec`) with the registered tools, the businesses
  and the stores in the prompt, and returns a draft. It is the only class in the path that knows a
  model exists, and nothing in the path references the gateway, the runner or a tool's `execute`; a
  test reads the sources to prove it. No new model-to-tool path exists.
- `JobSpecValidator` decides, without a model: the tool is registered; the arguments validate against
  that tool's own schema; the business and store are real rows the person may use (a store becomes
  the tool's `store_slug`, or a question when the tool takes none); the schedule parses and lies in
  the future; the timezone is real. Everything docs/48 L.3 calls ambiguous is a question: a day of
  the month some months lack, a time already passed, anything unresolved. An event-triggered job is a
  question too, because nothing dispatches those jobs yet and one made now would never run. The first
  run is computed in the job's own zone; a test crosses the November daylight-saving change in New
  York.
- A preview waits server-side under a one-time token, for the person who saw it (another person's
  attempt fails and does not spend it). Confirming creates the job through
  `ScheduledJobService::create()` with `created_from: nl` and the person's own words in
  `nl_source_text`; a test does it with a Bangla sentence and reads the words back exactly.
- **docs/48 L.5 made real.** A job that runs an R3 or higher tool is created pending. The scheduler
  already skipped pending jobs; now the job page has Approve and Reject (audited, `hq.jobs.manage`),
  approval computes the next slot from now so no backlog is released, and "run now" refuses a job
  that is not approved, which closes a bypass that had no way to happen until a pending job could
  exist. The form on the scheduler screen still creates every job approved, as it always has; the
  person filling it holds the same permission that approves, and whether the form should follow L.5
  too is a decision recorded here rather than made quietly.
- **Fixed on the way:** `ScheduledJobService::create()` passed its audit `reason` into the model,
  which throws outside production; the reason now goes to the audit row only.
- The sidebar's Ask HQ item and the scheduler's "Describe a job" button appear only when their flags
  are on (the shared `nav.features`).

**The M6 gate, not run.** docs/48's gate for M6 is a real job created from a real sentence in Bangla
and in English, previewed, approved and observed running at the right Dhaka time with its words
kept, and an ambiguous sentence proven to ask. Every step of that is exercised here against the
fixture provider, never against a real model; ChatGPT placed the real gate after the Executive
shadow evidence, and switching either flag on in production is part of that decision.

**Tests:** Ask HQ 8, scheduling in words 25 (a dataset of sixteen ways the validator asks instead of
guessing).

**Also in this stream:** two runbooks (`docs/runbooks/alerts-and-retention.md`,
`docs/runbooks/ask-hq-and-scheduling-in-words.md`), and `docs/70` M now records GitHub Actions as paused
until the 1 October reset by Joy's choice, and the Anthropic credit as not re-checked on 17 September
(the check uses the stored key and waits for his go-ahead).

**Verification, the merge gate until 1 October:** Pest, folder by folder: Agents 106,
Ai/Auth/Autonomy/Commerce/Consent 98, Conversation 70, Erp/Evaluation 194, Identity/Facts 38,
Foundation/Gateway 188, Media/Platform/Scheduler/Security/Settings 92, Observe/Ask 45, Dashboard/Unit 6:
**837 passed, 0 failed**. Pint `--test` passed; `tsc --noEmit` clean; `vite build` succeeded.
**Local verification passed; GitHub Actions validation deferred until 1 October reset.**

### C.4 Stream D: Phase 4 design

`docs/79` (the ERP Sales Order capability) and ADR-046 (the ERP Sales Order is the canonical order
write), both **proposed**, commit `a011f67`. They cover all seventeen items of point 6 (product
identity through ERP and commerce reconciliation), keep `take_order` as one business capability
behind the Capability Registry and the Tool Gateway with no agent knowing the transport (point 7),
and sort the Commerce Platform Interface into what stays as read-side adapters and what moves behind
the ERP capability. Nothing is implemented; no order write exists for any agent.

## D. Consolidated report (point 16), 17 September

Written once for the HQ thread, in the order point 16 lists. Everything below was read from the
repository and from production on 17 September.

**Consolidated report: full speed while Gate 11 waits (docs/78)**

1. **Phase 3 inert.** Built and merged: customer memory in the support bundle (labelled memory, never
   fact, ADR-033 intact); operator remember and forget on resolved threads (audited; episodes never edited); Instagram DM and Facebook comment normalisers from recorded
   fixtures through the same InboundMessage core (flag off, no credential, no send); the HQ side of
   the P3-G3 pairing (echo recorded as external outbound, paired with the proposal that answered the
   same message, a person's verdict of same, better, worse or different, a daily unmeasured sweep); five
   runbooks. Inbound content stays untrusted; nothing customer-facing is active.
2. **P3-G1.** Local, 17 September: 33 scenarios, 33 passed, two replays identical, no residual trace
   (identity, transitions, audited handoffs, redelivery, Dhaka and UTC, business scope, both new
   channels). The production replay needs the invariants in main, which 3314c54 lacks, so it runs after
   the release that follows gate 11. Not Support-agent safety evidence.
3. **P3-G2.** Prepared, not run: behaviour.support is 10 written cases plus one per agent-active
   scenario (Bangla, Banglish, English, mixed script, typos, order questions, complaints, handoff,
   returning customer with memory, injection, fake owner, claimed approval, another customer's order,
   paused and active states). It runs under GATE_AGENT=support after the release, with Joy as the
   enabling user for the simulated run only. Support stays paused.
4. **M7.** Built and merged. Retention: the governed policy (tool, model and connector detail 180 days
   then daily aggregates; processed event payloads 365 days, digest kept), Dhaka days, one
   transaction per chunk, bounded, watermarked, every pass recorded and audited, every aggregate
   naming its pass and id range. Kept forever: the permanent classes, and any
   detail row an agent, approval, evaluation result, conversation message or consent event points at,
   or that shares an agent run's correlation id; a schema test fails if a new reference appears. Nothing
   is eligible before March 2027. Alerts: fifteen rules every five minutes, deduplicated, clear after
   two quiet checks, audited; what the host monitor, model runtime or approvals sweep already pages is
   recorded, not re-sent. Four read-only dashboards (AI health, Cost, Control plane, Platform) and an
   alert page, on existing permissions.
5. **M6, inert.** Ask HQ over the unchanged AgentRunner (flag ask_hq): the only write is a question; a
   test shows a posted tool, arguments or model do nothing; while ai.runtime is off nothing is asked.
   Finding: the runner acts under the enabling user and does not narrow to the asker, so Ask HQ is
   open only to group-wide operators holding every permission the agent's tools need; narrowing the run
   itself is left to the M6 gate. Scheduling in words (flag scheduler.nl): strict JobSpec schema, low
   tier, deterministic validator (registered tool, arguments against its schema, resolved entities,
   future schedule, real zone, questions for ambiguity), preview under a one-time token, created_from nl
   with the words kept. docs/48 L.5 is now real: R3+ jobs are created pending, approve and reject exist,
   run-now refuses an unapproved job. No new model-to-tool path; a test reads the sources.
6. **Phase 4 design.** docs/79 and ADR-046, proposed: take_order is one business capability behind
   the ERP Sales Order capability; all seventeen items of point 6; which Commerce Platform Interface
   pieces stay read-side adapters. Nothing implemented.
7. **Commits and verification.** PR #35 merged 071ade1; streams merged to main by local --no-ff merges:
   A and D 575465a, B e91ef39, C with this report (branch head 210b93c). Local suites: A 767,
   B 804, C 837 passed, 0 failed, plus Pint, strict TypeScript and Vite. Executive stamp still executive.v8+18d60e98; no
   prompt, runner, detector, TaskGrounding or gateway file changed since 3314c54.
8. **CI.** Deferred: local verification passed; GitHub Actions validation deferred until the 1 October
   reset, when CI runs on main and anything it finds is fixed first.
9. **Anthropic capacity.** Not confirmed. Joy has not yet reported adding credit; the last bypass check
   answered 400 (credit). A fresh check was held back by my tool's guard (it uses the stored key);
   it waits for Joy.
10. **Gate 11 readiness.** Unchanged and ready on 3314c54: one fresh attempt 2 once the bypass answers
    200 with sufficient credit, zero unmeasured required, attempt 1's evidence kept.
11. **Production posture (read 17 September).** Release 3314c54. ai.runtime off; Executive, Analytics
    and Support paused at A0 (Support has no enabling user); grants 0; Meta inbound off; no outbound
    provider; consent events 0; no customer cohort; no shadow period (its tables ship with the next
    release). Nothing was activated.

## E. After the report: the trace link, M7's acceptance criterion

`docs/48` M7 names the end-to-end trace test as the acceptance criterion for the whole of Phase 2:
from an `ask_messages` row to an external HTTP status, with no broken link. With Ask HQ built, the
test can be written, and reading the chain for it found two broken links, both in what the connector
layer records rather than in anything that decides:

1. `connector_calls` holds no correlation id, so from a tool call there is no reliable way to its
   connector calls. The gateway already scopes the run's correlation id into the context for the whole
   tool execution (and the shop adapter already forwards it to the shop); the connector logger simply
   never wrote it down.
2. A successful connector call records no HTTP status; only a failure carries one, through its
   exception code.

**The plan, written before the code.** A nullable, indexed `connector_calls.correlation_id`, written by
`ConnectorCallLogger` from the context. The logger also notes the status of every HTTP response the
client receives while a call is being recorded (the client's own `ResponseReceived` event), and writes
it for a success as well as a failure. Nothing an agent, the gateway or a policy decides changes, and
the ERP keeps receiving its fresh per-request id. Retention keeps a connector call forever when its
correlation id belongs to an agent run, as it already does for tool and model calls. A test then walks
the whole chain from an Ask HQ question: the answer row, its agent run, the run's model calls and tool
calls, the connector call, and the ERP's 200.

**Record (`bcd1500`, branch `m7/trace-link`).** Built as planned: `connector_calls.correlation_id`
(migration `2026_09_17_200000`), written by `ConnectorCallLogger` from the context; the status of each
HTTP response received during a recorded call, noted through the client's `ResponseReceived` event and
written on success as well as failure, with the logger's state saved and restored around every call so
nothing carries from one call to the next in a long-running worker; exception codes that are not
integers (a database error's SQLSTATE) no longer reach the status column. Retention keeps a connector
call forever when its correlation id belongs to an agent run.

`TraceTest` walks the chain from an Ask HQ question: the answer row names its agent run, the run names
the question and the person, the run's two model calls and the gateway's tool call share its
correlation id, the ERP connector call carries it too, and the ERP's answer is recorded (200, and 503
when the ERP fails). The page shows the same tool call to the person who asked. Two more tests pin the
logger: a response outside a recorded call is never attributed to the next call, and a call made
outside any gateway call carries no correlation id.

**The release after gate 11 now carries five migrations:** `2026_09_16_100000` (shadow periods),
`2026_09_16_200000` (pairing), `2026_09_16_300000` (retention, alerts, indexes), `2026_09_17_100000`
(Ask HQ), `2026_09_17_200000` (connector correlation). All are additive.

**Verification, the merge gate until 1 October:** Pest, folder by folder: Agents 106, Ai/Auth/Autonomy/Commerce/Consent 98,
Conversation 70, Erp/Evaluation 194, Identity/Facts 38, Foundation/Gateway 188,
Media/Platform/Scheduler/Security/Settings 92, Observe/Ask 48, Dashboard/Unit 6: **840 passed, 0 failed**.
Pint `--test` passed; `vite build` succeeded; no TypeScript changed, and `tsc --noEmit` was clean
on the stream C head.
**Local verification passed; GitHub Actions validation deferred until 1 October reset.**

**Proposed next, while gate 11 still waits (for ChatGPT to order or decline).**

1. The rest of M7, built inert: the daily briefing's evidence bundle (typed facts and metrics with
   source and as-of, from HQ's own read models) and the unsourced-number check (every figure in a
   narrative must exist in the bundle, or the briefing is marked degraded), both deterministic; the
   narration behind a flag, off; no scheduled job created. docs/48 M already specifies all of it.
2. Phase 4 stays at design. The ERP endpoints `docs/79` F specifies are an order write, which point 6
   defers, so nothing is built on either side until that is ordered.
3. Nothing else is buildable without Joy or an external party: P3-G3 to G5 wait for Meta, the gates
   wait for credit, and the release waits for gate 11.

**Second message for the thread, after section D (short).**

> Addendum to the consolidated report. After it, I built M7's acceptance criterion, the end-to-end
> trace test, and it found two broken links in what the connector layer recorded: connector calls
> carried no correlation id, and a successful call carried no HTTP status. Both are now recorded
> (recording only; no agent, gateway or policy decision changed), agent-linked connector calls are
> kept forever by retention, and the test walks one Ask HQ question to the agent run, its model
> calls, the gateway's tool call, the ERP connector call and the ERP's 200 (and 503 on failure).
> Local suite 840 passed, 0 failed; merged to main. The release after gate 11 now carries five
> additive migrations.
>
> Proposed next while gate 11 waits, for you to order or decline: the rest of M7 built inert (the
> briefing's evidence bundle and unsourced-number check, deterministic; narration behind a flag, off;
> no scheduled job). Phase 4 stays at design, since its ERP endpoints are an order write. Nothing else
> is buildable without Joy or an external party.
