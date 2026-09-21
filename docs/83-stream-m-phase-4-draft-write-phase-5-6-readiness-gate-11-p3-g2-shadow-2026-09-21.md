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

To be written by the stream: today's behaviour read from both codebases, the design, then the
as-built record.

### C.4 The owner's steps

To be written.

### C.5 Stream O: the Phase 5 and 6 readiness packets

To be written.
