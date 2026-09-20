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

### C.2 Stream B: Effective Execution Context (branch `cp/effective-execution-context`)

#### C.2.1 How authority flows today (read from the code before any change, 20 September)

Every AI entry point ends in one call, `AgentRunner::run(AgentRunRequest)`. There are five of them:

| Entry point | File | Who is asking, as the code sees it |
| --- | --- | --- |
| Ask HQ | `app/Ask/AskHq.php:204` (`answer()`) | `operator: $conversation->user` |
| Shadow Probes (the only scheduled agent run: `routes/console.php:22-23`, `hq:shadow:tick`) | `app/Agents/Shadow/ShadowProbes.php:72,93` | `operator:` the period's opener, or the agent's enabling user when no opener was recorded |
| Gate runner | `app/Agents/AgentEvaluationSubject.php:85` | nobody |
| Support agent | the same subject, scenario path, `AgentEvaluationSubject.php:134` (the real wake-up is not built; `support` has no enabling user) | nobody |
| Console | `app/Console/Commands/AgentAsk.php:47` | nobody |

**Where the requester is dropped.** `AgentRunRequest::$operator` has exactly one reader:
`AgentRunner.php:269`, which writes it to `agent_runs.triggered_by_user_id`. It is a label. Authority
is taken from the agent row alone:

- `AgentRunner.php:242` reads `$agent->enablingUser`, and `AgentRunner.php:668` builds every tool
  call as `ActingIdentity::agent($agent, $enabling)`. The asker is not in the identity.
- `ActingIdentity.php:91-94` (`holds()`): the enabling user's permission AND agent active AND
  `agent.allowed_tools`. This is what `PolicyEngine.php:97` asks.
- `ToolGateway.php:460-462` (`checkScope`) and `ToolGateway.php:540-545` (`permittedBusinesses`, the
  filter handed to the group-wide read tools) read `identity->enablingUser()` only.
- `BusinessReferenceResolver.php:117-120` narrows the catalogue's business enum, the directory block
  (`ContextAssembler.php:57-59`) and the group expansion (`AgentRunner.php:783-785`) by the enabling
  user's business rows only.
- `AgentToolset.php:48-71` lists tools by `allowed_tools`, `agentExecutable` and the runtime switch. It
  never asks whether anybody holds the tool's permission, so a tool the gateway will refuse is shown.

**What the gateway already intersects** (docs/48 E.3): tool permission AND enabling human AND
`allowed_tools` AND the enabling human's business rows AND the tool's supported businesses and stores
AND the runtime switch AND policy AND consent AND credentials and capability readiness. **What E.3
lists and the code never did:** `business_scope(agent)`. `agents.business_scope` and
`agents.brand_scope` are written by the seeder and read by nothing (no reader anywhere in `app/`).
And `ToolRequest::$brandId` is carried to the tool and to the approval row and checked by nothing.

**Consequence**, as docs/78 C.3 recorded: a business-restricted operator asking the Executive would
be answered with the owner's group-wide reach. Ask HQ closed that at its own door
(`AskHq.php:67-89`: group-wide askers holding every permission only). The same hole is open to any
future surface that forgets to copy that check, which is why the correction is not an Ask HQ patch.

**Who is accountable for a system invocation today.** A scheduled job: `scheduled_jobs.owner_user_id`
and nothing else (no approver column; approval is an audit row). The scheduler runs TOOLS, not agents,
as `ActingIdentity::forJob($owner)` (`ToolRequest.php:57`): one human, no agent, no model, requester
and enabler the same person by construction, so there is nothing to intersect and that path is not
changed. A shadow period: `shadow_periods.opened_by_user_id`, nullable (`hq:shadow:open --by` is
optional). An evaluation run: nobody.

**The executive stamp.** `PromptLibrary::version()` is the role file name plus the first eight hex
digits of `sha256(core prompt + role prompt)`. It hashes the two prompt files and nothing else. No
code file is in it.

#### C.2.2 The design (written before the code)

One value object and one resolver, in `app/Execution`:

- `Requester`: who is asking, said honestly. `human` (a signed-in person: Ask HQ, or the console's
  new `--as`); `on_behalf_of` (the scheduler or the system, for the accountable person RECORDED on
  the job or the shadow period: `owner_user_id`, `opened_by_user_id`); `gate_runner` and `console`
  (nobody asked). Where no separate person exists (the gate, the console with no `--as`, a period
  opened with no `--by`), the requester is declared `as_enabled`: the accountable human is the
  agent's enabling user, so the requester term equals the enabling term and adds nothing. There is
  no system super-identity and no requester with authority of its own.
- `EffectiveExecutionContext` (immutable): requester, enabler, agent, acting identity label, entry
  point, environment, the requested business and brand, the business set, the brand set, the store
  rule (a store is in scope exactly when its business is; HQ has no per-store grant), the tool set,
  the permission set, the terms that are decided per call (runtime switch, policy, approval, consent,
  credentials and capability readiness: they need the call's arguments, so the gateway decides them
  per call under this same context), every narrowing with its reason, a digest, and the resolver
  version `eec.v1`.
- `ExecutionContextResolver::resolve()`, the single place. In order, each step only removing:
  agent active; an enabling user that exists and is not disabled (none: no run); a requester that is
  named, exists and is not disabled (none named: no run, there is no default); business set =
  enabler rows INTERSECT requester rows INTERSECT `agents.business_scope` INTERSECT active businesses
  (null stays null only when no term narrowed, which keeps today's group-wide posture byte for byte);
  the requested business must lie inside it; brand set = `agents.brand_scope` INTERSECT the brands of
  the business set, and the requested brand must lie inside it and belong to the requested business;
  tool set = `allowed_tools` whose permission BOTH people hold (and that an agent may execute);
  an agent that was granted tools and is left with none is refused with the reason. Anything
  unresolvable is a refusal (`ExecutionContextRefused`), audited.
- **Everything the agent is shown and everything the gateway checks comes from that one object.**
  `AgentToolset`, `ContextAssembler` (the directory) and `BusinessReferenceResolver` take the context
  instead of the agent row. `ActingIdentity::agent(Agent, User)` is REMOVED and replaced by
  `ActingIdentity::forExecution($context)`; `holds()` then requires the enabler's permission AND the
  requester's AND the agent's list AND the context's tool set; `ToolGateway::checkScope` and
  `permittedBusinesses` read the context's business set, and a brand outside the brand set is a
  scope refusal. Before each call the gateway re-reads both people and the agent from the database
  (`stillStands`): a requester or enabler disabled or deleted mid-run, or an agent paused or handed
  to another enabler, refuses the rest of the run's calls.
- **Recorded and audited.** `agent_runs.execution_context` (json) and
  `agent_runs.execution_context_digest`, additive and nullable; one audit row per run
  (`agent_run.context_resolved`), one per refusal (`agent_run.context_refused`). User ids, scopes,
  permission NAMES, reasons. No address, credential or secret exists in it.
- **Entry points.** Ask HQ passes `Requester::human(asker)` and drops its group-wide restriction:
  the page and the submit ask the resolver, and refuse with its reason when the intersection leaves
  the agent nothing. Shadow Probes pass `Requester::forShadowPeriod()`. The gate runner, and with it
  the support agent's scenario path, pass `as_enabled(gate_runner)`; `support` has no enabling user,
  so it still cannot run. The console passes `as_enabled(console)` or `--as`.
  `Requester::forScheduledJob()` exists for the first scheduled agent run (the M7 briefing job):
  the owner, or a refusal when the job names none.
- **`AgentRunner`**: the two fail-closed checks move into the resolver with their sentences
  unchanged; the runner takes `$enabling`, the catalogue, the directory, the expansion list and the
  identity from the context; nothing else in it changes. No prompt, `TaskGrounding`, `TaskScope`,
  detector, policy rule or approval binding is touched.
- **Identity case.** Requester = enabler = owner, no business rows, every permission, agent scopes
  null: the context's sets are null or full, so the prompt bytes, the tool requests and the outcomes
  are the same as before. A test pins the prompt text and the recorded calls.

**Conservative choices, for Joy and ChatGPT to revisit** (none invents a business rule):

1. A request that names no requester is refused; there is no default requester.
2. `agents.business_scope` and `brand_scope`: null or `["*"]` does not narrow; a list narrows (ids
   or slugs); an EMPTY list means nothing, like `allowed_tools`. Unknown entries are dropped.
3. People have no brand scope in HQ today (only `user_business_scopes`), and none is invented: a
   person's brands are the brands of their businesses. The brand term is fed by the agent row and the
   requested brand, and has one method to extend when a per-person brand scope exists.
4. A shadow period with no recorded opener runs `as_enabled`, as it effectively did before, and the
   record says so. Requiring `--by` on `hq:shadow:open` would be narrower; it changes an approved
   activation command, so it is left as a question.
5. An agent left with no usable tool is refused rather than run with an empty catalogue.
6. `users.is_active = false` is treated as disabled here.
7. The scheduler's tool path (`RunScheduledJob`) is not rerouted: it has one human and no agent.

#### C.2.3 As built (20 September), ADR-047

Built as designed in C.2.2, with one refinement found by the existing gateway tests: whether an
agent may EXECUTE a tool at all (`agentExecutable`) stays out of the permission term. The catalogue
leaves such a tool out and policy refuses it by name before it asks about permission, as before; the
context's tool set is "the agent's tools whose permission both people hold" and nothing else.

**New (`app/Execution`):** `RequesterKind`, `Requester`, `EffectiveExecutionContext` (`eec.v1`),
`ExecutionContextResolver` (`resolve`, `preview`, `record`, `stillStands`), `ExecutionContextRefused`.
**Migration, additive and reversible:** `2026_09_20_200000_add_execution_context_to_agent_runs`
(`execution_context` jsonb null, `execution_context_digest` varchar(64) null), rehearsed on Postgres:
applied, rolled back, re-applied.
**Changed:** `AgentRunner` (27 lines in, 29 out: the two fail-closed checks moved into the resolver
with their sentences unchanged; catalogue, directory, expansion and identity taken from the context;
the context recorded before the first model turn), `AgentRunRequest` (`requester`), `AgentToolset`,
`ContextAssembler`, `BusinessReferenceResolver` (all three take the context, not the agent row),
`ActingIdentity` (`agent()` removed, `forExecution()` added, `holds()` asks both people and the
resolved tool set), `ToolGateway` (`checkScope`: still stands, business, brand; the cross-business
filter), `AskHq` (the group-wide rule removed; `refusalFor` is the resolver's preview),
`ShadowProbes`, `AgentEvaluationSubject`, `hq:agent:ask` (new `--as`), `AgentRun`, the Ask page's
one sentence, the Ask HQ runbook. **Not touched** (`git diff` empty): every prompt file,
`TaskGrounding`, `TaskScope`, `TaskScopeResolver`, `SecurityIntent`, `ProhibitedCapability`,
`app/Gateway/Policy`, `app/Gateway/Approval`, the seeders.

**The executive stamp did not change.** `PromptLibrary::version()` hashes the two prompt files and
no code; read after the change it is `executive.v8+18d60e98` (and `support.v1+0f4a195b`). The
identity test's system-prompt digest begins `18d60e98`, the same bytes.

**Every AI entry point, after:**

| Entry point | Requester it states |
| --- | --- |
| Ask HQ (`AskHq::answer`) | `human`, the asker, read again when the queued answer runs |
| Shadow Probes (`hq:shadow:tick`, the scheduled one) | `on_behalf_of` the period's opener; `as_enabled` when no opener was recorded, and the record says so |
| Gate runner, and the support agent's scenario path | `gate_runner`, `as_enabled`; `support` names no enabling user, so it still cannot run |
| Console (`hq:agent:ask`) | `console`, `as_enabled`; or `human` with `--as` |
| A scheduled job that starts a run (none exists yet) | `Requester::forScheduledJob`: `on_behalf_of` the owner, refused when there is none |

**Regression tests** (30 new, `tests/Feature/Security`): `EffectiveExecutionContextTest` (14),
`ExecutionContextOnePathTest` (13), `ExecutionContextIdentityCaseTest` (3).

*Watched failing first, against the unmodified behaviour* (the code at `d8779c6` plus only the inert
`requester` field, so each failed for its behavioural reason and not for a missing class), 13 of the
14 in the first file: restricted requester with the owner enabling (the group-wide read returned 3
rows, not 1); two restricted people with different scopes (3, not 2); same permissions and no shared
business (ran; no refusal); same business, another brand (ran; `brand_scope` was read by nothing);
a permission only the enabling user holds (the tool ran for the asker); a permission only the
requester holds (the tool was listed in the catalogue); an asker sharing no permission (ran); no
requester named (ran); no enabling identity (refused, but unaudited); a requester or enabling user
disabled while the question waited in the queue (ran); Ask HQ for a restricted operator (refused by
the old rule); a Shadow Probe for a restricted opener (3 rows, not 2); the context recorded and
audited (no column). *Passed before and after:* owner requester with a restricted enabling user,
which the enabling-side intersection always held. *New guards, with no earlier behaviour to watch
fail* (second file): the gateway's checks reached with no runner in front (business, permission,
brand); the mid-run re-read (requester disabled, requester deleted, enabling user disabled, agent
paused, agent handed to another enabling user, which is the requester/enabler mismatch case); the
scheduled-job requester; the no-opener period; the gate runner and the console; the source-reading
test (no `ActingIdentity::agent(` anywhere; `forExecution(` only in the runner; a context built only
by the resolver; `resolve(` called only by the runner; every `new AgentRunRequest(` in `app/` states
a `requester:`; nothing that starts or carries a run reads `->enablingUser`; the runner still has
exactly one `->execute(`).

**The identity case, byte for byte.** Four sha256 digests (system prompt, first-turn prompt,
second-turn message with all seven tool results, the recorded calls row by row) were captured by
running the scenario on the app code of `d8779c6` (checked out over the working tree, then
restored), and the new code reproduces all four, for `as_enabled` and for the owner asking in
person. They are pinned in `ExecutionContextIdentityCaseTest`, with the rule for re-pinning them.

**One existing test changed its meaning, on purpose:** `AskHqTest` no longer expects the group-wide
refusal; it expects a restricted operator to be allowed, a business outside their rows refused, and
an asker sharing nothing refused in the resolver's words. Twelve existing test files changed
mechanically (a `requester:` on each run request; `Contexts::for()` where a test built an agent
identity, a catalogue or a directory by hand), with their assertions unchanged, except
`ToolGatewayTest`'s intersection case, which now also asserts that no context resolves for a
permissionless enabling user or a paused agent.

**Verification, the merge gate until 1 October.** Pest, folder by folder on `hq_test_b`: Agents 106,
Ai 53, Ask 8, Auth 15, Autonomy 14, Commerce 8, Consent 8, Conversation 70, Erp 18, Evaluation 176,
Identity and Facts 38, Foundation 14, Gateway 174, Media 7, Observe 40, Platform 21, Scheduler 34,
Security 54, Settings 6, Dashboard 5, Unit 1: **870 passed, 0 failed** (840 before, 30 new; the one
whole-suite run agrees). Pint `--test` passed; `tsc --noEmit` clean; `vite build` succeeded.
**Local verification passed; GitHub Actions validation deferred until the 1 October reset.**

**Not done, and why.** The run page does not yet SHOW the recorded context (it is on the row and in
the audit log; the screen is a small follow-up and was left out of the most security-critical
stream). The scheduler's tool path carries no context (choice 7). Nothing was switched on: no flag,
no grant, no job, no prompt, no agent status.

**Found on the way, reported and not changed.**
- `users.is_active` exists and nothing in authentication reads it; only this resolver now does.
- `tests/Feature/Facts` run alone fails one test (`signedPost()` is defined in
  `tests/Feature/Identity`); the two folders pass together, which is how `docs/78` counted them.
- `facts.query_orders` orders grouped rows by the metric alone, so equal groups come back in an
  order that changes between runs.
- The ADR index (`docs/32`) stops at ADR-019; ADR-020 to ADR-046 are not listed. ADR-047 was added.
- An approval parked by an agent records the ENABLING user as `requested_by_user_id`
  (`ApprovalService.php:76`); with a requester now known this may deserve a second column. Approval
  binding was out of scope.
- `BusinessScope` caches a person's rows for 60 seconds, so a person narrowed a moment ago keeps
  their old reach for up to a minute unless `forget()` is called. Unchanged here.
