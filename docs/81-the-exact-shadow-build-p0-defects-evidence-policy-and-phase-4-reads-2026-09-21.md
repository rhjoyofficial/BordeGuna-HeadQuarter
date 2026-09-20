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

### C.4 Stream H: the autonomy evidence policy, the producer, the resolver link and the view (branch `autonomy/evidence-policy-and-producer`, point 5, respecting point 13)

**The plan, written before the code.**

*Findings, read from the code first.*

1. **No required rate exists per level or per dimension.** The only requirement anywhere is a number
   on one grant: `autonomy_grants.min_agreement_rate` with `min_agreement_dimension` and
   `evaluation_suite_key` (`database/migrations/2026_09_09_310000_create_autonomy_grants_table.php`,
   `2026_09_10_200000_add_dimensions_to_evaluation.php:44`). `AutonomyEvidence.php:46-48` says so in
   its own header, and `docs/80` C.3 listed it as a decision for Joy and ChatGPT.
2. **The mechanical link has no producer.** `AgentRunner::resolveAutonomy()` (`AgentRunner.php:856-866`)
   builds an `AutonomyRequest` with no `agreementRate` and no `dimensionRates`, and nothing in `app/`
   builds either. `AutonomyResolver::hasEvaluationEvidence()` (`AutonomyResolver.php:209-231`) then
   reads null and the grant does not match. Fail closed, and ADR-023's link is unreachable.
3. **The resolver ignores a grant's `evaluation_suite_key`.** `hasEvaluationEvidence()` never reads it,
   so a grant naming a suite would be compared with whatever pooled rate the caller passed. Harmless
   while nothing passes a rate; it matters the moment a producer exists, so it is closed here.
4. **The per-check record already exists.** `DeterministicGrader::CHECKS` (`DeterministicGrader.php:51`)
   names seventeen checks (`must_not_call`, `no_tool_calls`, `no_denied_calls`, `task_grounding`,
   `security_intent`, `prohibited_capability`, `no_substitution`, `refusal`, `covers_businesses`,
   `scope_bound`, `tool_calls`, `citations`, `outcome`, `agent_woken`, `only_tools`, `replies`,
   `reply_script`); every result stores `checks` as `check => pass|fail|not_applicable`
   (`EvaluationRunner.php:126,147`) and the run stores `check_rates` (`EvaluationRunner.php:163-168,212`).
   A hard safety requirement references a check by one of those keys, and is recounted from the
   result rows, never read from `check_rates`.
5. **The append-only pattern** is a plpgsql function that raises, bound by `BEFORE UPDATE` and
   `BEFORE DELETE` triggers (`audit_logs` in `2026_09_05_100100`, `consent_events` in `2026_09_09_300000`,
   with restrict-only foreign keys because a cascade is a write the trigger refuses). Reused here.
6. **The audited named-human act** is the `hq:shadow:open` shape: dry run by default, `--apply`,
   `--by <email>`, `--reason`, `Auditor::record(..., actorUserId)`.
7. **The identity case** (`tests/Feature/Security/ExecutionContextIdentityCaseTest.php`) pins the
   system prompt, both turns and the recorded calls. It holds no grant and no policy, so the rule
   below ("no active policy: the request and the decision are exactly today's") keeps it green with
   no re-pin.

*Schema (one additive migration, Postgres triggers, rehearsed apply, roll back, re-apply).*

- `autonomy_evidence_policies`: `id`, `uuid`, `version` (unique, assigned in order), `name`,
  `summary`, `status` (`proposed` | `active` | `retired`), `supersedes_id`, `proposed_by`,
  `proposed_at`, `activated_by`, `activated_at`, `activation_reason`, `retired_by`, `retired_at`,
  `retirement_reason`, `content_digest` (sha256 of the canonical requirement set, written at
  activation), timestamps. A partial unique index allows **at most one `active` row**.
- `autonomy_evidence_requirements`: `policy_id`, `autonomy_level` (`a1`..`a4`; A0 needs nothing, A5
  is refused), `dimension` (one of the six ADR-030 dimensions; there is deliberately **no `overall`
  row**, because ADR-030 rejected the single number), `min_agreement_rate` decimal(4,3) **nullable =
  not decided**, `min_sample_count` **nullable = not decided**, `max_evidence_age_days` **nullable =
  not decided**, `required_measured_state` (only value today: `fully_measured`; a laxer state needs
  its own ADR), `note`. Unique on (policy, level, dimension).
- `autonomy_evidence_hard_checks`: `policy_id`, `autonomy_level`, `check_key` (a key of
  `DeterministicGrader::CHECKS`), `min_sample_count` and `max_evidence_age_days` (both nullable = not
  decided). The rate is not a column: a hard check is 100 percent or it fails.
- `autonomy_evidence_policy_events`: append-only history (`policy_id`, `event` proposed | activated |
  retired, actor user id and label, reason, a snapshot of the whole policy, its digest,
  `occurred_at`). `BEFORE UPDATE` and `BEFORE DELETE` refuse, as on `consent_events`.
- Database-level immutability: a trigger on the policy table refuses every change to a `retired` row,
  allows an `active` row only the move to `retired` (and only the retirement columns), refuses
  deleting anything that was ever active, and **refuses the move to `active` when the policy has no
  requirement row, when any numeric field of a requirement or hard check is NULL, or when
  `activated_by`, `activated_at` or `activation_reason` is missing.** Triggers on the two child tables
  refuse INSERT, UPDATE and DELETE once the parent is `active` or `retired`.
- `agent_runs.autonomy_evidence` jsonb nullable: which policy version and which evidence the run's
  autonomy decision was judged on. Null when no policy is active, so today's rows do not change.

*Governance.* `App\Autonomy\Evidence\EvidencePolicies` is the one writer: `propose()` (from a JSON
file, numbers may be null), `activate()`, `retire()`. Console only, the narrower surface:
`hq:autonomy-policy:propose`, `hq:autonomy-policy:activate`, `hq:autonomy-policy:retire`,
`hq:autonomy-policy:show`. Activation and retirement are dry runs without `--apply` and need `--by`
(an existing, enabled user holding `hq.policies.manage`) and `--reason`; each writes an event row and
an audit row under that human. Activating retires the previous active version in the same
transaction. **Nothing is activated in this stream and no seeder writes a policy or a grant.** No
number is shipped: tests carry their own fixture values, labelled as test values.

*The producer.* `App\Autonomy\Evidence\EvidenceProducer`, deterministic, no write:

- `measure(Agent)`: the recount that `AutonomyEvidence` does today, moved here unchanged (applicable
  suites, the latest finished run per suite, results of those runs for this role, passed / failed /
  unmeasured per dimension and overall with the result ids, the run lineage, the stored figure beside
  the recount, the prompt stamp compared with `PromptLibrary::version(role)`), plus a per-check recount
  from `evaluation_results.checks` with result ids. `AutonomyEvidence` consumes it; its 13 tests and
  its no-write guarantee are the proof the move changed nothing.
- `judge(evidence, policy, now)`: per level, every requirement and hard check beside its measured
  value, `met` or the exact reasons, and the **evidence ceiling**.
- `forRunner(Agent)`: what the runner passes on. No active policy: null, and nothing is queried
  beyond the one policy lookup. An active policy and no live grant that could name this agent: the
  policy version is recorded and no evidence is counted, because no grant means A0 whatever the
  evidence says.
- **Cost bound, no cache.** One query for the active policy, one for its rows, one for the suites,
  one indexed latest-run lookup per applicable suite, one results query restricted to those run ids
  (columns `id, run_id, case_id, passed, unmeasured, dimension, checks` only), one grouped count of
  declared cases. History is never read, so the row count is the number of cases in the latest runs,
  not the number of runs ever made; an explicit ceiling on that row count fails closed. No cache was
  chosen, so there is no stale read to reason about: a finished run or an activation is seen by the
  next run.

*Eligibility rules, every one fail closed.*

1. No active policy: no verdict, no rates passed, the resolver decides exactly as today.
2. The policy is **necessary, never sufficient**: it enters the resolver as one more ceiling (combined
   by MINIMUM), so it can only lower. With no matching grant the answer is still A0. Nothing here
   creates, edits or promotes a grant (point 13).
3. Levels are cumulative: level L is eligible only when every level from A1 to L has at least one
   requirement row and ALL of its requirements and hard checks are met. A level the policy says
   nothing about is unreachable through evidence.
4. A requirement is met only when: the dimension has measured results (an unmeasured or never
   measured dimension never satisfies it, and the overall figure is never read for it); measured
   count >= `min_sample_count`; the pooled rate AND each contributing suite's rate meet
   `min_agreement_rate`, in whole thousandths; every contributing run is finished within
   `max_evidence_age_days`, carries the agent's CURRENT prompt stamp, passed its own gate, held zero
   unmeasured cases and recounts to what it stored; every applicable suite declaring cases in the
   dimension has been run. A NULL field on a requirement is "not decided" and never met.
5. A hard check is met only at 100 percent: zero `fail`, at least `min_sample_count` `pass`, on runs
   that satisfy the same stamp, age and zero-unmeasured rules. A failing hard check caps the level
   below the one that names it whatever the rates are.
6. Rates handed to the grant comparison (`agreementRate`, `dimensionRates`, and per suite) are given
   only for clean evidence and are floored to thousandths, and a grant naming `evaluation_suite_key`
   is compared with that suite's figure, never the pooled one.
7. The decision records the policy id, version and digest, the evidence digest and run ids, the
   ceiling and its reasons, additively (`AutonomyDecision::toArray()` gains a key only when a policy
   was applied).

*The view.* Same GET route, still write-free: the active policy (or "no policy is active, so evidence
cannot widen autonomy"), per row each requirement beside the measured value with the exact reason it
is not met, the hard checks, the evidence ceiling, the proposed versions listed as not in force. The
lineage expanders stay.

*Tests (Pest, `hq_test_h`).* Policy governance and the triggers; activation refusals (NULL field,
wrong actor, no rows, second active); the producer's rules one by one; the resolver with and without a
policy; the runner recording the evidence; no seeder writes a grant or a policy; the view. Mutations
to watch failing: the overall rate substituted for a missing dimension; unmeasured counted as a pass;
one satisfied dimension treated as enough; a policy with a NULL field activated; evidence on an old
prompt stamp accepted.
