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

### C.1 Stream M, and the release candidate for Gate 11 attempt 2

Planned and recorded in `docs/81` C.9 (it began under that milestone). Branch
`fix/deploy-lock-and-residuals`, head `d26c66c`, reviewed by reading the diff, merged `--no-ff` as
`ddfcd53` on top of stream O's merge and C.2's test. What it brings: one host lock (`flock` on
`/tmp/bordeguna-hq-deploy.lock`, the gate runner's pattern) taken by `deploy.sh` and `rollback.sh`
before they read anything, a second release refused at once naming the holder, `--verify-only`
reading the lock's record without opening it (FAIL while one runs, WARN for a killed one); a
`RUN_MIGRATIONS` other than 0 in `deploy/.env` refused by the preflight AND by `deploy.sh` (the stream
went past its brief there, because the preflight is optional and the deploy is where the damage
happens); Anthropic's "api usage limit" 400 read as `provider_blocked_by_credit` (the wording measured
on production during gate 6, `docs/69`); the deleted ERP host gone from `deploy/.env.example` and the
`erp-counter` seed, held by `DeletedErpHostTest`; `EvidencePolicies::retire()` judging and retiring the
row as the database has it, locked. Every correction watched failing first. Instruction point 1's other
outcomes were already true and stay tested: backup-first (`docs/81` C.3), unknown arguments refused,
the rollback invariant.

**Verification of the combined tree (main `ddfcd53`: streams M and O, and C.2's test).** Full Pest
suite in one run: **1199 passed, 10,731 assertions, 0 failed**, 314 s (1189 + 6 from stream M + 4
from C.2). `vendor/bin/pint --test` passed; `npx tsc --noEmit` clean; `npx vite build` built.
Deploy-safety on the branch: **313 passed, 0 failed** (260 + 53), on Git Bash with the `stat` shim;
against the unmodified scripts 275 passed and 38 failed, all 38 new checks; nothing in `deploy/`
changed after the branch except `deploy/README.md`, which no check reads. **Local verification
passed; GitHub Actions validation deferred until the 1 October reset; Linux parity unproven.**

**The release candidate is `origin/main` at the commit that records this section.** Its application
code is the tree of `ddfcd53`. It carries no migration over production's `87d8658`, no prompt,
TaskGrounding, TaskScope, detector or agent-schema change (stamps still `executive.v8+18d60e98` and
`support.v1+0f4a195b`), and switches nothing on. It is the build Gate 11 attempt 2 measures and the
shadow week then runs, so nothing is released between them. If stream N's inert HQ side is merged and
verified before Joy releases this, the candidate moves to include it (and this section says so);
otherwise N waits until after the shadow week. The release lines are in `deploy/README.md`, "the
release after `87d8658`"; `deploy.sh` there is `87d8658`'s copy, which has no lock yet.

Found by the stream, not fixed: `EvidencePolicies::activate()` has the shape `retire()` had (the
database trigger still refuses an incomplete version, so it fails closed); the refusal when `flock`
is missing is untested (the suite always has the real one or its stand-in).

### C.2 The scheduler incident as a standing invariant (point 2)

Each item of point 2 against what holds it, read on 21 September. The monitoring stays the one
architecture it was: `/api/health` inside HQ, the host monitor outside it, and the deploy scripts that
read the same endpoint. Nothing new was added to watch; one gap in the tests was closed.

1. **Init/supervision semantics for the scheduler container.** `deploy/docker-compose.yml`, service
   `scheduler`, `init: true` (stream L). Held by deploy-safety section 24, which reads the service block
   and fails without the line. On production at 07:06 UTC: PID 1 `/sbin/docker-init`, then
   `php artisan schedule:work`, 2 processes in all. The ERP's scheduler, built the same way, got the
   same line the same day (ERP `e3e39cf`, deployed 21 September behind a tested dump; `init=true`
   read back from the running container).
2. **No zombie build-up from background children.** The same line: docker-init reaps what
   `runInBackground` leaves behind. Measured, not assumed: 2 processes 34 minutes after the release,
   against the 2 a minute the unreaped container gathered before.
3. **Pids exhaustion cannot silently consume the container.** It can still happen in principle (a
   real leak of live processes), but it can no longer be silent: a scheduler that cannot fork writes no
   heartbeat, `/api/health` then answers 503 naming the scheduler, and the host monitor, which runs
   OUTSIDE the container and so does not die with it, texts `ALERT hq_scheduler` with the detail.
   Held by deploy-safety section 25 (the real monitor against stubs) and, since this milestone, by the
   application test below. HQ's own `hq:alerts:check` runs inside the scheduler and cannot report the
   scheduler's death; that is why the host monitor is the watcher of record for this failure.
4. **Deploy, rollback and verify-only keep the failure body.** Stream L: each reads health with
   `ignore_errors` and names the failing component in its FAIL or UNVERIFIED line. Held by
   deploy-safety sections 12 (deploy), 16b (rollback) and 23 (preflight).
5. **The host monitor keeps the 503's body and component name.** Held by section 25: a 503 naming
   the scheduler alerts `hq_scheduler` with "no tick recorded", never `hq_reachable`; the fallback
   through the app container still names it; only nothing answering at all is "unreachable".
6. **Health names the failing component.** `app/Http/Controllers/Health/HealthController.php`: each
   component carries its own status, the scheduler its `detail` or `last_tick_age_seconds`; any `fail`
   makes the whole answer 503.
7. **Scheduler health stays observable.** The same endpoint, the Platform dashboard that reads it, and
   the monitor.

**The gap, closed.** The deploy-safety suite exercises every reader against a STUB that answers 503
with `{"scheduler": {"status": "fail", "detail": "no tick recorded"}}`, but no application test held
the endpoint itself to that shape: the one existing test (`TwoFactorAndRbacTest`) checks only that the
keys exist, because on this laptop Redis and Horizon are absent and the endpoint answers 503 for them
anyway. New `tests/Feature/Observe/SchedulerHealthTest.php` pins Redis and Horizon healthy and
proves: a fresh tick answers 200 with its age; no tick answers 503 with exactly
`{"status": "fail", "detail": "no tick recorded"}` and every other component `ok`; a tick older than the
stale budget answers 503 with its age; the boundary (180 seconds alive, 181 failed, on a clock frozen to
a whole second, because the heartbeat is stored without microseconds). The behaviour already existed,
so the four tests passed first and are guards; their teeth were shown by mutation, each applied to the
controller, the file run, the controller restored: health answering 200 whatever failed (3 of 4
fail), the detail renamed (1 fails, the one the monitor's text depends on), the stale budget ignored
(2 fail). `vendor/bin/pint --test` passed; `tests/Feature/Observe` 93 passed.

### C.3 Stream N: the Phase 4 draft write, inert

To be written by the stream: today's behaviour read from both codebases, the design, then the
as-built record.

### C.4 The owner's steps

Each is Joy's because it needs his accounts, his 2FA session, or a production write this session's
permission classifier refuses. Each says why. None bypasses a readiness check; nothing here is a
made-up credential.

**1. The OpenAI key, from the account that has the credit (blocks Gate 11).** Why: the key stored as
row #18 belongs to another OpenAI account with no credit, so every model call answers 429
`credit_balance_exhausted`. The Bor de Guna organisation (sign-in `bordeguna@gmail.com`, credit about
$20.52, auto-reload off, organisation limit $100) is the business's own account, which also answers
the provider-ownership note of point 6: the gate's key belongs to the business, not to a person.
On platform.openai.com, in organisation "Bor de Guna", project "Bor de Guna HQ Automation": API keys,
create a secret key named `bordeguna-hq` (or use the existing "HQ Production" key ending `7j4A` if it
was kept). On https://hq.bordeguna.com/credentials (hard reload first): provider `openai_api_key`,
account ref `hq`, type `api_key`, group-wide, save. Storing is replacing: the row #18 is revoked in the
same transaction. Then, on the OpenAI account the old key came from, delete that key. After he says it
is stored, I run `hq:credentials:health --verify-model` (one call of 16 output tokens); it must read
`verified`, and that exact key is the one the gate uses.

**2. The P3-G1 production replay (one line).** Why: it proves on production's own database that the
conversation core's invariants hold (33 scenarios, two replays, rolled back, no model, no provider);
this session's classifier refuses it as a remote write although nothing survives the rollback.

```
! ssh vps 'cd /opt/bordeguna-hq && docker compose -f deploy/docker-compose.yml --env-file deploy/.env exec -T app php artisan hq:conversation:simulate --repeat=2'
```

Expected: every scenario passed, the two replays identical, no row-count difference, exit 0.

**3. The ERP read token for the Phase 4 reads.** Why: the five read tools answer 403 until HQ's ERP
token carries `inventory.view` and `sales.view`. Run it in HIS OWN terminal, not with `!`, so the token
is not printed into this chat:

```
ssh vps
cd /opt/bordeguna-erp/deploy
docker compose exec -T app php artisan hq:api:token hq@bordeguna.com 4 --name=hq-p4 --abilities=inventory.reports.view,sales.reports.view,crm.tickets.view,inventory.view,sales.view
```

The token prints once. On /credentials store it with the same provider and account as the current
`erp_api_token` row (#1, business `bor-de-guna`); storing replaces #1. Tell me, and I run
`hq:erp:probe` (a read). Only after the probe answers ok, revoke the old token:
`docker compose exec -T app php artisan hq:api:token hq@bordeguna.com 4 --revoke` (the default name
removes only the old `hq:business:4` token, never `hq-p4`).

**4. Meta Business Verification (Phase 3 live, and Phase 5).** Why: Messenger and Instagram inbound
cannot be switched on for real customers without it, and it takes days to weeks. As in `docs/82` S1.

**5. The browser walk, under his 2FA.** Why: no page added since 16 September has been seen in a real
browser by a person (this session cannot pass his 2FA). About ten minutes, reporting anything that
looks wrong:
- `/agents/runs/{any run}`: the execution-context panel (who asked, who enabled, businesses, tools);
- `/observe/autonomy-evidence`: "No autonomy evidence policy is active";
- `/observe/platform`: production `87d8658`, verified release, main ahead by documents only;
- `/observe/alerts`: what the first alert checks found after the release;
- `/approvals`: the requester and the enabler shown apart;
- `/credentials`: the OpenAI row after step 1, reading `verified`;
- `/horizon` in a fresh private window, signed in with password but BEFORE the 2FA code: it must send
  him back to the 2FA challenge.

**6. For Hudhud, the ERP's assistant (not HQ; recorded so all his steps are in one place).** The ERP
now runs Hudhud on OpenAI with its tools (ERP `2d4b66b`, deployed 21 September). A separate key from
the same organisation, project "Bor de Guna ERP Hudhud", pasted at
https://erp.bordeguna.com/settings/ai-providers (provider OpenAI (ChatGPT), model Provider default);
switch the Anthropic row off (Anthropic is still preferred while its row is active); press Test; ask
one question in English and one in Bangla.

**7. The release of the candidate (blocks Gate 11).** Why: Gate 11 must measure the build the shadow
week will run, and Stream M changes PHP. This session's classifier refuses `deploy.sh` as a production
deploy. The lines, from `deploy/README.md` "the release after `87d8658`":

```
ssh vps
cd /opt/bordeguna-hq
git fetch -q origin main
git rev-parse --short origin/main      # must print the candidate named in C.1's message to Joy
./deploy/deploy.sh --verify-only       # must end "RESULT: no failure found"
./deploy/deploy.sh
cat deploy/release-state/release.json  # released, verification passed, health_release = the candidate
```

### C.5 Stream O: the Phase 5 and 6 readiness packets

Merged `--no-ff` as `65c0c2b` (branch `docs/phase-5-6-readiness`, head `e35d288`); documents only,
no code, migration, flag or job, so no suite changes.

- **`docs/84`, Phase 5, marketing and ads (600 lines).** Built on what HQ already has
  (`Platform/Contracts/AdsPlatform.php` with its six operations, the capability router's refusal of
  two equally valid ad accounts, the descriptor vocabulary, V.1's approval for every R4 and R5). One
  section per bullet of point 4, in order, mapped at the end. Capabilities sketched: reading campaigns
  and insights R2; preparing a paused campaign, pausing, lowering a budget and uploading a creative
  R4; raising a budget and switching a campaign on R5; a customer-list upload R5 with per-person
  marketing consent and not in the first release. Gates P5-G0 (readiness) to P5-G5 (the first
  guardrail, a pause only). One correction made openly: `docs/16`'s examples that ran without approval
  ("budget up to 20%", "auto-pause") give way to V.1 until measured evidence exists.
- **`docs/85`, Phase 6, content factory and research engine (442 lines).** One section per bullet of
  point 5, plus the four rules at its end mapped to mechanisms (memory is not truth; model output is not
  trusted; untrusted content creates no authority; no open browsing: fetching only from the task's
  list and its own results). Research search and fetch R2; filing an artifact, a draft, an image or a
  card R3; a draft post R4; publishing and deleting a post R5, one approval per exact post, read back.
  Gates P6-G0 to P6-G5.
- **ADR-050** (paid media moves money only by a person's decision; billing, payment methods, the ad
  account spending limit, accounts, people, legal identity fields and deletion are never tools; the
  owner's spending limit in Meta is a ceiling HQ reads and never writes) and **ADR-051** (research is
  evidence, never truth; product facts in published content come only from the ERP or the shop),
  both proposed and indexed in `docs/32`.
- **External facts** were read from the providers' own pages on 21 September and cited; what could
  not be verified is marked in the text: bKash as a Meta ads payment method (press only; Meta's
  Bangladesh page lists cards and PayPal), special ad category rules for a Bangladesh-only campaign,
  VAT on Meta's invoices in Bangladesh, the data-protection position on hashed customer data in the
  Conversions API, an idempotency key in the Marketing API, Meta's verification document list for
  Bangladesh, App Review for publishing to the group's own Pages, Instagram story publishing and
  container lifetime, Tavily's storage terms, a Page publishing rate limit, LinkedIn's access process.
- **Found in the records:** the ad account records disagree (`docs/16` has one account billed in
  BDT; the LaLaDia hilsa account shows a $10-a-day budget).
- **Owner prerequisites added:** `docs/70` E5 to E7 (a system user in the business portfolio with its
  token on HQ; each ad account's brand, currency and spending limit; whether bKash is offered) and F7
  to F9 (Page publishing permissions and tokens; Instagram professional accounts; a content brief and
  claims policy per product line); `docs/82` S1 sourced (Meta: "may take up to 14 business days") and
  sections 5 and 7 (decisions 6 and 7: the ads money and the content claims).
- **Left for Joy:** which ad account belongs to each brand and its currency; the monthly ads budget
  per business; whether delivered purchases are sent to Meta and with which customer fields; the
  claims policy per product line; the search provider and a plan that allows storing results; the
  source list; who approves posts per brand; how long unapproved drafts are kept. **For ChatGPT:**
  whether V.1 replaces `docs/16`'s automatic examples; publishing at R5 (proposed) or R4; whether
  switching a campaign on may ever rise above A2.
