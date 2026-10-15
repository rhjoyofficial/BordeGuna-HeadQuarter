# 125. Wave 4 small items (W5), 15 October 2026

Workstream W5 of `docs/120-wave-4-plan-2026-10-15.md`, under the binding ruling `docs/120-chatgpt-ruling-2026-10-15.txt` section B. Branch `platform/wave4-small-items` from main `5419493`, worktree `hq-wt/j`, test database `hq_test_j`. Nothing here activates anything; no production access; Joy alone releases.

## Plan (committed first, `8c4723c`)

Groups, each closed by a WIP checkpoint commit:

1. **S3 (items 1-7, 30-32, 35).** Item 1: a typed foreign key stays required for every internal result type; an `erp_order` reference may go without its row only while HQ holds no mirror of that ERP order, and is refused when a mirror exists. Items 2-7 and 30-32: verify in code, add a test wherever a ruled property is not yet pinned. Item 35: test the append-only correction and retraction semantics; pin that no callable retract tool exists.
2. **S5 (items 18-21, 34).** A separate, labelled "what I would have sent" box on the shadow review that sends nothing. Reasons stay off the corpus page and stay in the record and the audit. One vocabulary `approved|edited|rejected` for every new decision record, with a documented compatibility map for legacy `send`/`discard`; history is not rewritten. Additive `decision_records.business_id`, backfilled only from a context row that names a business; the rest stays null and is reported; a null never widens a read.
3. **S6 (items 22-26).** Reviewer independence with a visible single-person bootstrap rule; legacy columns untouched; business-wide and brand playbooks both load, labelled, brand refining and never widening, a conflict surfaced and not inferred; Support runs load playbooks read-only and gain nothing.
4. **S7 (items 27-29).** Verify fail-closed identity, single-person plain lift with evidence and audit, two-person DNC lift, and the spent-approval rule, with tests.
5. **Cost and bypass (items 40-42).** The `scheduler.gateway` row is not deleted; the post-release delete procedure is written below. Counter and audit kept when alerts dedupe; threshold 3 in 24 h; no customer content or credentials in the alert.
6. **W5-TZ.** Shop timestamps stored in UTC whatever offset the shop sends; the contribution report's Dhaka day windows converted to UTC before they reach Postgres. Tests with a `+06:00` payload and a day-boundary order.

Item 33 is design-only (not built). Item 23 is held by the existing structural test, now also pinned at the table name.

## Record, per item

### S3, outcome ledger

- **Item 1 (D4), BUILT.** The ruling changed our recommendation: the typed key is required, not a convenience. The database already refused a `shop_order`, `conversation`, `conversation_message` or `content_publication` reference without its column (CHECK `outcome_result_refs_typed_target`), and the recorder already refused one without its row. The one gap was `erp_order`, which may go by number alone. `OutcomeRecorder` now refuses an `erp_order` reference without its row when HQ already holds a read-back of that ERP order in the same business (`facts_orders.erp_order_number`). The natural key stays mandatory (NOT NULL, unique per outcome, 1 to 191 characters) and stays the idempotency source. Tests: mirror present refused, mirror absent allowed, pointing at the mirror accepted; every internal type refused at the database without its column; empty natural key refused at the database and the recorder.
- **Item 2 (D7), verified with test.** No proposal table, no `proposal` action type; `proposal_ref` lives on the attribution and only with an agent run (existing test).
- **Item 3 (D10), verified with test.** Every registered type matches `order|conversation|publication.<event>`, is no registered tool key and names no provider or HQ action word.
- **Item 4 (D11), verified with test.** Every row carries `schema_version`; the digest includes it, so redefining a type as v2 makes the same key a conflict instead of reinterpreting the v1 row; a v2 row needs its own key.
- **Item 5 (D12), verified with test.** The caller's key is stored as given; a retraction derives `retract:{key}`; a replayed retraction returns the same row; the recorder mints no key.
- **Item 6 (D17), verified with test.** Current view = asserted rows with no successor (with item 35).
- **Item 7 (D18), verified.** `RetentionTest` already keeps a tool call, model call and workflow run an attribution credits, and the three ledgers are permanent.
- **Item 30 (D14), verified with test.** A first `record()` and `attribute()` write no audit row; the ledger row is the record. The gateway invocation of `outcomes.attribute` still writes its `tool_calls` row (existing `AttributeOutcomeToolTest`).
- **Item 31, verified with test.** `conversation.resolved` stays in the S3 registry (domain conversation, subject conversation); no ledger table carries a resolution category column.
- **Item 32, verified.** `outcomes.attribute` is R3, human-only, held by no agent, records actor, reason and evidence (existing `AttributeOutcomeToolTest`). No second approval in the single-operator posture; revisit before attribution feeds autonomy or money.
- **Item 33, DESIGN-ONLY.** The explicit HQ-scope shape of `docs/115 §3.3` is accepted in principle and not built. An action with no business stays refused as `ImplicitHqScopeRefused`; `business_id = NULL` is never read as HQ scope.
- **Item 35, verified with test (no tool).** Corrections and retractions are successor rows; a superseded row cannot be retracted or corrected again (conflict, nothing written); retracting the current row leaves the first row byte for byte and its attribution in place; a retraction is final; the same retraction with a different reason is a conflict. No registered tool key names retract, supersede or correct, and nothing outside `app/Outcomes` calls the correction methods.

### S5, decision corpus

- **Item 18, BUILT.** The shadow review (`/agents/runs/{run}`) has a separate dashed box, "What I would have sent", labelled "Proposal only. Never sent." with a line saying it sends nothing, reaches no customer and is not a message. It appears only for `edited` or `rejected` (refused on `approved`). The text goes into the decision record (`final.would_have_sent`, `final.would_have_sent_executed = false`, `delta = {from: answer, to: suggestion}`) and nowhere else: no tool call, approval, message, delivery, thread or workflow row, no HTTP call (all asserted). The audit row says only that a suggestion exists. The run page shows it back in the same labelled box, apart from the run's answer. The corpus reader now classifies a shadow review that has the text like a reply edit, so it no longer counts as "edited without text".
- **Item 19, verified with test.** The reason never appears on the corpus page; it stays in `decision_records.reason` and in the `agent.run_reviewed` audit reason.
- **Item 20, BUILT.** `App\Decisions\DecisionVocabulary` is the one vocabulary and the documented compatibility map: `discard` reads as `rejected`; `send` reads as `edited` when `final.edited` is true (or, absent that, when a delta exists), else `approved`; any other legacy word reads as edited with a delta, approved without. `DecisionRecord` refuses any other word on create. The inbox writer now stores the verdict (`approved`/`edited`/`rejected`) and keeps the button pressed in `final.decision`. Legacy rows are not rewritten (test: a stored `discard` stays `discard` and reads as rejected).
- **Item 21, BUILT.** Migration `2026_10_15_510000_add_business_id_to_decision_records` adds nullable `business_id` (FK RESTRICT, index with `created_at`) and runs `DecisionBusinessBackfill`, which assigns a business only when the context row (approval by id, agent run or conversation by uuid) exists and itself names one, and never overwrites a set value. Every writer now stores its context's business (approval, run, thread; a group-wide run stays null). `hq:decisions:report --unassigned [--json]` lists every null row with its reason: context row gone, context row has no business, unknown context type, or written without it after the backfill. Read-only.
- **Item 34, verified with test.** The corpus reader takes the record's own `business_id`, else its context's; a row resolving to no business is counted for a group-wide viewer only, never for a restricted one. `OutcomeRecorder` now reads `decision_records.business_id` for a decision-record action: a record with a business may be credited, one without is refused as an implicit HQ scope.

### S6, playbooks

- **Item 22, BUILT.** `Playbooks::review()` refuses approval by the drafter while another eligible reviewer exists (enabled, holds `hq.playbooks.review`, may act on the version's business). Sending back is never refused on this ground. **The bootstrap rule:** when the drafter is the only eligible reviewer, self-review is allowed and recorded in a `playbook.self_review_bootstrap` audit row with the rule text and the count of other eligible reviewers (0), and the version page shows "Review rule: Reviewed by its drafter under the single-reviewer bootstrap rule". The same call is refused as soon as a second eligible reviewer exists. No new column.
- **Item 23, verified with test.** `PlaybookStructureTest` already pins the one runtime reader (`ContextAssembler`) and the model names; a new test pins the table names too, so a raw `DB::table('playbook_versions')` reader would fail the suite.
- **Item 24, verified with test.** `content_briefs.claims_policy` and `brands.guidelines` are untouched and unread by the playbook code. Retirement stays a planned, documented, manual step when a playbook replaces them.
- **Item 25, BUILT.** Both load, labelled. A brand version of a kind that also has an active business-wide version carries `refines` in the run record, the pair is listed under `overlaps` with `brand_refines_business_wide`, and its block says it refines version N for this brand only and never widens a permission or overrides a policy. A refinement never loads without its base: if the business-wide one is skipped for budget, the brand one is skipped as `base_not_loaded`. The framing block tells the model that when the two contradict and it cannot tell which reading satisfies both, it must say the playbooks conflict and leave the point to a person, not pick one. Text conflicts are not judged by code (there is no deterministic way to), so nothing is inferred. The brand version page shows "Precedence: refines business-wide version N".
- **Item 26, verified with test.** A Support wake-up on a LaLaDia thread loads the brand playbook (its text reaches the model) and the run stays A0, shadow, a proposal only, with no reply tool call, delivery or outbound message.

### S7, consent

- **Item 27, verified with test.** An unresolved identity gets no grant from another identity or another business (refusal `consent_not_recorded`), and a brand-level withdrawal binds both the unresolved and the resolved identity.
- **Item 28, verified with test.** A plain withdrawal lift is single-person under `hq.consent.grant`, refused without an evidence reference or a reason, audited with the evidence reference, never parked. A support agent cannot lift. A do-not-contact still needs the separate permission and a second approver (existing tests).
- **Item 29, verified with test.** An approval presented to an approver without `hq.consent.lift_restriction` is consumed; a properly permitted approver then cannot replay it (nothing lifted, the do-not-contact stands); a fresh request decided by a second holder is what lifts it.

### Cost and bypass

- **Item 40, NOT DELETED; procedure recorded.** The `scheduler.gateway` feature-flag row stays on production. Post-release procedure, Joy's act, only after the release is verified:
  - Confirm the deployed commit is the release candidate (`release.json` and the health endpoint name it).
  - Confirm no consumer in the deployed code: inside the app container, `grep -rn "scheduler.gateway\|scheduler_gateway" app config routes` returns nothing (the `GatewayCoverageStructureTest` pin holds the same in CI).
  - Confirm the scheduler runs through the gateway: recent scheduled `tool_calls` rows exist with gateway policy results.
  - Keep the release for at least one verified scheduler cycle, because a rollback to code before docs/116 reads a missing row as OFF and would use the old direct path.
  - Then delete: `docker compose -f deploy/docker-compose.yml --env-file deploy/.env exec -T app php artisan tinker --execute="App\Models\FeatureFlag::where('key','scheduler.gateway')->delete(); Illuminate\Support\Facades\Cache::forget('hq:feature_flags');"`
  - Verify: the Flags page no longer lists it and the next scheduler tick still writes gateway tool calls.
- **Item 41, verified with test.** A guard refusal the gateway would allow stays refused, audited and counted; nothing runs.
- **Item 42, verified with test.** Five disagreements by one agent: two give no alert, the third opens one, the fourth and fifth are deduplicated into it. The counter still reads 5, all five `agent_run.effectful_call_refused` audit rows exist, `alert.opened` is written once. The alert's subject, body and context carry only agent key, count, window and threshold: no tool argument, conversation id, question text or the configured bot token.

### W5-TZ, time zones

- **How the shops send timestamps.** The captured live `order.created` payload (`tests/Pest.php`, `liveWebhookOrderPayload`) and the OrderResource fixtures send Laravel's JSON form, `2026-09-06T14:49:14.000000Z`. The status listeners send `changed_at`, and the ERP read-back sends `+06:00` strings (`docs/45` E.3). Both must land on one instant.
- **The defect.** Eloquent writes a date as `Y-m-d H:i:s` in the Carbon's own zone and drops the offset; the session zone is UTC. So a `+06:00` value parsed with `CarbonImmutable::parse()` was stored six hours late. Separately, `FactsOrdersDeliveredSales` bound Dhaka-local day edges without an offset, so Postgres read them as UTC. For `+06:00` payloads the two errors happened to cancel; for `Z` payloads, which is what the shops actually send for orders, a delivery between 00:00 and 06:00 Dhaka was counted on the previous day.
- **BUILT.** `FactsOrderUpserter` converts every shop timestamp (`placed_at`, `confirmed_at`, `delivered_at`, `cancelled_at`, status-event `at`/`changed_at`/`delivered_at`, and `last_event_at`) to UTC before storing. `FactsOrdersDeliveredSales` converts its bounds to UTC before the query. A zone-less string is still read in the app zone (UTC), as before.
- **Tests** (`tests/Feature/Facts/ShopTimestampZoneTest.php`): a `+06:00` and a `Z` payload of the same instant store the same UTC value; a `+06:00` `changed_at` lands on its instant; four deliveries around the 20 Sep Dhaka day (first second, last second, 00:30 next day as `Z`, last second of the day before) give exactly the first two, and the contribution report counts 2 orders and 300.00. Both tests fail on the old code (checked by restoring it).
- **Not repaired.** Existing production `facts_orders` rows written from `+06:00` strings (status events, ERP read-back) are six hours late. No data is rewritten here. A read-only count Joy may run: `SELECT count(*) FROM facts_orders WHERE source_system = 'erp';` and a comparison of `delivered_at` against the shop's admin for a few status-event deliveries. Any repair is a separate, authorized step.

## Migrations

- `2026_10_15_510000_add_business_id_to_decision_records` (additive; backfill from reliable context only; rollback drops the column).

## Deviations and notes for the merge

- Files shared with the identity agent's workstream, touched in small, separate hunks: `InboxController::decide()` (business and vocabulary on the decision record; `ordersFor()` untouched), `FactsOrderUpserter` (time parsing only; the `resolve()` call untouched), `tests/Feature/Conversation/SupportWakeUpTest.php` and `tests/Feature/Consent/ConsentLedgerTest.php` (one appended test each; the item 27 test uses the existing `consentIdentities()` helper, which the identity change will need to give a business).
- `tests/Feature/Decisions/DecisionCorpusReaderTest.php`: the legacy `send`/`discard` fixture now writes past the model's events, because the model refuses those words for new rows.
- `playbooks/show.tsx` was not Prettier-clean on main; only the new lines were added, the file was not reformatted.

## Gate

- Full Pest suite, `DB_DATABASE=hq_test_j php artisan test --parallel --processes=4`: **2,077 passed, 0 failed, 31,210 assertions** (main `5419493` had 2,047; 30 new tests).
- Pint (whole app): passed. `npx tsc --noEmit`: clean. `npx vite build`: built. Bangla dictionary test green with the 8 new entries (NFC, English digits, no em dash).
- Migration rolled back and re-applied on `hq_test_j`: both clean.
- W5-TZ and item 1 tests watched red on the old code.

## Commits

- `8c4723c` plan; `5144ce6` S3; `641e6bd` S5 + S6; S7 tests; items 41-42 tests; `5466127` W5-TZ; this record.
