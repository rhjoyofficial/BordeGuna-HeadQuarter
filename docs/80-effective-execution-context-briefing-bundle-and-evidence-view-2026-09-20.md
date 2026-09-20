# 80 - Effective Execution Context, the briefing evidence bundle, the autonomy evidence view, and release-state visibility (20 September 2026)

**Status:** the plan for the milestone ChatGPT ordered on 20 September, written before the code, then
the record as each stream completes. Gate 11 attempt 2 stays first the moment the Anthropic bypass
answers 200 (`docs/77` section E); nothing here activates anything.

**Read first:** `docs/78` (the previous milestone and its consolidated report, section D),
`docs/48` sections E.3 (the intersection rule), K (Ask HQ), M (the briefing), `docs/60` (the
boundaries), ADR-030 (evaluation dimensions), ADR-045 (the shadow period).

## A. Joy's direction and the instruction, verbatim

Joy, 20 September, after the move to the new PC: "resume the bordeguna-hq work, take ownership and
make own decision, discuss with chatgpt, use full effort, continue work and sync." Then, during the
session: "we have to speed up, run multiple agent if needs but we have to complete full project ASAP
without skipping anything, with perfection", and "remember it we needs to speed up this project, top
priority."

The consolidated report of `docs/78` section D (with the trace-link addendum folded in as point 7)
was posted to the HQ thread on 20 September with five questions. ChatGPT's answer, in Bangla, in
short: the proposal is approved, and tightened in two places. The typed evidence bundle is the
canonical contract of the Executive's standard briefing, but it is not truth, it is the bounded
evidence surface the narrator may speak from. The Ask HQ authorization finding must not be an M6
patch: it becomes a generic Effective Execution Context, an intersection and never a union, used by
every AI entry point. It added what the proposal had missed: an autonomy evidence view (read-only),
deterministic lineage from every briefing number back to its source, and an operator-visible
difference between the production release and main. It would not build a new safety gate now. The
instruction itself:

> Proceed at full speed on the safe/inert work. I agree with the overall proposal, with the following decisions and refinements.
> 
> ## 1. Build the M7 Executive briefing evidence bundle now
> 
> APPROVED.
> 
> Build the deterministic typed evidence bundle specified by the existing M7 design.
> 
> The bundle becomes the **canonical evidence contract for the future Executive standard briefing**.
> 
> Important distinction:
> 
> **The evidence bundle is not business truth. It is the bounded evidence surface from which the briefing narrator is allowed to speak.**
> 
> The flow must be:
> 
> HQ/ERP read models
> → typed briefing evidence bundle
> → completeness/provenance/freshness validation
> → LLM narration
> 
> Each evidence item should carry, as applicable:
> 
> * metric/fact identifier
> * value
> * unit
> * business/brand scope
> * source reference
> * as-of timestamp
> * freshness information
> * deterministic derivation/provenance
> 
> The unsourced-number check must ensure that every numeric figure in the generated narrative can be traced to a bundle item.
> 
> The narrator must not introduce unsupported numbers.
> 
> A missing/stale/incomplete evidence bundle should produce an explicit degraded state rather than silently filling gaps.
> 
> Keep narration behind its existing feature flag and OFF.
> 
> Do not create the standard scheduled briefing job yet.
> 
> ## 2. Make the briefing task a real explicit capability contract
> 
> Do NOT implement TaskScope v2 heuristics for phrases such as:
> 
> * “what needs my attention”
> * “how did the group do”
> * “how current is the data”
> 
> Instead define an explicit Executive Briefing capability/skill contract whose trusted task produces the typed evidence bundle.
> 
> The normal user-facing natural-language question may map to that explicit capability, but the capability semantics must be deterministic.
> 
> This is the durable replacement for heuristic TaskScope expansion.
> 
> ## 3. Build generic Effective Execution Context now
> 
> APPROVED, and broaden the Ask HQ fix beyond M6.
> 
> Create one canonical execution-context resolution mechanism for every AI entry point.
> 
> At minimum distinguish:
> 
> * requesting actor
> * enabling identity
> * acting identity
> * agent
> * business scope
> * brand scope
> * store/platform scope where applicable
> * environment
> * applicable permissions/policy/consent/capability readiness
> 
> The effective authority must be the **intersection**, never the union.
> 
> Conceptually:
> 
> requester
> ∩ enabling identity
> ∩ business/brand scope
> ∩ tool permission
> ∩ policy
> ∩ consent
> ∩ capability readiness
> 
> Do not let an enabling user grant the asker more authority than the asker possesses.
> 
> Do not let the asker escape the enabling user's authority.
> 
> A scheduled/system invocation must use the same canonical resolution path rather than getting a special bypass.
> 
> Add regression coverage for combinations such as:
> 
> * owner requester + restricted enabler
> * restricted requester + owner enabler
> * restricted requester + restricted enabler with different scopes
> * same permission but different business scopes
> * same business but different brand scopes
> * tool permission present only on one side
> * requester/enabler mismatch
> * no enabling identity
> 
> The resolved effective context must be auditable.
> 
> ## 4. Apply the same execution-context primitive to Ask HQ
> 
> M6 must no longer execute solely under the enabling user's reach.
> 
> The actual execution context must be narrowed to the effective intersection described above.
> 
> Do not create an M6-only authorization shortcut.
> 
> This is a shared control-plane primitive for future interactive, scheduled, delegated, and shadow execution.
> 
> ## 5. Build the autonomy evidence view now
> 
> Create a read-only operator view of autonomy evidence without changing autonomy grants.
> 
> It should make visible, per agent and relevant evaluation dimension:
> 
> * latest measured result
> * evidence count
> * agreement rate
> * required threshold
> * recent failures
> * unmeasured state
> * current autonomy ceiling
> * whether evidence satisfies the configured requirement
> * exact reason when it does not
> 
> Do not create or modify autonomy grants from this view.
> 
> This is evidence/observability infrastructure only.
> 
> ## 6. Add deterministic evidence lineage
> 
> For the M7 briefing bundle and the autonomy evidence view, preserve source lineage.
> 
> A number or evaluation metric should be traceable to:
> 
> source record
> → derived/read-model record
> → bundle/result
> → narrative or decision
> 
> Do not use opaque aggregation without retaining enough provenance to reconstruct how the value was obtained.
> 
> ## 7. Keep M6 inert
> 
> Continue building:
> 
> * Ask HQ
> * strict JobSpec
> * deterministic validation
> * NL scheduling
> * preview/token flow
> * approval state
> * audit/correlation
> 
> The effective execution-context correction above must land before the M6 gate.
> 
> Do not enable `ask_hq`.
> 
> Do not enable `scheduler.nl`.
> 
> Do not create production model-driven automation.
> 
> ## 8. Continue Phase 3 inert work
> 
> Proceed with:
> 
> * labelled customer memory in the Support bundle
> * audited remember/forget on resolved threads
> * Instagram DM normalization
> * Facebook comment normalization
> * P3-G3 comparison/pairing infrastructure
> * runbooks
> * support-agent fixtures
> * simulation/replay infrastructure
> 
> Maintain:
> 
> * untrusted inbound provenance
> * memory never becomes truth
> * no real outbound messaging
> * Meta inbound OFF
> * no production customer cohort
> 
> ## 9. Close P3-G1 where possible
> 
> Run/close the Conversation Core gate from the approved simulation environment.
> 
> The production replay may use the explicit permission Joy has already granted, but it must remain a rolled-back/traceless verification until the release containing the required invariants is deployed after Gate 11.
> 
> Do not treat G1 as Support-agent safety evidence.
> 
> ## 10. Prepare P3-G2, but keep activation separate
> 
> Continue Support-agent evaluation work.
> 
> The Support agent remains paused.
> 
> Its enabling-user relationship must use the new Effective Execution Context.
> 
> Do not activate customer-facing outbound messaging.
> 
> P3-G2 remains a separate role-scoped gate.
> 
> ## 11. Continue Phase 4 design only
> 
> Keep Phase 4 at design stage.
> 
> The canonical future write capability is:
> 
> `take_order`
> 
> implemented through the ERP Sales Order capability/interface.
> 
> Do not implement autonomous order writes yet.
> 
> The design must preserve:
> 
> * product/variant identity
> * customer identity
> * quantity
> * price source
> * shipping
> * discount/coupon
> * payment/COD
> * totals
> * stock confirmation
> * idempotency
> * modifications/cancellation
> * reconciliation
> * compensating/failure paths
> 
> Commerce Platform Interface remains useful for read-side provider integrations where appropriate.
> 
> ## 12. CI constraint
> 
> Joy has chosen to wait for the GitHub Actions reset.
> 
> Until 1 October:
> 
> * use local exhaustive verification as the temporary merge gate
> * Pest
> * Pint
> * strict TypeScript
> * Vite
> * relevant production/build checks
> * deploy-safety tests
> 
> Never describe this as “CI green”.
> 
> Describe it as:
> 
> **local verification passed; GitHub Actions validation deferred until the 1 October reset.**
> 
> On 1 October:
> 
> * run CI against main
> * reconcile every discrepancy
> * fix failures rather than suppress them
> 
> ## 13. Gate 11 remains unchanged
> 
> Do not create Gate 12.
> 
> When Anthropic credit and monthly limit capacity are restored:
> 
> * verify the bypass returns HTTP 200
> * run exactly one fresh Gate-11 attempt 2 on release `3314c54`
> * preserve the provider-failure attempt as historical evidence
> * require zero unmeasured cases
> * do not tune before or after the run
> * stop and report the result
> 
> Do not activate the shadow merely because provider capacity has returned.
> 
> ## 14. Conditional A0 shadow
> 
> Joy has already given the conditional authorization.
> 
> If and only if the fresh Gate-11 attempt 2 fully passes:
> 
> * enable `ai.runtime`
> * activate Executive at A0
> * open the seven-day shadow period
> * schedule the approved Shadow Probes twice daily
> * keep Analytics paused
> * keep Support paused
> * grants remain zero
> * outbound remains OFF
> * Meta inbound remains OFF
> * business writes remain OFF
> 
> The Effective Execution Context must also govern all shadow-probe execution.
> 
> ## 15. Shadow evidence
> 
> During the eventual shadow week collect:
> 
> * trusted/canonical task
> * model interpretation
> * authority/security classification
> * effective execution context
> * proposals
> * permitted/executed calls
> * scope decisions
> * capability readiness
> * connector outcome
> * verdict
> * verification/postconditions
> * cost
> * provider/runtime errors
> * unnecessary tool calls
> * scheduled probe results
> * operator review
> * discrepancies
> 
> Do not widen autonomy automatically at the end of seven days.
> 
> ## 16. Production/code-state visibility
> 
> Add a read-only operational distinction wherever practical between:
> 
> * current production release
> * current main commit
> * unreleased main changes
> * last verified production release
> 
> Do not imply that code merged to main is production behaviour.
> 
> ## 17. Historical evidence
> 
> Preserve all prior gate results and operational traces.
> 
> Do not rewrite:
> 
> * Gates 1–10
> * Gate-11 attempt 1
> * prior provider failures
> * runner defects
> * connector failures
> * evaluation traces
> 
> Historical evidence remains immutable.
> 
> ## 18. What NOT to do
> 
> Do not:
> 
> * implement TaskScope v2 heuristics
> * weaken any existing safety gate
> * create new AI prompts merely to satisfy tests
> * activate Ask HQ
> * activate NL scheduling
> * activate Support
> * enable Meta inbound
> * enable outbound customer messaging
> * create autonomy grants
> * implement autonomous order writes
> * run Gate 11 until provider capacity is sufficient
> * call provider availability a credential-validity result
> 
> ## 19. End-of-milestone report
> 
> Report cumulatively:
> 
> A. M7 briefing bundle and lineage
> B. Effective Execution Context design and tests
> C. Ask HQ authorization correction
> D. autonomy evidence view
> E. M6 inert status
> F. Phase 3/P3-G1/P3-G2 progress
> G. Phase 4 ERP Sales Order design
> H. CI/local verification status
> I. Anthropic capacity status
> J. Gate-11 readiness
> K. production vs main release state
> L. exact items blocked on Joy
> M. current production safety posture
> 
> Continue the work without waiting for another daily instruction. Stop only at the next explicit capability gate or when Joy-only action is required.


## B. The plan (written before the code)

Four streams, one branch each, built in parallel in separate git worktrees with a separate test
database per worktree, each merged to main only on a full local verification recorded here as
"local verification passed; GitHub Actions validation deferred until the 1 October reset".

| Stream | Branch | Instruction points |
| --- | --- | --- |
| A. Briefing evidence bundle, unsourced-number check, lineage, the explicit Executive Briefing contract | `m7/briefing-evidence-bundle` | 1, 2, 6 |
| B. Effective Execution Context, applied to Ask HQ, the scheduler and the shadow probes | `cp/effective-execution-context` | 3, 4, 7, 10, 14 (last line) |
| C. Autonomy evidence view, read-only, with lineage | `obs/autonomy-evidence-view` | 5, 6 |
| D. Production, main and unreleased state, read-only | `obs/release-state` | 16 |

Rules every stream keeps: no prompt file changes; nothing is switched on; no flag default changes to
on; no scheduled job is created; no autonomy grant is created or changed; historical evaluation rows
are never rewritten; a test is watched failing before the code that makes it pass, where the change
is a correction (stream B).

**A note on the executive stamp.** Stream B necessarily touches how a run's authority is resolved,
so it is the first change since `3314c54` that may touch `AgentRunner`. Gate 11 attempt 2 runs on the
production release `3314c54` and is not affected by main. The release that follows gate 11 will carry
stream B, so the shadow week would run on a runner gate 11 did not measure. That is reported to
ChatGPT in the end-of-milestone report as a sequencing question (release before the shadow opens and
re-measure, or open the shadow on `3314c54` and release after), not decided here.

## C. Record

(filled in per stream as it completes)

### C.1 Stream A: the briefing evidence bundle, the unsourced-number check, lineage, the Executive Briefing contract (branch `m7/briefing-evidence-bundle`)

**The plan, written before the code (20 September).** Everything is deterministic and inert: no
prompt file, no flag row, no scheduled job, no seeder entry, and no change to `TaskScope`,
`TaskScopeResolver`, `TaskGrounding`, `AgentRunner` or the two detectors.

1. **The typed bundle (`App\Briefing`).** `EvidenceItem` carries a stable id, a kind, a section, a
   value and unit, the business, brand and store scope, a source reference, `as_of`, freshness
   (budget, age, state) and lineage. `EvidenceKind` defines `fact`, `metric`, `inference` and
   `recommendation`; the collector may produce only the first two, and the validator refuses a
   bundle that carries the narrator-side kinds. The bundle is not business truth: it is the bounded
   evidence surface a narrator may speak from, and the class says so.
2. **Lineage (point 6).** Every metric names its formula and the read-model rows it was computed
   from: the ids and each row's own source reference (store, order ref, last event id) when there
   are 50 or fewer, otherwise the id range, the count, a sha256 digest of the ids and the filter that
   selects them again. A rollup names the bundle items it sums. Money is summed in integer paisa
   from the very rows the lineage lists, never by a second query, so the number and its lineage
   cannot disagree.
3. **Content (docs/48 M.2, M.3).** Yesterday as a Dhaka calendar day (`hq.timezone`, the bounds taken
   in UTC, the way `QueryOrdersTool` and the retention runner do) per store, per business and for the
   group: orders placed, gross of orders placed (all statuses, named as such), and orders confirmed,
   delivered and cancelled in the day. What is scheduled today from the scheduler's own
   `next_run_at`. Anomalies: the latest integrity check, failed and dead job runs, dead events, open
   alerts, stale sources, and an order-volume band only where a store has at least 14 days and 30
   rows of history before yesterday (the floor `facts.query_orders` already uses); below that the
   section says "insufficient history" and no band exists. ERP low stock from the stored snapshots.
   Pending approvals with their clocks, shape only, never the payload. Marketing and finance render
   "not connected yet" with the reason. Source freshness is a top-level field, stale and missing
   first. A store HQ has no adapter for and a business no poll is scheduled for are `not_connected`,
   which is listed and is not a fault.
4. **Validation.** `BundleValidator` checks completeness (every contract section present, none
   silently empty), provenance (every item has a source, an `as_of` or a stated reason, and every
   metric has lineage that resolves) and freshness. Any failure is `degraded` with coded reasons.
5. **The unsourced-number check.** `NarrativeNumberCheck`, strict and documented in the class: every
   digit run in the narrative is a figure, including one attached to letters; there is no whitelist
   for small counts or ordinals; equality is exact after normalising separators and trailing zeros,
   so a rounded figure fails; a percentage needs a percent item and a currency figure a BDT item;
   Bangla digits are read; scale words (k, thousand, lakh, crore and the Bangla words) multiply and
   must still match exactly; a date or time must equal a date or time the bundle carries; a sum of
   two items fails unless that sum is itself a metric.
6. **The contract (point 2).** `ExecutiveBriefingContract`: capability `executive.briefing`, a fixed
   trusted task owned by HQ, input of scope and date, evidence surface exactly the bundle version,
   the narration flag, the sections. It is code and data, with no natural-language mapping. The
   Phase 2.5 Capability Registry (`App\Platform`) describes what a connected external account can
   do, so it is not where an HQ capability belongs; the contract is registered in `config/hq.php`
   under `briefing`, beside the tool list. `briefing.compose` goes through the normal registry and
   gateway, collects, validates and stores the briefing.
7. **Narration seam.** `BriefingNarrator` behind a new flag `briefing.narration`, absent and so off
   (`Features::enabled` answers false for a missing row). With the flag or `ai.runtime` off no
   narrator is resolved and the briefing is stored evidence-only. No model-calling narrator is
   built, because that needs a prompt; tests use a fake to prove the number check gates what is
   stored.
8. **Storage and retention.** One additive, reversible migration: `briefings`, append-only, kept
   forever (`RetentionPolicy::PERMANENT`), with a nullable `ai_run_id` whose model call is kept by a
   new keep condition, so the retention schema test stays green for the right reason.
9. **The page.** `/observe/briefings` and `/observe/briefings/{uuid}`, read-only, behind the existing
   `hq.tools.facts.read`, inside the viewer's business scope.

**As built (20 September), and where it differs from the plan.**

- **The bundle (`App\Briefing`).** `EvidenceBundle` (`briefing.bundle.v1`) holds the sources with
  their freshness first, then the eight contract sections, then the items. `EvidenceItem` carries id,
  kind, section, label, value, unit, scope (business, brand, store), source (`system`, `read_model`,
  `ref`, and the row id where it is one row), `as_of`, freshness (state, budget, age, `in_future`),
  lineage, a quotable `subject` and non-quotable `details`. The class docblock says what ChatGPT
  said: it is not business truth, it is the bounded surface a narrator may speak from. The collector
  produces `fact` and `metric` only; the validator refuses the other two kinds.
- **Sources (`Sections\SourceReader`).** Order facts per active store: as current as the later of
  the newest `synced_at` and the last succeeded `commerce.reconcile_facts` call for the store,
  because `synced_at` moves only when an order is written and a quiet shop would otherwise look
  stale. ERP low stock per ERP business: the older of `as_of` and `polled_at`. The latest succeeded
  integrity check. Budgets are 1,560 minutes (the 26 hours the snapshot alert uses, since the polls
  and the reconciliation are daily) and 15 minutes for an open alert's last sighting. A store with
  no adapter and no facts, and a business with no low-stock poll scheduled, are `not_connected`:
  listed, not a fault. An `as_of` more than five minutes in the future is stale, never fresh.
- **Yesterday.** Per store: orders placed, gross of orders placed (every status, and named so),
  orders confirmed, delivered and cancelled by their own timestamps, in the Dhaka calendar day with
  UTC bounds. Business and group totals are `sum_of_items` metrics. A store HQ has never written
  produces no figure and a note, and no business or group total is made over the stores that did
  report. Money is summed in integer paisa from the listed rows.
- **Scheduled today** from `scheduled_jobs.next_run_at` (active and approved); `not_applicable` on a
  past date. **Anomalies:** integrity discrepancies and facts checked, failed and dead job runs since
  the start of yesterday, dead events, open alerts, the count of degraded sources, and the band. The
  band is the lowest and highest daily count of the 14 Dhaka days before yesterday, given only when
  the store's facts reach back over all 14 days and hold at least 30 orders in them; otherwise an
  `insufficient_history` note and no number. **Inventory:** the low-stock count per business and,
  for the first 20 lines, on hand and threshold. **Approvals:** pending and overdue counts, and per
  approval whole hours waited and the deadline; never the payload, reason, evidence or preview.
  **Waiting on a decision** points at the pending approvals and at the integrity item when it found
  something; it adds no item. Marketing and finance are `not_connected` with the reason.
- **Lineage.** `Lineage::ofRows` (formula, table, ids with each row's source reference up to 50 rows,
  otherwise range, count, sha256 of the ids, sha256 of the source references, and always the filter),
  `ofItems` for rollups, `ofSources` for the degraded-source count. The test "walks one number"
  goes from `orders.gross.group` to the business items, to the store item, to the `facts_orders` ids,
  re-reads those rows to the same paisa, and ends at the shop's order numbers and last event ids.
- **Validation (`BundleValidator`).** Two states only. Completeness: every contract section, no
  silent section, every listed item exists, every item is listed, no duplicate id, a withheld
  section is a named gap. Provenance: evidence kinds only, source and unit and `as_of` present, every
  metric has lineage that resolves, and the value is recomputed where the bundle alone allows it (a
  row count against its rows, a sum of items against those items, in paisa for money). Freshness:
  every stale or missing source, a future `as_of`, and an item stale on its own account. Provenance
  and completeness faults block narration; freshness faults do not, because a briefing that says
  what is stale at the top is what M.3 asks for.
- **The number check (`NarrativeNumberCheck`, rule `briefing.numbers.v1`).** As planned, plus three
  things found while attacking it: a digit of a script the check cannot read is reported, never
  passed; the English words two to twenty and the tens are read as figures and a bare magnitude
  ("hundreds of orders") is unsourced, while "one" is not read; and an item's `subject` (a SKU, a
  uuid, a job name) may be quoted whole so its digits are not figures, but a subject that is only a
  number whitelists nothing. The class states what it does not prove: that the sentence around a
  figure says the right thing about it, and Bangla number words and month names.
- **The contract (`ExecutiveBriefingContract`).** Capability `executive.briefing` 1.0.0, the fixed
  `TRUSTED_TASK` (three sentences, a constant in code, not a prompt file), input `business_slug` and
  `date` with `additionalProperties: false`, evidence surface `briefing.bundle.v1`, registered at
  `config('hq.briefing.contract')`. The gateway refuses a `question`, `task` or `prompt` argument
  before the tool runs. A test reads the source of the whole namespace and fails if it ever names
  `TaskScope`, `TaskScopeResolver`, `TaskGrounding`, `AgentRunner`, either detector, `PromptLibrary`
  or the model router.
- **`briefing.compose`.** Registered in `config('hq.tools')` and run through the normal gateway.
  **Deviation from the brief, which said read-tier: it is R3 and `ReadWrite::Write`,** because it
  writes a `briefings` row and the house rule is the highest tier of any effect (`erp.poll_*` is R3
  for the same stated reason). R3 is still automatic, with no approval. Not agent-executable.
  Permission `hq.tools.facts.read`; the approvals and scheduler sections are read only when the
  caller also holds `hq.approvals.view` and `hq.jobs.view`, and are otherwise `withheld`, which
  degrades the briefing. The output is the verdict, not a second copy of the bundle.
- **Narration.** `BriefingNarrator` with `NoNarrator` bound. `BriefingComposer` resolves a narrator
  only when `briefing.narration` and `ai.runtime` are both on; states `off`, `runtime_off`,
  `blocked`, `unavailable`, `empty`, `rejected`, `narrated`. A rejected narrative is kept inside
  `narrative_check.rejected_narrative` and never in `narrative`; each unsourced figure is a degraded
  reason that names it. A narrator's exception message is never stored. No model-calling narrator
  was built.
- **Storage and retention.** Migration `2026_09_20_100000_create_briefings_table` (one new table,
  `down()` drops it). `Briefing` refuses update and delete. `briefings` is in
  `RetentionPolicy::PERMANENT`; `briefings.ai_run_id` is in the schema test's list and the `ai_runs`
  keep condition names it. One logical composition is one row (unique `idempotency_key`), and
  `reconcile()` finds it.
- **The pages.** `/observe/briefings` and `/observe/briefings/{uuid}` (`BriefingController`, GET
  only, `hq.tools.facts.read`). The detail page shows stale and missing sources first, the degraded
  reasons, the narrative state, every source, and per section every item with kind, value, scope,
  source, `as_of`, freshness and lineage; the stored bundle's digest is recomputed on every view.
  A person limited to some businesses sees a briefing only when it was composed for one of their
  businesses or under a restriction inside their own.
- **Not created:** no scheduled job, no seeder entry, no cron, no flag row, no prompt file, no grant.
  A test seeds the whole database and reads every seeder, migration and `routes/console.php` to
  prove it.

**Found on the way, not fixed.** `tests/Feature/Facts/OrderUpdatedTest.php` calls `signedPost()`,
which is declared in `tests/Feature/Identity/ShopWebhookAndIdentityTest.php`, so the Facts folder
fails one test when run alone and passes with Identity or in the full suite. `prettier --check`
reports six files this stream did not touch (`app-header.tsx`, `agents/show.tsx`,
`credentials/index.tsx`, `inbox/index.tsx`, `inbox/show.tsx`, `ssr.jsx`).

**Verification, the merge gate until 1 October.** Pest, folder by folder, `DB_DATABASE=hq_test_a`:
Agents 106, Ai 53, Ask 8, Auth 15, Autonomy 14, Commerce 8, Consent 8, Conversation 70, Briefing 61
(bundle 17, number check 15, validator 8, tool and contract 18, pages 3), Erp 18, Evaluation 176,
Identity with Facts 38, Foundation 14, Gateway 174, Media 7, Observe 40, Platform 21, Scheduler 34,
Security 24, Settings 6, Dashboard 5, Unit 1: **901 passed, 0 failed** (6,674 assertions in one full
run; 840 on the base, 61 new). Pint `--test` passed; `tsc --noEmit` clean; `vite build` succeeded.
**Local verification passed; GitHub Actions validation deferred until the 1 October reset.**

