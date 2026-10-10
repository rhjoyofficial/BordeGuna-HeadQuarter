# 110. Wave 1 report: S1, cost attribution, S3, S7, S2 merged to main (10 October 2026)

The report `docs/103-chatgpt-ruling-2026-10-10.txt` §11 asked for. Five streams were built in parallel on the 10 Oct ruling (docs/101 §13), each in its own worktree and test database, then merged `--no-ff` into main and gated on the combined tree. Nothing is released; production stays `b96a396`.

## 1. Commits
| Stream | Branch | Head | Merge | Record |
|---|---|---|---|---|
| S1 safety preconditions | `platform/s1-safety-preconditions` | `53900ca` | `35246dd` | docs/105 |
| Evaluation cost attribution | `platform/eval-cost-attribution` | `ee1412b` | `48fbe7e` | docs/106 |
| S3 canonical outcome model | `platform/s3-outcome-model` | `f1d2e77` | `d6eaf66` | docs/107 (contract D1-D19, Q1-Q11) |
| S7 consent enforcement | `platform/s7-consent-enforcement` | `dced87e` | `4d0e8e9` | docs/108 |
| S2 Customer 360 | `p3/s2-customer-360` | `ff12d70` | `5628496` | docs/109 |
| Merge fix | main | `a8a6410` | | this doc §3 |

Main = **`a8a6410`**, pushed.

## 2. Tests and CI
- Each branch alone: full suite green (1,857 / 1,857 / 1,875 / 1,863+34 / 1,857), Pint, `tsc`, Vite where frontend changed.
- **Combined tree, first run: 1,940 passed, 1 failed.** The failure was cross-stream and invisible to any single branch: S2's Customer 360 test pins the set of write routes under `/customers`; S7 added `POST customers/{customer}/consent`. Fixed in `a8a6410` by naming the S7 route in the pin deliberately and comparing sorted. (Also: S2 and S7 both edited the customer page, the Bangla dictionary and its registry test; resolved by keeping both, 7 identical dictionary keys deduplicated, S2's page with S7's Consent history button.)
- **Combined tree after the fix: 1,941 passed, 0 failed, 25,264 assertions.** Pint passed, `tsc --noEmit` clean, `vite build` clean.
- **GitHub Actions run 38029016996 on `a8a6410`: CI_RESULT_PLACEHOLDER**

## 3. S1: safety preconditions (docs/105)
- `SupportWakeUp::settle()` now settles an executed reply: accepted only when the gateway's `tool_calls` row confirms `messaging.send_reply` succeeded/replayed for this thread; records `conversation.reply_sent`; thread goes to `ai_waiting`; an `uncertain` delivery or an unconfirmed record goes to a person with the reason. The A1 cap is NOT lifted (test: a grant and ceiling of A3 still resolve to A1, reply stays a proposal, no outbound).
- "Effectful" = a write or risk tier R3+, one method `ToolDescriptor::hasEffect()`. The eight: `demo.request_approval`, `erp.poll_overview`, `erp.poll_low_stock`, `commerce.reconcile_facts`, `briefing.compose`, `commerce.take_order`, `messaging.send_whatsapp_reply`, `commerce.cancel_sales_order`.
- Three places stopped short of the gateway, all closed: (a) the agent runtime refused before asking the gateway; now the gateway is asked read-only at every level and its verdict is recorded (`agent_run.effectful_call_refused`); (b) **the scheduler bypassed the gateway entirely when `scheduler.gateway` was off or unseeded** (a scheduled R4 write would have run with no approval, policy, consent, idempotency key, tool_calls row or audit); effectful tools now always go through the gateway; (c) `hq:facts:reconcile` wrote directly; it now runs the tool through the gateway as a console caller.
- Structural test `GatewayCoverageStructureTest`: only `ToolGateway` may invoke a tool; every other call site is on a reviewed list; services behind effectful tools are reachable only from their tool; the nine effectful tools are pinned.
- Production: `scheduler.gateway` is ON (read 10 Oct), so behaviour there is unchanged by (b).

## 4. Evaluation cost attribution (docs/106)
- Root cause: nothing ever wrote `evaluation_runs.cost_usd`; every evaluation run ever recorded shows 0. Second defect: a result linked one arbitrary `ai_runs` row, so even a linked sum would miss multi-turn cases and retries.
- Fix: cost from every `ai_runs` row sharing the run's correlation id (turns, repairs, fallbacks, failed attempts), each counted once, idempotent. New columns `cost_estimated_usd`, `cost_provider_usd` (NULL today: HQ records no provider figure; nothing invented), `cost_attribution` (jsonb, completeness and errors), `evaluation_results.correlation_id`. `cost_usd` = per call COALESCE(provider, estimate), the convention `AiBudget`, `CostDashboard` and `ShadowEvidence` already use. A case with no evidence is unaccounted, never guessed. An attribution failure is recorded and cannot change a verdict (tested). Backfill `hq:evaluation:attribute-cost` is dry-run by default and writes complete runs only. 10 tests, 9 watched failing first.

## 5. S3: canonical outcome and attribution model (docs/107, FOR CONTRACT REVIEW)
- Three narrow tables: `outcomes` (one row per business result; business required, brand/store optional and owned by the business; type, state asserted/retracted; occurred/source-as-of/recorded; source, system, ref, provenance; evidence as ids and digests; idempotency key; supersedes), `outcome_result_refs` (typed FKs to facts_orders / conversation / message / publication plus source_system + external_ref; CHECK matches result_type), `outcome_attributions` (composite FK `(outcome_id, business_id)` so a cross-business attribution is impossible; eight typed action FKs with CHECK; role produced/proposed/approved/contributed; method closed list with confidence rules; supersedes).
- No amount, currency, status or lines column anywhere; money is read from the system of record at query time.
- Append-only triggers refuse UPDATE/DELETE on all three; every FK RESTRICT; corrections are successor rows with a reason, audited; retraction final.
- One live-path touch: retention keeps any tool call, model call or workflow run an attribution points at (keeps more, never less; matches nothing while empty). Tables added to `RetentionPolicy::PERMANENT`.
- Inert illustrative recorder `ErpOrderPlacedRecorder` (tests only). 27 tests. Decisions D1-D19 and questions Q1-Q11 are in docs/107 §12 for one-by-one ruling before S4 builds on it.

## 6. S7: consent enforcement (docs/108)
- Resolution is per purpose × channel × brand (`ConsentLedger::permittedAt()`); denials first: do-not-contact > withdrawn > expired; opt-in required for every purpose but service; an inbound message can never create a marketing grant (the ledger refuses to write one); a brand-scoped denial also stops a brandless send, a brand-scoped grant never covers one. New state `lifted` ends a restriction and grants nothing.
- **Enforced in the Tool Gateway** (`ToolGateway.php` step 5b) before idempotency, credentials and execution, refusal reason `consent refused: [<class>]`, outcome class `consent_denied`, audit `consent.refused` naming the deciding event; the inbox reply path (`OutboundPolicy`) gives the same answer. Classes: `consent_do_not_contact`, `consent_withdrawn`, `consent_expired`, `consent_opt_in_missing`, `consent_not_recorded`, `consent_subject_unresolved`.
- 9 gateway-level tests (withdrawal refused; marketing without opt-in refused then allowed after operator opt-in; wildcard grant ≠ opt-in; marketing withdrawal leaves service; DNC beats a later opt-in, operator consent, a fresh inbound, a human operator and OutboundPolicy; brand isolation; lift; read-only authorize refuses identically and writes nothing; unresolved subject).
- Operations: withdraw, do-not-contact, marketing opt-in, operator consent, lift; evidence_ref + reason required, no future dating. Permissions `hq.consent.view` / `.restrict` / `.grant` (support_agent: view + restrict). Screen `customers/{customer}/consent` (what a send would get now; current position; history; one form). Migration adds `consent_state.brand_id`. **Release note: `db:seed --class=RolesAndPermissionsSeeder --force` at release.**

## 7. S2: Customer 360 (docs/109)
- `Customer360Reader` (SELECT only, every query bound to customer AND business). Page sections labelled by kind: observed; inferred by HQ (with resolver rule and confidence); source-of-truth order copies with `owner_system` and copy age; conversations; hand-overs; memory with provenance; consent (ledger + projection, legacy blob apart); promises (nothing recorded); outcomes (placeholder awaiting S3). Inbox-derived sections only with `hq.inbox.view`.
- Two guards tightened: customer index/show/merge/dismiss are business-scoped (404 outside); `IdentityResolver::merge()` throws `CrossBusinessMerge` for two businesses. One phone in LaLaDia + Bionic = two customers, nothing of one on the other's page (tested).
- No-write snapshot test over 17 tables, identical after two renders. No migration, no new permission, no new write route.

## 8. Decisions needing a ruling
**Cost:** (a) headline `cost_usd` = COALESCE(provider, estimate) per call as built, or estimate only? (b) backfill #45, #46 and earlier on production after release (dry run first; Joy's call)? (c) a run that throws inside `AgentRunner::run()` loses its pre-throw calls from the count (separate change).
**S1:** (d) `conversation.reply_sent` stays the settling event, or folds into the real outbound adapter's event at P3-G4? (e) after an executed reply the thread is `ai_waiting`, so the Q1 rule (second inbound → person) also applies there; confirm, or rule a different state for when the A1 cap lifts. (f) retire scheduler direct mode entirely and seed or remove `scheduler.gateway`? (g) if the gateway would authorize what a runtime check refused, the refusal stands and audit records `gateway_would_authorize: true`; audit-only enough, or alert?
**S3:** docs/107 §12 Q1-Q11 (three-ledger shape; typed FKs vs polymorphic; who owns `rule_window` rules and S3 vs S4; credit share; first detail table; attribution to HQ-scope actions; explicit scope vs global scope; backfill of historical ERP orders; RESTRICT and future erasure; frozen as-attributed values; operator-asserted attribution as a gateway tool).
**S7:** (h) after a business-wide service withdrawal, does the customer's own new inbound reopen service replies? Built: no, only a lift. (i) per-identity scope needed? (j) confirm support_agent = view + restrict, never grant. (k) lifting a DNC: operator evidence + `hq.consent.grant`, or a second approver?
**S2:** (l) where do promises come from (S4/S7 store, or person-recorded)? (m) pre-existing cross-business merge rows: flag only (current) or undo? (n) inbox-derived sections behind `hq.inbox.view`, or a dedicated permission?

## 9. Next
Per the ruling order: S5 (decision-corpus reader) and S6 (playbook store) now; S4's inert contracts now and its outcome-dependent part after the S3 review and S7's checks (both present). Release of this wave is Joy's call; it carries 4 additive migrations (cost columns, outcome tables, consent brand) and the permissions seeder.
