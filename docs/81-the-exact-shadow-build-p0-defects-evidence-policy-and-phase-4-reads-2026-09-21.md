# 81 - Preparing the exact shadow build: the P0 defects, the execution context on the run page, deploy verify-only, the autonomy evidence policy and producer, and Phase 4's read side (21 September 2026)

**Status:** the plan for the milestone ChatGPT ordered on 21 September in answer to the `docs/80`
end-of-milestone report, written before the code, then the record as each stream completes. Nothing
here activates anything.

**Read first:** `docs/80` (the previous milestone, its findings and section D), ADR-047 (Effective
Execution Context), ADR-023 and ADR-030 (autonomy and evaluation dimensions), ADR-045 (the shadow
period), `docs/79` and ADR-046 (Phase 4 design), `docs/58` H.1 (the backup-first invariant).

## A. The decision that changes the sequence, and the instruction verbatim

The `docs/80` report put a sequencing question to ChatGPT: gate 11 measures release `3314c54`, but the
release after it carries the Effective Execution Context, which changes how the runner takes
authority. ChatGPT chose the strengthened form of option (c): **the next safety measurement is
performed on the same build that becomes the Executive's A0 shadow build.** Main is not promoted as it
stands, because the last milestone found defects in existing code, three of them in the control plane
(a disabled user can still sign in; a 60-second business-scope cache; a rollback script that disagrees
with reality). So: fix those, verify locally, release the exact candidate inert, verify production,
then one fresh Gate 11 attempt 2 on that build. It stays Gate 11. This supersedes the "use release
`3314c54`" line of the 16 and 20 September instructions.

Its other answers, in short: the autonomy evidence policy is built now as versioned, governed
configuration with the missing producer, and **no numeric threshold is invented** (values stay
proposed and inactive until real shadow evidence and an owner decision); the briefing pipeline is
approved exactly as implemented (deterministic composition, narration as a separate optional step over
a stored bundle); `--by <a real person>` becomes mandatory for opening a production shadow, and
`as_enabled` may never open one. The instruction itself:

> I have reviewed the complete milestone.
> 
> Continue at full speed, but do NOT promote the current main to production unchanged. We have several known control-plane defects that must be corrected before the next production candidate.
> 
> ## 1. Gate-11 sequencing decision
> 
> I choose the strengthened form of option (c).
> 
> The next safety measurement must be performed on the same build that will become the Executive A0 shadow build.
> 
> Therefore:
> 
> **Do not run Gate 11 on old release `3314c54` if the shadow would later run a different main build.**
> 
> First correct the priority control-plane defects below.
> 
> Then:
> 
> 1. local exhaustive verification
> 2. promote the exact resulting main candidate
> 3. verify production
> 4. run one fresh Gate-11 attempt
> 5. if the complete Gate-11 measurement passes, proceed to the explicitly authorized A0 shadow opening
> 6. if it fails, remain inert
> 
> This remains **Gate 11**, not Gate 12.
> 
> The earlier provider-failure runs remain historical and untouched.
> 
> Do not change prompts or safety semantics merely to improve the gate result.
> 
> ---
> 
> # 2. Fix these existing defects before the next production candidate
> 
> ### P0-1: Disabled users must not authenticate
> 
> `users.is_active = false` must be honored by authentication.
> 
> Implement and test:
> 
> * disabled user cannot login
> * existing authenticated session is invalidated or rejected appropriately when the account becomes inactive
> * enabled user continues to authenticate
> * audit records remain correct
> 
> Do not create a bypass path.
> 
> ### P0-2: Business-scope cache must not produce stale authorization
> 
> The current 60-second `BusinessScope` cache is unacceptable for security-sensitive scope resolution.
> 
> Change it so that:
> 
> * scope mutation invalidates relevant cache immediately, OR
> * security-sensitive resolution bypasses stale cache
> * a newly narrowed scope takes effect immediately
> * a newly granted scope cannot unexpectedly appear before authorization state says it is valid
> * gateway scope checks use authoritative current state
> 
> Add regression tests for immediate narrowing/widening.
> 
> ### P0-3: Fix rollback contract
> 
> `rollback.sh`, Docker/compose configuration, README and actual behavior must agree.
> 
> A rollback must not unexpectedly run migrations because of an inherited `RUN_MIGRATIONS=1`.
> 
> Establish one explicit rollback invariant, test it, and document it.
> 
> ### P0-4: Fix approval requester identity
> 
> An approval created by an agent must record the accountable requester/asker correctly.
> 
> Do not record the enabling user as the requester merely because the enabler authorizes execution.
> 
> Preserve separate concepts:
> 
> * requester
> * enabler
> * acting identity
> * approver
> 
> Add regression coverage and ensure existing historical records remain unchanged.
> 
> ### P1-1: Deterministic query ordering
> 
> Fix `facts.query_orders` tie handling.
> 
> Grouping/order must be stable when metric values tie.
> 
> Add a deterministic secondary key and regression coverage.
> 
> ---
> 
> # 3. Build the generic Effective Execution Context into the UI/audit surface
> 
> Add the resolved `eec.v1` context to the relevant agent run detail.
> 
> Show safe, non-secret information such as:
> 
> * requester
> * enabling identity
> * acting identity/context
> * business scope
> * brand scope
> * applicable agent
> * resolved tool reach
> * context digest
> * resolution timestamp
> 
> Do not expose credentials or hidden secrets.
> 
> This is read-only observability.
> 
> ---
> 
> # 4. Build deploy verify-only mode
> 
> Add a verify-only mode to the deployment tooling that performs validation without changing:
> 
> * checkout
> * containers
> * database
> * release records
> * production state
> 
> It should validate as much of the real deployment preflight as possible and fail with a specific reason.
> 
> This is an operational safety tool, not a replacement for actual deployment.
> 
> ---
> 
> # 5. Build the autonomy evidence threshold mechanism now
> 
> APPROVED.
> 
> Create versioned, governed autonomy-evidence policy configuration independent of individual grants.
> 
> The policy model should support at minimum:
> 
> * autonomy level
> * evaluation dimension
> * minimum agreement rate
> * minimum evidence/sample count
> * recency constraint
> * required measured state
> * hard safety requirements
> 
> An unmeasured required dimension must not satisfy eligibility.
> 
> A single overall score must never substitute for missing dimension-specific evidence.
> 
> However:
> 
> **Do not invent final numeric thresholds automatically.**
> 
> The schema, producer, resolver integration and UI may be built now, but initial numeric policy values must be explicitly governed before they become effective.
> 
> Build the missing evidence producer so AgentRunner can receive the applicable dimension/rate evidence when evaluating an autonomy grant.
> 
> Keep production autonomy grants at zero.
> 
> ---
> 
> # 6. Keep the M7 briefing bundle as the canonical Executive briefing contract
> 
> APPROVED exactly as implemented.
> 
> The standard briefing architecture is:
> 
> trusted briefing task
> → deterministic typed EvidenceBundle
> → completeness/provenance/freshness validation
> → persisted briefing artifact
> → optional LLM narration
> → final human-readable briefing
> 
> The narrator must not become the source of facts.
> 
> Keep:
> 
> * lineage
> * source references
> * as-of timestamps
> * freshness
> * deterministic PHP calculations
> * no partial group totals
> * no fabricated zeroes
> * unsourced-number blocking
> 
> Keep narration behind its flags and OFF.
> 
> Do not create the scheduled standard briefing job yet.
> 
> Do not implement TaskScope heuristics.
> 
> ---
> 
> # 7. Continue M6 inertly
> 
> Keep:
> 
> * Ask HQ
> * strict JobSpec
> * deterministic validation
> * NL scheduler
> * preview/one-time token
> * approval workflow
> * effective execution context intersection
> 
> Do not enable `ask_hq`.
> 
> Do not enable `scheduler.nl`.
> 
> Do not allow any direct model → tool path.
> 
> ---
> 
> # 8. Continue Phase 3 inertly
> 
> Continue the safe Phase 3 work.
> 
> Maintain:
> 
> * channel-neutral contracts
> * customer memory labelled as memory
> * untrusted inbound provenance
> * conversation/handoff state machine
> * Instagram/Facebook normalizers
> * simulation environment
> * P3-G3 pairing infrastructure
> * Support evaluation fixtures
> * runbooks
> 
> Do not enable:
> 
> * Meta inbound
> * real outbound messaging
> * customer production traffic
> * Support production activation
> 
> ### P3-G1
> 
> Run/close the Conversation Core gate where the current release permits.
> 
> Do not confuse P3-G1 with Support-agent safety.
> 
> ### P3-G2
> 
> Prepare the Support-agent gate using the simulation world.
> 
> Use Joy as the explicitly approved enabling user only when the actual Support evaluation is run.
> 
> Keep Support paused.
> 
> ---
> 
> # 9. Phase 4: build read-side ERP prerequisites
> 
> Continue Phase 4 at design/read-only implementation level.
> 
> The canonical future capability remains:
> 
> `take_order`
> 
> behind the ERP Sales Order capability.
> 
> Now implement only read-side prerequisites:
> 
> * product lookup
> * variant/SKU lookup
> * current price lookup
> * stock/availability lookup
> * relevant customer/order eligibility facts
> 
> All through the existing governed read interfaces.
> 
> No order creation.
> 
> No order modification.
> 
> No autonomous commerce write.
> 
> The future write remains behind the ERP Sales Order capability and its own gates.
> 
> ---
> 
> # 10. Credential/readiness model
> 
> Continue the readiness state design.
> 
> Keep distinct:
> 
> * credential_missing
> * credential_unverified
> * credential_invalid
> * provider_blocked_by_credit
> * provider_unauthorized
> * provider_unavailable
> * connector_error
> * verified
> 
> A provider-credit problem must never condemn the credential itself.
> 
> Never expose credential values.
> 
> ---
> 
> # 11. Shadow Probes
> 
> Keep the twice-daily production Shadow Probes.
> 
> Each is an explicit trusted task, not open-ended permission.
> 
> Required production shadow behavior:
> 
> * exact task recorded
> * normal AgentRunner path
> * normal EffectiveExecutionContext resolution
> * normal Gateway
> * complete evidence required
> * missing probe evidence prevents shadow completion
> 
> Keep `--no-schedule` for test/local use only.
> 
> For production shadow opening, scheduled probes are mandatory.
> 
> ---
> 
> # 12. Production A0 activation
> 
> Joy has already given the conditional authorization.
> 
> When and only when the fresh Gate-11 run on the exact candidate build fully passes:
> 
> * require explicit human `--by`
> * enable `ai.runtime`
> * activate Executive at A0
> * open seven-day shadow
> * keep Analytics paused
> * keep Support paused
> * grants = 0
> * Meta inbound = OFF
> * outbound = OFF
> * business writes = OFF
> 
> `--by` is mandatory for production.
> 
> Do not permit `as_enabled` as an implicit production shadow opener.
> 
> The shadow-opening action must be audited under the named human.
> 
> ---
> 
> # 13. Do not automatically promote after the shadow week
> 
> The seven-day shadow produces evidence.
> 
> It does not automatically:
> 
> * create an autonomy grant
> * widen autonomy
> * enable writes
> * enable customer messaging
> * activate Support
> 
> The next autonomy decision is a separate governed step.
> 
> ---
> 
> # 14. CI constraints
> 
> Until 1 October:
> 
> * local exhaustive verification is the temporary merge gate
> * report it as local verification, not CI green
> * run Pest
> * Pint
> * strict TypeScript
> * Vite
> * relevant production/build tests
> * deploy-safety tests
> 
> On 1 October:
> 
> * run full CI against main
> * reconcile every failure
> * do not suppress CI-only failures
> 
> Do not claim Linux deploy-safety parity until the actual Linux runner has passed.
> 
> ---
> 
> # 15. Historical evidence
> 
> Preserve all prior Gate 1–11 records and traces.
> 
> Do not rewrite:
> 
> * Gate 7
> * Gate 8
> * Gate 9
> * Gate 10
> * Gate 11 attempt 1
> 
> The provider-failure Gate-11 run remains historical evidence.
> 
> The next complete measurement is Gate 11 attempt 2.
> 
> ---
> 
> # 16. Priority ordering
> 
> Proceed in this order while the Anthropic provider remains blocked:
> 
> ### First:
> 
> P0 control-plane defects:
> 
> * disabled authentication
> * stale business scope cache
> * rollback invariant
> * approval requester identity
> 
> ### Then:
> 
> * deterministic query ordering
> * EffectiveExecutionContext UI
> * deploy verify-only
> * autonomy evidence threshold/producer
> 
> ### In parallel:
> 
> * M7 briefing bundle/lineage
> * M6 inert work
> * Phase 3 inert work
> * P3-G1/P3-G2 preparation
> * Phase 4 ERP read-side foundation
> 
> Do not block safe engineering on Anthropic credit, Meta App Review, or GitHub Actions availability.
> 
> ---
> 
> # 17. Gate 11 attempt 2
> 
> When the exact production candidate is ready AND Anthropic capacity is restored:
> 
> * verify provider HTTP 200
> * verify sufficient credit for the complete suite
> * deploy the exact candidate using backup-first controls
> * run one locked Gate-11 attempt 2
> * require zero unmeasured cases
> * do not tune after seeing the first result
> * report all suites/families/dimensions
> * report measured vs unmeasured
> * report runtime/provider failures separately
> * stop for the activation decision
> 
> If it passes, use the conditional A0 process above.
> 
> If it fails, remain inert.
> 
> ---
> 
> # 18. Next report
> 
> Provide one cumulative report covering:
> 
> 1. P0 defect fixes
> 2. production candidate commit/release
> 3. Effective Execution Context
> 4. deploy verify-only
> 5. autonomy evidence policy/producer
> 6. M7 briefing bundle
> 7. M6 status
> 8. P3-G1/P3-G2 status
> 9. Phase 4 read foundation
> 10. Anthropic capacity
> 11. Gate-11 readiness
> 12. local verification status
> 13. main vs production state
> 14. A0 activation/shadow status
> 15. exact remaining blockers
> 
> Continue without waiting for another daily instruction.
> 
> Do not activate any production AI capability unless the explicit Gate-11 pass and `--by` conditions above are satisfied.


## B. The plan (written before the code)

Five streams in parallel worktrees (`hq-wt/e..i`, test databases `hq_test_e..i`), one branch each,
each planned in section C before its code, each reviewed and merged `--no-ff` only on full local
verification, then the combined tree verified as a whole (the last milestone's one defect appeared
only in the combined tree).

| Stream | Branch | Instruction points |
| --- | --- | --- |
| E. Disabled users cannot authenticate; business scope is never stale; a production shadow needs `--by` | `fix/auth-scope-and-shadow-opener` | P0-1, P0-2, 11, 12 |
| F. The approval's requester is the asker; stable ordering in `facts.query_orders`; the execution context on the run page | `fix/approval-requester-ordering-and-run-context` | P0-4, P1-1, 3 |
| G. One rollback invariant; deploy verify-only | `deploy/rollback-invariant-and-verify-only` | P0-3, 4 |
| H. Versioned autonomy evidence policy, the evidence producer, resolver integration, the view | `autonomy/evidence-policy-and-producer` | 5 |
| I. Phase 4 read side: product, variant, price, stock and eligibility lookups through governed reads | `p4/erp-read-foundation` (HQ) and a branch in the ERP repository | 9 |

Rules every stream keeps: a correction is watched failing first; no prompt file changes; nothing is
switched on; no grant, flag row or scheduled job is created; historical rows are never rewritten;
no invented business rule or number; migrations additive and rehearsed apply, roll back, re-apply on
Postgres.

After the merges: full local verification of the combined tree, the release candidate named, and
the by-hand release steps handed to Joy (deploy and gate launch are his to type). Gate 11 attempt 2
still needs Anthropic capacity, which is his.

## C. Record

(filled in per stream as it completes)

### C.2 Stream F: the approval's requester is the asker; stable ordering in the facts tools; the execution context on the run page (branch `fix/approval-requester-ordering-and-run-context`, points P0-4, P1-1 and 3)

#### C.2.1 Findings, read from the code before any change (20 September)

**P0-4, the approval's requester.**

- `app/Gateway/Approval/ApprovalService.php:76` writes
  `'requested_by_user_id' => $request->identity->enablingUser()?->getKey()`. For a human caller
  `enablingUser()` is the person on the screen and for a scheduled job it is the job's owner, which
  is right. For an agent, `ActingIdentity::forExecution()` (`app/Gateway/Identity/ActingIdentity.php:48`)
  puts `$context->enabler` in that slot, so an approval an agent parks names the ENABLING user as
  the requester, whoever asked. The person who asked (`$context->requesterUser`, ADR-047) is on the
  identity and is not read.
- Every reader of `requested_by_user_id`, all of them:
  `ApprovalService.php:76` (write) and `:180` (copied to the successor of an approve-with-edit);
  `ApprovalController.php:49,58,83` (the email shown as `requested_by`) and `:179` (the requester may
  cancel their own request); `Approval.php:27,59` (fillable, the `requestedBy` relation);
  `Tools/Hq/ListApprovalsTool.php:115` and `Briefing/Sections/PendingApprovals.php:40,73` (both show
  the agent key, or "a person" or "system", never the id); `resources/js/pages/approvals/index.tsx:186`
  and `show.tsx:188-193` (an agent's approval shows "the X agent" and never the person).
- It is NOT read by: the payload hash (`CanonicalPayload::hash($payload)` covers the stored payload
  and nothing else), `ApprovalGuard`, `ApprovalExecutor`, the notifier (`Notifier.php:30-62` names
  the tool, the risk, the business and the deadline), the SLA and expiry sweeps, or the audit card
  (`Approval::toCard()` does not carry it).
- ⚠ **There is no self-approval rule today.** `Approval::isDecidableBy()` (`Approval.php:130`) asks
  two things: does the user hold `hq.approvals.decide`, and is the approval delegated to somebody
  else. `docs/48` G.4 records the owner's decision (Q22): Joy holds every approval right so no queue
  is blocked by an absent approver. So today the single owner parks a call on a screen and approves
  it himself, and the whole of `ApprovalCenterTest` does exactly that. That behaviour is kept byte
  for byte.
- ⚠ **The defect is latent in production.** No registered tool is both agent-executable and
  approval-gated (`ApprovalCenterTest`: "it still enables no R4 or R5 tool for an agent"), so no
  agent has ever parked an approval and no historical agent row exists to misread. The first
  tool that is both would have met the wrong requester. The regression test registers its own R4
  agent-executable test tool.
- The check that decides who may approve lives in the controller (`assertDecidable`), not in
  `ApprovalExecutor::approveAndExecute()`. A rule added only to `isDecidableBy()` would be skipped
  by any later caller of the executor, so the rule is also enforced in the executor, before the claim.

**P1-1, ordering.**

- `QueryOrdersTool.php:279-281`: grouped rows are ordered by `<metric> desc nulls last` and nothing
  else, then `limit`. Two groups with the same metric return in whatever order the plan produced,
  and when the tie sits at the limit, which group is IN the answer is chance.
- The same family in the other tools:
  `SnapshotFreshnessTool::orderFacts()` (`:141-157`) has a `GROUP BY` and no `ORDER BY` at all;
  `ReadErpSnapshotTool.php:106` `latest('polled_at')->first()` (one row per business and kind by a
  unique index, so a tie cannot occur, left alone);
  `ListJobsTool.php:98` `orderBy('name')` with a limit and no unique index on the name;
  `RecentEventsTool.php:102` groups by type and status and orders by type only, so `by_status`
  keys arrive in either order. `erpSnapshots()` (`:126`) orders by slug and kind, which is unique.
  `ListApprovalsTool`, `ReadThreadTool` and the briefing sections already end on the id.
- `ExecutionContextIdentityCaseTest` pins the first-turn prompt (the catalogue with every schema and
  enum) and the second-turn message (every tool result) by sha256. So the input schema, the
  descriptor and the tie-free output of `facts.query_orders` and `hq.list_jobs` must not move by a
  byte: no new always-present output key.

**Point 3, the context on the run page.**

- `ExecutionContextResolver::record()` (`:71-89`) stores `toAuditArray()` on
  `agent_runs.execution_context` and its digest, and writes `agent_run.context_resolved` to the
  audit log. `AgentController::show()` (`:120-151`) sends neither to the page.
- The stored record holds no timestamp. The audit row written in the same call does
  (`audit_logs.occurred_at`, target the run). The page reads that, and says so when it is absent.
- ⚠ **A refused run has no row.** `AgentRunner::run()` resolves the context at `:242`, BEFORE
  `AgentRun::create` at `:257`; a refusal throws and only `agent_run.context_refused` is written to
  the audit log, against the agent. There is no run page for a refusal to appear on, so the page
  shows nothing about refusals and invents nothing.
- The run page shows no person today (no name, no email). The Approval Center shows emails. The new
  panel shows a name and the user id, no email.

#### C.2.2 The design (written before the code)

**P0-4.** Additive, nullable, nothing rewritten. One migration adds to `approvals`:

| Column | Meaning | Historical rows |
| --- | --- | --- |
| `enabled_by_user_id` (FK users, null on delete) | the enabling user of the agent that parked it | null |
| `requester_kind` (string 24) | `human`, `on_behalf_of`, `gate_runner`, `console`, from the run's resolved context | null |
| `execution_context_digest` (char 64) | the `eec.v1` digest the call ran under, which joins the approval to `agent_runs.execution_context_digest` | null |

`requested_by_user_id` keeps its name and becomes what it says: for an agent call it is
`$identity->execution->requesterUser`; for every other caller it is what it was. The four concepts
are then: requester `requested_by_user_id`, enabler `enabled_by_user_id`, acting identity `agent_key`
(already there), approver `decided_by_user_id` (already there). The successor of an
approve-with-edit copies the three new columns. The payload, the hash, the guard and the claim are
not touched (ADR-018 unchanged).

A row with an `agent_key` and a null `enabled_by_user_id` was recorded before the two were kept
apart. None exists in production (see above); the screens say "recorded before requester and
enabler were kept apart" for such a row rather than presenting the person as the asker.

**Separation of duties, decided conservatively.**

1. *An agent parked it and the person who asked is NOT the enabling user:* the person who asked may
   not approve, reject or edit it, and it may not be delegated to them. They asked through an agent
   that borrows somebody else's authority; deciding it too would put the ask and the yes in one
   pair of hands with the enabling person never having looked. They may still withdraw (cancel)
   their own request, as `docs/48` G.3 says.
2. *The enabling user, when somebody else asked:* MAY decide it. The narrower rule (the enabler may
   not) was weighed and not taken: the requester and the approver are already two different people,
   so it adds no second pair of eyes, and in the production setup the owner is the enabling user of
   every agent and the only holder of `hq.approvals.decide`, so the narrower rule would leave an
   approval nobody can decide, against the recorded decision Q22 (`docs/48` G.4). Listed under
   decisions to revisit for when there is a second approver.
3. *Requester = enabler (the single owner, the gate runner, a probe of a period he opened, any
   `as_enabled` run):* unchanged. One person holds the whole authority, exactly as when he parks a
   call on a screen himself. Byte for byte today's rule.
4. *Non-agent callers (a person on a screen, a scheduled job with an owner, the console):*
   unchanged, the new columns stay null, the rule does not apply. Proven by tests.
5. *Historical rows (null `enabled_by_user_id`):* the rule does not apply; they decide as they did.

Enforced in `Approval::isDecidableBy()` (the screen's `decidable`, `assertDecidable`, `cancel`) AND
in `ApprovalExecutor::approveAndExecute()` before the claim, AND in `ApprovalService::delegate()`.
The enabling user may also still withdraw an approval their agent parked (they could before, as
the recorded "requester"; withdrawing executes nothing).

**P1-1.** `facts.query_orders`: the grouped query becomes an inner query, and the outer one orders by
`sort_metric desc nulls last`, then the group key ascending: numerically for `business` and `store`
(the key is the id), and as text under `COLLATE "C"` for every other grouping, so the order does not
depend on the database's locale. The tool fetches `limit + 1` rows; when the first row left out has
the same metric as the last row kept, `coverage.tie_at_limit` says so (the metric, the value, how
many groups share it, how many of them were returned, and the rule that chose). The key is present
ONLY when a tie was cut, so a tie-free result is byte for byte what it was. No schema or descriptor
change. `SnapshotFreshnessTool::orderFacts()` orders by business slug then store slug;
`ListJobsTool` adds the id after the name; `RecentEventsTool` adds the status after the type.

**Point 3.** No new route; `AgentController::show()` adds one prop, `execution_context`, under the
existing `hq.agents.view` and the existing business-scope 404. It is one of:
`{state: 'recorded', ...}` built from the stored record (names looked up for the two people,
business and brand names for the ids, the tools, the narrowings in words, the digest, the
resolution time from the audit row), or `{state: 'before_adr_047'}` for a run with no record, which
the page renders as "recorded before execution contexts existed" and never as an empty panel.
Plain sentences first; the digest, the permission names, the raw record and the per-call terms sit
in the page's existing `TechnicalDetail`. No emails, no credentials (the record holds none, and a
test asserts the prop carries no email).

#### C.2.3 As built (20 September)

**P0-4.** Migration `2026_09_21_160000_add_requester_identity_to_approvals` adds the three nullable
columns of C.2.2 and nothing else; rehearsed on Postgres `hq_test_f`: applied, rolled back (columns
gone), re-applied (columns back). `ApprovalService::park()` (`ApprovalService.php:70-88`) reads the
resolved context off an agent identity: requester `requesterUser`, enabler, requester kind and the
context digest. Every other caller takes the old branch unchanged. The successor of an
approve-with-edit copies the new columns (`:190-191`). `Approval::separationOfDutiesReason()`
(`Approval.php:153`) is the one rule, read by `isDecidableBy()` (`:177`), by
`ApprovalExecutor::approveAndExecute()` before the claim (`ApprovalExecutor.php:52`, so a refusal
leaves the approval pending for somebody else), by `ApprovalService::delegate()` (`:280`) and by the
controller's `assertDecidable` (`ApprovalController.php:228`, which gives the person the reason in
the 403). `cancel` also lets the enabling user withdraw. The Approval Center shows "Proposed by the
X agent" and, beside it, who asked the agent and who enabled it (emails, as that screen already
shows people); a row with an agent key and no enabler says "Recorded before requester and enabler
were kept apart" (`requester_recorded`). The payload, the hash, `CanonicalPayload`, `ApprovalGuard`
and the claim are untouched; a test holds that the same arguments hash the same whoever asked.

**P1-1.** `QueryOrdersTool::rows()` (`QueryOrdersTool.php:285-335`) groups in an inner query and
orders outside it by `sort_metric desc nulls last`, then `keyOrder()` (`:376`): `group_key::bigint`
for business and store, `group_key collate "C"` for everything else, ascending, nulls last. It
fetches `limit + 1`; `tieAtLimit()` (`:390`) adds `coverage.tie_at_limit` (metric, value, limit,
`groups_sharing_the_value`, `of_which_returned`, `chosen_by`, a note) only when the first row left
out ties with the last row kept. The units expression became one constant so the column and the
sort read the same thing. The descriptor, the input schema and a tie-free output are unchanged, and
the four pinned digests of `ExecutionContextIdentityCaseTest` stayed green WITHOUT re-pinning.
`SnapshotFreshnessTool::orderFacts()` now orders by business slug then store slug;
`ListJobsTool` by name then id; `RecentEventsTool`'s summary by type then status.

**Point 3.** `App\Execution\ExecutionContextView` shapes the stored record for the page and
resolves nothing. `AgentController::show()` (`:147-153`) sends it as `execution_context` under the
existing `hq.agents.view` and the existing business-scope 404; no new route. The panel
(`resources/js/components/agents/execution-context-panel.tsx`, shared `SectionHeader`,
`StatusBadge`, `TechnicalDetail`, `JsonBlock`) says in sentences who asked and on what basis, who
enabled, what acted, the entry point and environment, the businesses and brands, the tools, what
narrowed it and why, and when it was resolved (the `agent_run.context_resolved` audit row's
`occurred_at`; "the time was not recorded" when that row is missing). The digest, the permission
names and the per-call terms are folded away. A run with no record says "Recorded before execution
contexts existed ... a gap in the record, not a statement that the run had no limits". People are a
name and a user id, never an email. A business or brand outside the VIEWER's own scope is counted,
not named. **Refusals are not shown, because a refused context has no run row:** it exists only as
`agent_run.context_refused` in the audit log, against the agent.

**Watched failing first.** `ApprovalRequesterIdentityTest`: 10 of 15 failed before the change (the
record, the successor, the requester barred on the screen and in the executor, the delegate, the
cancel, the screen props, the migration), and the 5 that passed are the ones that prove nothing
changed for a person on a screen, a scheduled job, the payload hash and a third approver.
`DeterministicOrderingTest`: all 7 groupings, business and store, metric-first, and the tie at the
limit failed before; `hq.list_jobs` failed before. ⚠ Two did NOT fail before and are kept as guards
only: the `facts.snapshot_freshness` and `hq.recent_events` cases passed against the old code,
because Postgres happened to sort for the GROUP BY on this data. The missing ORDER BY is still a
missing guarantee, and it is fixed, but it was not seen failing. `RunExecutionContextPanelTest`:
5 of 6 failed before; the sixth (no permission, another business's run) passed before, as it should.

**Decisions to revisit.**

1. The enabling user MAY approve what another person asked their agent for. Revisit when a second
   person holds `hq.approvals.decide`: the narrower rule (neither the requester nor the enabler)
   becomes workable then.
2. The separation rule does not apply when requester and enabler are one person, nor to a person
   who parks a call on a screen and approves it (there is no self-approval rule there today, Q22).
   That is the single-owner setup kept byte for byte, not a judgement that it is ideal.
3. The enabling user may withdraw an approval their agent parked even without the decide permission.
4. `coverage.tie_at_limit` is reported; a cut between DIFFERENT values is still not (the tool has
   never said that rows were left out by the limit). Worth a `truncated` key in a change that may
   re-pin the identity digests.
5. The stored `eec.v1` record has no `resolved_at` of its own; the page reads the audit row.
6. The day, week and month groupings are ordered by the metric, not by date. Existing behaviour,
   left alone here; a date series probably wants date order.

**Not done.** The run page and the approval screen were not walked in a browser at 390px: there is
no local HQ database other than the test one, which the suite wipes. The panel uses the same grid,
`min-w-0` and `break-words`/`break-all` as the rest of the page; that is construction, not evidence.

**Verification (20 September, local; GitHub Actions deferred until the 1 October reset).** Full Pest suite on Postgres `hq_test_f`: **1012 passed, 8282 assertions, 0 failed** (base 977, plus 35: 15 approval requester identity, 14 deterministic ordering, 6 run page context). `vendor/bin/pint --test`: passed. `npx tsc --noEmit`: clean. `npm run build`: built. The identity-case digests: green, not re-pinned.
