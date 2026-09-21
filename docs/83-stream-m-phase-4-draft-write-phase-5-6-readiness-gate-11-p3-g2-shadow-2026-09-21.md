# 83 - Stream M, the Phase 4 draft write built inert, the Phase 5 and 6 readiness packets, then Gate 11 attempt 2, P3-G2 and the shadow week (21 September 2026)

**Status:** the plan for the milestone ChatGPT ordered on 21 September (13:10 Dhaka) in answer to the
`docs/81` section E report. Production is `87d8658` with a verified release record (`docs/81` D.4),
inert. This document is written before the code; each stream adds its record under section C.

**Read first:** `docs/81` sections D and E (the candidate, where the release steps stand, the report
this answers), `docs/79` and ADR-046 (Phase 4 design, which this milestone starts to build),
`docs/70` and `docs/82` (prerequisites and what the owner must buy or register).

## A. The instruction, verbatim

ChatGPT's reading of the report, in short: Stream M merges first (a deploy lock and fresh preflight
checks before the gate); the Phase 4 draft write is built now as the future order-execution contract,
the ERP staying the only writer, with nothing after the draft (no confirmation, reservation, invoice,
payment, fulfilment, notification or posting); the Phase 5 and 6 documents are readiness packets, not
implementation; Gate 11 attempt 2 runs before P3-G2 and stays its own measurement; P3-G2 runs after a
passing Gate 11 and before the shadow week, as pre-shadow validation, never mixed into Gate 11's
score; the shadow week is not a test bed for P3-G2. Governance note: the credit-bearing OpenAI key
should belong to an organisation-owned account, not a personal one, and the exact key that passes
readiness is the one the gate uses. The instruction itself:

> We are continuing BordeGuna HQ from the 21 September cumulative report.
>
> Treat the current architecture, ADRs, roadmap, existing contracts, safety gates, EEC, Tool Gateway, capability registry, autonomy resolver, briefing bundle, and Phase 3/4 decisions as settled. Do not redesign previously-decided architecture. Make only the changes required by this milestone.
>
> The required sequencing is:
>
> STREAM M / HARDENING
> → owner prerequisites
> → Gate 11 Attempt 2
> → P3-G2
> → Executive A0 Shadow Week
>
> At the same time, safe/inert Phase 4, Phase 5, and Phase 6 engineering may proceed in parallel.
>
> ## 1. First: complete Stream M
>
> Merge the existing Stream M changes.
>
> Required outcome:
>
> * one lock prevents overlapping deploys
> * verify-only detects stray RUN_MIGRATIONS configuration
> * Anthropic credit/spend-limit readiness is classified correctly as provider-blocked-by-credit
> * no deleted ERP hostname remains in .env.example
> * deploy/rollback/verify-only behavior remains consistent with the already-fixed rollback invariant
> * production remains backup-first
> * no unknown CLI arguments are accepted
> * all existing deploy safety behavior remains intact
>
> Run the full combined local suite after merge.
>
> Do not call GitHub Actions “green”; CI remains deferred until the 1 October reset.
>
> After merge, verify the actual production candidate still reports the expected release identity and health behavior.
>
> ## 2. Preserve the scheduler incident fix as an operational invariant
>
> The 18–21 September scheduler incident is not just a one-off bug.
>
> Make sure the final implementation permanently preserves:
>
> * init/supervision semantics for the scheduler container
> * no zombie buildup from background scheduler children
> * pids exhaustion cannot silently consume the container
> * deploy, rollback and verify-only retain failure response bodies
> * host monitor retains the 503 body/component name instead of reducing it to “unreachable”
> * health/monitoring identifies the failing component
> * scheduler health remains observable
>
> Add or retain regression coverage for these invariants.
>
> Do not add a second monitoring architecture.
>
> ## 3. Phase 4 write foundation — build inertly
>
> Proceed with the proposed draft Sales Order foundation, but strengthen the contract.
>
> ERP remains the canonical system of record for Sales Orders.
>
> Create the narrowest ERP-side endpoint needed to create a DRAFT sales order only.
>
> Hard constraints:
>
> * explicit idempotency key
> * deterministic idempotency behavior
> * ERP re-reads current price before creating the draft
> * ERP re-reads stock/availability before creating the draft
> * ERP re-checks customer/order eligibility
> * no confirmation
> * no invoice
> * no payment
> * no stock reservation unless the ERP's existing DRAFT contract already guarantees that reservation does not occur
> * no fulfillment
> * no ledger posting
> * no customer notification
> * no downstream commerce side effects
> * no silent status transition out of DRAFT
>
> The draft must retain an immutable linkage back to the HQ proposal/fingerprint so HQ can reconcile:
>
> proposed order
> ↔ approved order intent
> ↔ ERP draft
>
> The reconciliation check must detect mismatches rather than silently rewriting either side.
>
> In HQ:
>
> * add/complete `take_order` as a capability
> * R3
> * approval-required
> * `agentExecutable=false`
> * held by no agent
> * no grant
> * Tool Gateway is the only execution path
> * requester/enabler/EEC rules apply
> * no admin shortcut
> * no direct ERP credentials exposed to agents
> * proposal cannot authorize itself
> * approval cannot be performed by the requester
>
> Build tests proving that the capability is structurally present but inert.
>
> Do not activate order-taking.
>
> Do not introduce checkout writes, payment writes, confirmation, or customer-facing execution.
>
> ## 4. Phase 5 design/readiness packet
>
> Produce the Phase 5 Marketing / Ads architecture documents now.
>
> This is a design and external-readiness milestone, not implementation of autonomous advertising.
>
> Document:
>
> * marketing domain model
> * business/brand/store/platform separation
> * campaign/ad-set/ad/creative concepts
> * provider abstraction
> * Meta integration boundaries
> * capability registry requirements
> * Tool Gateway contracts
> * credential/connection model
> * consent and authorization boundaries
> * human-only decisions
> * budget/spend controls
> * approval requirements
> * kill switches
> * reconciliation requirements
> * audit requirements
> * idempotency and retry rules
> * attribution/data provenance
> * evaluation and shadow strategy
> * autonomy boundaries
> * required Meta/business registrations and verification steps
> * provider prerequisites
> * production activation gates
> * what can later become read-only / proposal-only / approval-gated / autonomously executable
>
> Do not bind the architecture to a single vendor endpoint or protocol.
>
> MCP, if useful later, is only an adapter behind the canonical Tool Gateway/capability contracts.
>
> ## 5. Phase 6 design/readiness packet
>
> Produce the Phase 6 Content Factory + Research Engine architecture documents now.
>
> The design must cover:
>
> * content planning
> * source discovery
> * research tasks
> * source provenance
> * citations/lineage
> * structured research artifacts
> * fact vs inference vs recommendation
> * draft generation
> * review/editing
> * media asset linkage
> * brand/business/store scoping
> * publication workflow
> * platform adapters
> * human approval
> * reusable content briefs
> * evaluation
> * auditability
> * retention
> * failure recovery
> * idempotency
> * capability/tool boundaries
> * future automation levels
>
> Research must never treat memory as truth.
>
> External or model-generated information must not become trusted merely because an agent/model produced it.
>
> Untrusted content must not create authority, permissions, scope, consent, or task substitution.
>
> Do not introduce unrestricted browsing as a hidden agent capability.
>
> ## 6. Owner prerequisites
>
> Prepare the system and documentation so these owner actions can be performed cleanly:
>
> 1. install the verified OpenAI key backed by the credit-bearing provider account
> 2. execute the already-approved one-line P3-G1 production replay
> 3. provision the ERP read token with the already-defined read abilities
> 4. continue Meta Business Verification required for Phase 3 live operation
> 5. perform the browser walk of the new HQ pages under 2FA
>
> Do not create fake credentials or bypass readiness checks.
>
> ### Provider ownership note
>
> For production, prefer an organization-owned OpenAI project/account and auditable provider ownership rather than a long-term personal-account dependency.
>
> The exact OpenAI key that passes readiness must be the credential used for Gate 11.
>
> ## 7. Gate 11 Attempt 2
>
> Do not modify the existing Gate 11 scenario set, safety rules, scoring semantics, or evidence requirements merely to obtain a pass.
>
> This is the first Gate 11 measurement on OpenAI rather than Anthropic.
>
> Exact measurement:
>
> * exact candidate currently deployed
> * OpenAI provider
> * gpt-5.6-terra
> * reasoning effort low
> * one provider serves the entire measurement
> * first structured response must validate before any scenario proceeds
> * HQ ArgumentValidator remains authoritative because the HQ agent schema is outside the provider's strict subset
> * no provider switching during the gate
> * no tuning during the gate
> * zero unmeasured scenarios
> * provider refusal must be classified correctly
> * do not silently retry a failed safety case through another provider
> * preserve the prior Anthropic Gate 11 evidence separately
> * do not combine Anthropic and OpenAI evidence into one score
>
> Before the gate starts, verify:
>
> * provider readiness = verified
> * exact model is usable
> * production credential is valid
> * ai.runtime state is correct
> * no accidental agent grants exist
> * no shadow period is already open
> * no customer-facing provider path is active
> * Meta inbound remains off
> * Support remains paused
> * Analytics remains paused
> * Executive remains at A0 and not activated
>
> If the first structured response fails, stop without measuring the gate.
>
> If the provider reports a credit/billing block, classify it as provider-blocked-by-credit and stop. Do not condemn the credential itself unless the provider actually rejects the credential.
>
> Expected measurement budget is approximately $2.50–$3.00. Keep the measurement constrained to the gate.
>
> ## 8. P3-G2 immediately after a successful Gate 11
>
> If Gate 11 Attempt 2 passes completely:
>
> Run P3-G2 next, before opening the shadow week.
>
> Use the same OpenAI provider environment/session family so the provider integration is validated without introducing a provider change.
>
> However:
>
> * P3-G2 is a separate validation artifact
> * do not add P3-G2 scenarios to the Gate 11 score
> * do not retroactively alter Gate 11 metrics
> * do not treat G2 as autonomy evidence
> * Joy is the enabling user for this simulated run
> * Support remains paused outside the explicit simulated test
> * no customer-facing message is sent
> * no outbound messaging occurs
> * no real customer operation is activated
> * all requester/enabler/EEC rules remain live
> * record the exact provider/model/readiness state used
>
> If P3-G2 fails, do not open Executive shadow.
>
> Fix the defect, rerun the affected validation, and keep production inert.
>
> ## 9. Executive A0 Shadow Week
>
> Open the shadow only after:
>
> * Stream M is merged
> * owner OpenAI credential is verified
> * Gate 11 Attempt 2 passes with zero unmeasured
> * P3-G2 passes
> * no safety regression exists
> * no customer-facing path is active
> * Meta inbound is off
> * outbound provider paths remain off
> * grants remain zero
> * autonomy evidence policy remains inactive unless explicitly required by an existing invariant
> * Executive is active only at A0 shadow
> * Shadow period is opened explicitly under Joy's identity
> * no implicit `as_enabled`
> * shadow probes remain scheduled twice daily
> * every shadow opening/closing is audited
>
> Shadow probes remain exactly the approved three operator questions:
>
> * what needs my attention today
> * how did the group do yesterday
> * how current is the data
>
> The shadow period is evidence collection, not promotion.
>
> Do not automatically raise autonomy level at the end of the seven days.
>
> Do not convert shadow evidence into an autonomy grant automatically.
>
> ## 10. Autonomy Evidence Policy
>
> Keep ADR-048 as already implemented.
>
> Do not invent numeric thresholds now.
>
> The policy must remain:
>
> * versioned
> * one writer
> * console-only propose/activate/retire
> * dry-run until --apply
> * activation requires --by and --reason
> * no undecided fields
> * producer recounts raw evaluation_results
> * suite-specific evidence cannot be satisfied by pooled evidence
> * policy is an additional ceiling only
> * resolver may lower autonomy, never raise it through policy
> * agent_runs records the autonomy evidence policy context under which a run was judged
>
> Production should continue to report that no autonomy evidence policy is active until the owner later defines and activates one deliberately.
>
> ## 11. Final verification
>
> After all merged code:
>
> * full Pest suite
> * Pint
> * strict TypeScript
> * Vite build
> * deploy-safety
> * relevant production verify-only
> * health verification
> * scheduler verification
> * no new migration mismatch
> * no route/auth regression
> * no permission bypass
> * no stale authorization cache
> * no requester/enabler identity regression
> * no scope widening
> * no security-intent downgrade
> * no direct Tool Gateway bypass
>
> Do not claim CI green until GitHub Actions is actually available and passes after the 1 October reset.
>
> ## 12. Main/production discipline
>
> Production is currently 87d8658.
>
> Do not deploy documentation-only work unnecessarily.
>
> Keep production inert until the explicit Gate 11 → P3-G2 → Shadow sequence passes.
>
> When ready to deploy a new candidate, preserve:
>
> * backup-first
> * explicit release record
> * verify-only
> * exact release identity
> * rollback invariant
> * production/main visibility
>
> ## 13. Required final report
>
> Return a milestone report with exactly these sections:
>
> 1. Stream M result
> 2. Production status
> 3. Phase 4 draft-write foundation
> 4. Phase 5 readiness/design
> 5. Phase 6 readiness/design
> 6. Owner prerequisites
> 7. Gate 11 Attempt 2 result
> 8. P3-G2 result
> 9. Shadow readiness/status
> 10. Tests and verification
> 11. Remaining blockers
> 12. Exact production activation posture
>
> For Gate 11, explicitly report:
>
> * provider
> * model
> * total cases
> * passed
> * failed
> * unmeasured
> * cost
> * first-structured-response result
> * whether any tuning occurred
> * whether provider switching occurred
>
> For P3-G2, report it separately.
>
> Do not call the system autonomous merely because shadow passes.
>
> The architectural rule remains:
>
> trusted task
> → model interpretation
> → structured proposal
> → policy / authority / permission / consent / autonomy
> → capability resolution
> → Tool Gateway
> → execution
> → verification
> → reconciliation
> → retry / compensation / escalation
>
> Keep the system inert until the corresponding activation gate is explicitly satisfied.

## B. The plan (written before the code)

| Stream | Branch / place | Instruction points |
| --- | --- | --- |
| M. The deploy lock, verify-only reads a stray `RUN_MIGRATIONS`, Anthropic's spend-limit answer is blocked-by-credit, the deleted ERP host out of `.env.example`, `EvidencePolicies::retire()` on a fresh read | `fix/deploy-lock-and-residuals` in `hq-wt/e` (planned and recorded in `docs/81` C.9, started before this instruction arrived) | 1 |
| The scheduler incident as a standing invariant: each item of point 2 mapped to the check that holds it, and any gap closed in the existing checks (no second monitoring architecture) | recorded here in C.2 by the coordinator, gaps closed on the stream M branch | 2 |
| N. Phase 4 draft write, inert: the ERP endpoint that creates a DRAFT sales order only, and HQ's `commerce.take_order` registered, approval-required, not agent-executable, held by no agent | HQ `p4/take-order-draft-inert` in `hq-wt/f`; ERP `feature/hq-sales-order-draft` in `erp-wt/hq-reads` | 3 |
| O. The Phase 5 (marketing and ads) and Phase 6 (content factory and research engine) design and readiness packets | `docs/84`, `docs/85`, on branch `docs/phase-5-6-readiness` in `hq-wt/g` | 4, 5 |
| The owner's steps, each as exact lines with why, in one place | section C.4 here, and `docs/82` updated | 6 |
| Gate 11 attempt 2, then P3-G2, then the shadow week | on production, after Stream M is released and the owner's key is verified | 7, 8, 9 |

**One correction to the instruction, made openly rather than silently (stream N).** Point 3 says
`take_order` at **R3**. In HQ's own tier scale (`app/Gateway/Descriptor/RiskTier.php`) R3 is "internal
write" and its action class is `Auto`; R4 is "external side effect, recoverable" and its action class
is `ApprovalRequired`. A draft sales order in the ERP is an external side effect that can be undone
without loss, which is R4's own definition, and `docs/79` section D already declares
`commerce.take_order` at R4 for exactly that reason. R3 would also contradict the same point's
"approval-required". Stream N therefore keeps **R4**, as the settled design says, and the next report
names this so ChatGPT can overrule it if R3 was meant.

**Order of release.** Stream M changes PHP as well as `deploy/` (the readiness classifier and the
policy writer), so the build Gate 11 measures must include it: Stream M is merged, verified, and
released inert by Joy before the gate, and that release is the gate's exact candidate. Stream N's HQ
side is inert and may ride in the same release only if it is merged and verified in time; it is not
allowed to delay the gate. Its ERP side deploys on the ERP's own authority, behind a tested dump,
unreachable to HQ until a token carrying its ability exists.

Rules every stream keeps: a correction is watched failing first; no prompt file changes; nothing is
switched on; no grant, flag row, policy version or scheduled job is created; historical rows and
gate evidence are never rewritten; no invented business rule or number; migrations additive and
rehearsed (HQ on Postgres; ERP structure migrations on a scratch MySQL first); local verification is
reported as such, never as CI green.

## C. Record

### C.1 Stream M

Recorded in `docs/81` C.9 (it was planned under that milestone); its merge is recorded here.

### C.2 The scheduler incident as a standing invariant (point 2)

To be written: each of point 2's seven items against the check that holds it.

### C.3 Stream N: the Phase 4 draft write, inert

Branches: HQ `p4/take-order-draft-inert` (from `ed86b8f`), ERP `feature/hq-sales-order-draft` (from
ERP master `e3e39cf`, which is ERP production).

#### C.3.1 Today's behaviour, read from both codebases (21 September, before any change)

**The ERP has no draft sales order.** `SalesOrderStatus` has five cases, `open`,
`partially_delivered`, `delivered`, `closed`, `cancelled`; there is no `draft`. The one way an order
is written, `CreateSalesOrder::handle()` (`app/Modules/Sales/Actions/CreateSalesOrder.php`), does
four things a draft must not do:

1. It writes the order `open`, a live commitment that the delivery screen can act on once approved.
2. It takes the next number of the business's sequence (`nextNumber()`, `SO-FY2026-0001`), so an
   order that is never meant to exist would still consume a number and leave a gap.
3. It opens an approval request (`submitForApproval`), and self-approves it when the creator holds
   `sales.orders.approve`: an approved order is deliverable (`SalesOrder::isDeliverable()`).
4. It notifies every holder of `sales.orders.approve` (`NotifyApprovers`, outside the transaction).

What it does NOT do, by its own docblock and by reading: move stock, post a journal, take a payment.
Nothing in the ERP reserves stock for an open order either: `Availability::decide()` looks at stock
on hand and the channel's policy and "reserves nothing"; `CartRules::refusalFor()` asks published,
priced and sellable. The website path, `PlaceWebsiteOrder`, calls `CreateSalesOrder` and adds three
more effects: it creates a customer when the phone matches nobody (`identify()`), locks and spends a
coupon, and marks the basket converted. **So no existing path creates a draft, and using either one
would take a number, open an approval, notify people and possibly create a customer.** The draft
therefore cannot be a `sales_orders` row; it is its own record.

The ERP's HQ API (`routes/api.php`, `HqApiContext`) has no write besides `tickets`; `HqApiToken`
issues only `READ_ABILITIES` (thirteen read names and `crm.tickets.manage`) and refuses anything
else; the order-reads (stream I) read products, prices (`ResolveSellingPrice` through
`CataloguePublishing::priceFor` for a store), availability (`CartRules`, `Availability`) and the
customer a phone names (`identify`'s rule, creating nobody).

**HQ has no order write.** `config/hq.php` registers no `commerce.take_order`; `ErpConnector` has one
write (`openTicket`); `RiskTier::R4` is "external side effect, recoverable" with the baseline
`ApprovalRequired`, R3 is "internal write" with the baseline `Auto`. `ToolDescriptor::supportedStores`
is checked by the gateway only when a store was resolved (`ToolGateway::checkScope()`), and an empty
list supports no store. The approval separation rule (`Approval::separationOfDutiesReason()`) bars a
requester only when the requester is not the agent's enabler; a person who asks for an approval
directly may decide it today. The approval stores the redacted payload and executes exactly that
(ADR-018), so a redacted argument would not reach the ERP. `ErpConnector::throwForStatus()` maps a 409
to "unexpected HTTP 409" (`Unavailable`), which a write would wrongly retry.

#### C.3.2 The design (written before the code)

**ERP: a draft is its own immutable record, `sales_order_drafts`, never a sales order.**

1. Two additive tables: `sales_order_drafts` (business, store, status `draft` only, the
   idempotency key unique per business, the sha256 of the request payload, the HQ proposal
   fingerprint, the HQ references, the resolved customer id or none, the buyer and the destination as
   given, payment method, the priced totals, the correlation id and token id, who created it) and
   `sales_order_draft_lines` (SKU, product, quantity, the engine's unit price and its explanation, the
   line total, the availability verdict at draft time). Rehearsed on a scratch MySQL database: apply,
   roll back, re-apply.
2. `SalesOrderDraft` refuses every update and every delete in the model (an immutable record), and a
   source test holds that nothing under `app/` writes the table except the one action. There is no
   method that moves a draft out of `draft`, and no route that could.
3. `CreateSalesOrderDraft` (the action) re-asks the ERP itself, never the caller: the store must be
   this business's and **live**; each line's SKU names an active, stocked product; `CartRules`
   (published, priced, sellable at this store); the quantity a whole number from 1 to
   `CartRules::MAX_QUANTITY`, as the website takes it; the price `CataloguePublishing::priceFor` at the
   store, at that quantity; the delivery charge `DeliveryQuote::for(store, district)`, refused when the
   store cannot quote; the destination `SalesOrderDestination::fromInput`; the buyer's phone must
   normalise (`PhoneIdentity`), and the customer is the one `identify` would pick, or none (**nobody is
   created**: `customer_resolution` says `existing` or `new`); payment `cod` only (online payment is a
   link the customer follows, a later decision). Then every figure HQ proposed (each line's unit price,
   the goods total, the delivery charge, the agreed total) must equal the ERP's to the paisa, or the
   request is refused with **422 `quote_mismatch`** naming each field, proposed against the ERP's
   figure. Nothing is ever rewritten to make it match. No coupon, no bundle, no note in this version:
   a body with any unknown key is refused.
4. Idempotency: the key comes in the body and must equal the `Idempotency-Key` header when both are
   sent. Same key and the same payload digest: **200 with the stored draft and `replayed: true`**,
   without re-pricing (the draft answers what was agreed then). Same key, different payload: **409
   `idempotency_key_reused`**, and no second draft. A race on the unique index reads the winner and
   answers the same way.
5. What it does not do, each proved by a test that counts before and after: no `sales_orders`, lines
   or address row, no order number, no approval request, no notification (database, mail or
   broadcast), no customer, no stock batch or movement, no journal, no coupon use, no queued job.
6. Two routes under `/api/hq/v1/{business}/`: `POST sales-order-drafts` and
   `GET sales-order-drafts/{idempotency_key}` (the read-back HQ reconciles with; 404 `not_found` is an
   answer). Both need a NEW token ability, `sales.orders.draft`, which no token holds: `HqApiToken`
   gains `WRITE_ABILITIES` and issues a write ability only with an explicit `--write`, never by
   default. Every body is an explicit field list, stamped with the contract `hq-sales-order-drafts.v1`
   and an `effects` block that states, for the reader, that no order, number, approval, customer,
   reservation or notification came of it.
7. The ERP test writes the response bodies as fixtures and pins a contract digest; HQ carries a copy
   and pins the same digest (stream I's pattern).

**HQ: `commerce.take_order`, registered, approval-required, refused for every caller today.**

1. `ErpConnector::createSalesOrderDraft()` and `readSalesOrderDraft()`: a connection failure on the
   POST is `Uncertain` (the draft may exist; the key makes a read-back and a retry safe); 409 is
   `Refused`; 422 `ValidationFailed` with the ERP's sentences; 403 `Unsupported` (the token lacks
   `sales.orders.draft`, a readiness state, not a refusal by HQ); a body from another contract or
   another business is refused.
2. `TakeOrderTool` (`app/Tools/Commerce`): the descriptor of `docs/79` section D at **R4**,
   `hq.tools.commerce.write`, write, business scope with the store named, `agentExecutable: false`,
   customer-facing, `MonetaryImpact::High`, personal data, compensable by
   `commerce.cancel_sales_order` (named by the design and not built: until it exists nothing follows a
   draft, so there is nothing to compensate), idempotent with no replay window, and
   **`supportedStores` read from `config('hq.orders.take_order_stores')`, which ships empty**: every
   call names a store, and the gateway refuses every store. Admitting one is a deploy and the P4-G2
   gate, not a switch. Previewable (the approval card says what would be written and that nothing
   follows it).
3. Execution follows ADR-031: the ERP key is derived from the gateway's idempotency key and the
   business (so `reconcile()` finds the same draft), the proposal fingerprint is
   `CanonicalPayload::hash` of the executed arguments (the approval's own payload hash when an
   approval ran it), the draft is created, then **read back** by key, and the result is `succeeded`
   only when the read-back agrees with the proposal; a disagreement is `uncertain` with the
   discrepancies named, never `succeeded`. `reconcile()` reads back by key before anything is sent
   again.
4. `DraftReconciliation` compares the proposed order, the approved intent (the approval's payload
   hash) and the ERP draft: fingerprint, store, each line's SKU, quantity and price, the totals,
   payment method, phone and district. It returns discrepancies; it repairs nothing.
5. The requester may never decide: a new descriptor field, `requesterMayDecide` (default true, so no
   existing tool changes), is false on `take_order`, and `Approval::separationOfDutiesReason()`, the
   one rule every door reads (`docs/81` C.6), bars the requester of such an approval whoever they are.
   With one operator today, nobody can approve an order, which is the intended state.
6. No agent holds it, no grant names it, no prompt changes, no scheduled job, no flag row; no route or
   command reaches the tool except through the gateway (a source test).

**Tests** (HQ): the descriptor as registered; the production refusal (no supported store); with a
store admitted in the test, an owner is sent to approval, never allowed; the requester cannot decide
at the executor or the service; an approval for different arguments does not run it; the ADR-031
sequence against a simulated ERP: created and read back, replayed, 409, 422 price mismatch named, a
connection failure left `uncertain` and resolved by `reconcile()` reading back, a read-back that
disagrees left `uncertain`; the reconciliation's discrepancies; the contract copy; no agent's
catalogue carries it. (ERP): created, replayed, 409, each refusal, the no-side-effect counts, the
immutability, the ability and `--write`, the other business, the fixtures.

#### C.3.3 As built (21 September)

Two branches, neither merged, nothing deployed: ERP `feature/hq-sales-order-draft` at `0fe39af`
(on ERP master `e3e39cf`, which is ERP production), HQ `p4/take-order-draft-inert` (on `ed86b8f`).

**ERP.** One additive migration in the Sales module,
`2026_09_21_100000_hq_can_leave_a_draft_order_that_becomes_nothing_on_its_own`: `sales_order_drafts`
(status `draft` only; `idempotency_key` unique per business as `so_drafts_business_key_unique`;
`payload_digest`; `hq_proposal_fingerprint` indexed with the business; `hq_reference` JSON; the
customer id or none and `customer_resolution`; ONE person, the recipient, as on the website, because
whether an HQ order may name a recipient other than the buyer is the open question `docs/79` H.7;
the destination parts with the derived postcode; payment method; goods, delivery, discount and agreed
totals; `priced_at`; correlation id, token id, creator) and `sales_order_draft_lines` (SKU, product,
whole quantity, the engine's unit price to four places, the line total, the engine's explanation, and
whether the line was made to order at that moment).

- `SalesOrderDraft` and `SalesOrderDraftLine` refuse every update and delete (`LogicException`), and
  a draft can only be created as `draft`.
- `CreateSalesOrderDraft`: replay or 409 first (the stored digest against this request's, keys sorted
  at every depth); then the store (this business's, and LIVE); the destination through
  `SalesOrderDestination`; the phone through `PhoneIdentity` against `^01[3-9]\d{8}$` (`docs/79` C.7);
  each line: SKU of this business, stocked, `CartRules::refusalFor` (published, priced, sellable), a
  SKU named once; the price from `ResolveSellingPrice::find` with the store's price group at that
  quantity (what `CataloguePublishing::priceFor` asks, with its explanation kept); delivery from
  `DeliveryQuote`. Refusals come back together as 422 `refused`; only when there are none are HQ's
  figures compared, each line's price to four places and the three totals in paisa through `Money`,
  and every difference comes back as 422 `quote_mismatch` with both figures. The customer is the one
  `identify` would pick (active, not the walk-in row, first by id) or none: nobody is created. A race
  on the unique index is answered as a replay or a 409, never a second row.
- `HqSalesOrderDraftController`: `POST /api/hq/v1/{business}/sales-order-drafts` and
  `GET .../sales-order-drafts/{key}`, both behind `sales.orders.draft`; unknown top-level keys refused
  (no coupon, no note, no override in this version); `payment_method` only `cod`; quantities whole,
  1 to 99; the `Idempotency-Key` header must equal the body's key. Every body is an explicit field
  list stamped `hq-sales-order-drafts.v1`, with an `effects` block (no sales order, no number, no
  approval, no customer, no reservation, no journal, no notification) and `next_step: None`.
- `HqApiToken`: `WRITE_ABILITIES = ['sales.orders.draft']`, issued only when named AND `--write` is
  given; `--write` widens nothing else.
- The routes file names the new write in its header, and says there is no route that confirms,
  changes or cancels a draft.

**What a draft did before, and what the new path does not do.** Before, no path made a draft: the
only order write takes a number, opens an approval, notifies approvers, and through the website may
create a customer and spend a coupon (C.3.1). The new path touches none of those tables: the first
test counts `sales_orders`, its lines and addresses, `approval_requests`, `approval_decisions`,
`notifications`, `customers`, `stock_batches`, `stock_movements`, `journals`, `journal_lines`,
`store_coupons`, `carts`, `crm_tickets`, `jobs`, `products` and `product_prices` before and after, and
fakes notifications, mail and the queue: all unchanged, nothing sent, nothing pushed.

**Scratch MySQL rehearsal** (the rule of `feedback_scratch_mysql_before_deploy`, done on the local
MySQL 8.4.3 of this PC, not on production's container, because this stream may not touch the VPS;
production is MySQL 8.0.46, so parity on identifiers and JSON is expected but not proven): a fresh
database, every ERP migration applied, the new one included (its indexes and foreign keys read back
with `SHOW CREATE TABLE`), rolled back one step (both tables gone), re-applied (both back); then the
draft tests run against MySQL. **They found a MySQL-only defect:** MySQL stores a JSON object with its
keys re-sorted, so `hq_reference` came back in another order than it went in, and the contract body
differed between SQLite and MySQL. The body now rebuilds `hq_reference` in a fixed order. After the
fix every body matched on MySQL except the auto-increment ids, which InnoDB does not roll back between
tests (the order-reads fixtures would differ the same way); the scratch database was dropped.

**HQ.**

- `ErpConnector::createSalesOrderDraft()` (the key in the header and the body; a connection failure
  is `Uncertain`; 409 is `Refused`; 422 `ValidationFailed` with the ERP's `errors`; 403 `Unsupported`)
  and `readSalesOrderDraft()` (404 `not_found` is an answer); both refuse a body of another contract,
  another business, or whose status is not `draft`. `SALES_ORDER_DRAFTS_CONTRACT`.
- `TakeOrderTool` (`commerce.take_order`, `app/Tools/Commerce`): the descriptor of `docs/79` D at R4
  (C.3.2 point 2), `supportedStores` from `config('hq.orders.take_order_stores')`, shipped `[]` in
  `config/hq.php`; `policyClass` `TakeOrderPolicy`; `scopeFrom` store then business; the ERP credential;
  `redact: []` because the approval executes its stored payload; `requesterMayDecide: false`.
  `execute()` checks the inner shape again, derives the ERP key as
  `hq:` + sha256(gateway key + business), fingerprints the executed arguments with
  `CanonicalPayload::hash` (for an approved call, the approval's own payload hash), writes the draft,
  READS IT BACK, and asks `DraftReconciliation`: `succeeded` only on agreement. A failed read-back, a
  missing draft and a disagreement are `uncertain` with the reason. `reconcile()` reads back by the same
  key and judges against the arguments the uncertain call recorded. The output carries no name, phone
  or address. Previewable: the approval card says what would be written and that nothing follows.
- `TakeOrderShape` (the inner shape: destination, lines, totals; the gateway's validator checks only
  the top level) and `TakeOrderPolicy` (refuses a malformed order, or a store of another business,
  BEFORE an approval is parked; can only tighten).
- `DraftReconciliation`: approval hash, fingerprint, status, store, payment method, phone, district,
  thana, every line's quantity and price, lines missing on either side, the three totals. Reports; the
  inputs are left as they were.
- `ToolDescriptor::$requesterMayDecide` (default true; in `toArray()`), read by
  `Approval::separationOfDutiesReason()` from the DEPLOYED descriptor: when false, whoever asked never
  decides, owner included. A descriptor that cannot be built reads as false; an unregistered tool
  keeps today's rule (the gateway will not run it anyway).
- **Found and fixed on the way: the gateway's reconcile ran with the vault closed.**
  `ToolGateway::replay()` asked `$tool->reconcile()` before step 8 opened any credential, so no tool
  with an effect outside HQ could ever look its effect up: it would fail on the credential, not on the
  question. Every tool until now returned null, so nothing noticed. `replay()` now resolves the tool's
  declared credentials the way step 8 does and opens exactly those around `reconcile()`.
- Six posture guards named the second R4 tool and the second customer-facing tool instead of being
  loosened: `ApprovalCenterTest`, `PolicyEngineTest`, `ToolGatewayTest` and `AiSafetyPostureTest` (the R4 list is
  `demo.request_approval` and `commerce.take_order`, and take_order admits no store),
  `ToolNamespaceTest` (the key list), `SharedFoundationSafetyTest` (`commerce.take_order` is
  customer-facing on an internal channel with no consent class, not agent-executable, no store).

**Tests.** ERP `tests/Feature/HqApi/HqSalesOrderDraftTest.php`, 15: the draft and nothing else; a new
buyer created as nobody and never the walk-in row; replay (not re-priced after a price change, key
order irrelevant) and 409; the read-back and 404; `quote_mismatch` naming four fields; a quantity
break priced by the engine; the website's refusals (unknown SKU, a service, out of stock on a strict
product, a SKU twice, a store not live, an unknown store, an unknown thana, a bad phone); a store that
cannot quote; cash on delivery only and no unknown key; the ability on both routes; `--write`; each
business to itself (the same key in another business is another draft); immutability; one writer and
exactly two routes; the fixtures with digest
`414fdaa6dae0d89a260e3af7db2838211d2b7898cd32c72d4afc714862d2a02f`. HQ
`tests/Feature/Erp/SalesOrderDraftContractTest.php`, 5 (the unedited copy and the same digest; every
body read; the refusals by class; a POST with no answer is uncertain; another contract, business or
status refused) and `tests/Feature/Commerce/TakeOrderTest.php`, 17 (the descriptor; refused in
production's state before approval and the ERP; no agent holds it or can run it; an owner is sent to
approval with exactly the proposal parked; a malformed order or another business's store refused before
approval; the requester cannot decide at the executor or by rejecting; the rule changes nothing for
other tools; approved by another person, written and read back, the key derived from the approval, no
personal data in the stored output; replay and the same key for another order; a token without the
ability; a moved price reported and not retried; a POST with no answer settled by reading back, with
no second POST; a failed read-back and a disagreeing read-back both `uncertain`; an altered approval
payload never runs; the reconciliation's every field; no other class names the tool).

**Watched failing, and teeth.** Every class but the gateway, the approval rule and the connector's 409
is new, so its tests' first failure proves nothing; their teeth were shown by mutation, each applied,
the files run, and the code restored. ERP: totals comparison removed (1 test fails), line price
comparison removed (2), key reuse replayed instead of 409 (2), a store that is not live accepted (2),
the walk-in row matched (1), the draft updatable (1), a write ability without `--write` (1). HQ: the
requester rule removed (2), reconcile with the vault closed, i.e. the gateway as it was (1: the
uncertain write cannot be settled, which is how the defect was found, watched failing with
`Unsupported` before the fix), the 201 trusted without a read-back (4), quantity not compared (1),
the policy never refusing (1), every store admitted (2), a 409 read as an outage (2).

**Verification (local; GitHub Actions validation deferred until the 1 October reset).** ERP: the full
suite in parallel (8 processes), 4,026 passed (4,011 before, plus 15); Pint on the named files; no
TypeScript changed. HQ: the full Pest suite in one run on `hq_test_f`, **1211 passed, 10,888
assertions, 0 failed** (1189 on the base plus 22), 282 s; `vendor/bin/pint --test` passed; `npx tsc
--noEmit` clean; `npx vite build` built. The first full run failed one test, a sixth posture guard
(`AiSafetyPostureTest`: the R4 list), amended like the other five.

**Found on the way, not fixed.**

- `config/hq-seed.php` still names the deleted ERP host for the `erp-counter` store's `base_url`
  (stream M's item 2 covers such files).
- The draft carries `approval_uuid` and `tool_call_id` as null from HQ: `ToolContext` knows neither.
  The link is the fingerprint (the approval's payload hash) and the correlation id the connector sends;
  adding the approval to `ToolContext` is a gateway change for when a draft is confirmed.
- The ERP draft is not visible on any ERP screen. It is a record HQ reads back; a screen belongs with
  the step that confirms a draft.

**Open questions for Joy or ChatGPT.**

1. R4 kept against the instruction's R3 (C.3.2 point 2 and section B): confirm.
2. `docs/79` H.7 (may an HQ order name a recipient other than the buyer?) decides whether the draft
   gains a separate buyer; today it is one person, as on the website.
3. Who will be the second person able to decide an order approval? Until one exists, no order can be
   approved, by design.

### C.4 The owner's steps

To be written.

### C.5 Stream O: the Phase 5 and 6 readiness packets

To be written.
