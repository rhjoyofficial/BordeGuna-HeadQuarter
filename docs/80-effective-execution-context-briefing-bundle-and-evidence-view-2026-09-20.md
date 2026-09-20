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

### C.3 Stream C: the autonomy evidence view (branch `obs/autonomy-evidence-view`, points 5 and 6)

**The plan, written before the code.**

*What was read first, and what it says about thresholds.* A required agreement rate exists in exactly
one place: on an autonomy grant (`autonomy_grants.min_agreement_rate`, optionally
`min_agreement_dimension` per ADR-030 and `evaluation_suite_key`). There is no table, config key or
ADR that sets a required rate per autonomy level or per dimension, and production holds zero grants.
A suite's `pass_threshold` (1.0 on the three seeded suites) is a different thing: it decides whether
one run of that suite passed, and it is shown as such. So today the honest reading of "required
threshold" for every agent and dimension is "no requirement configured", and the state is "not
eligible". The view invents no number; whether per-level requirements should exist is a decision for
Joy and ChatGPT and is listed in the record below.

*One service, pure query and arithmetic.* `App\Observe\Autonomy\AutonomyEvidence::build()` returns,
per agent, the resolver's present answer and one row for the overall rate plus one per evaluation
dimension. Rules, each deterministic:

1. **Applicable suites** are the ones the gate itself would run: active, and scoped to no role or to
   the agent's role (`role_key`, else the agent key, as `AgentEvaluationSubject` reads it).
2. **The latest-run rule.** Per applicable suite, the evidence is the finished run (`passed` or
   `failed`) with the highest id whose `agent_key` is this agent. Older runs are history and appear
   only under recent failures. A newer run that never finished is named as a caveat and is not
   evidence.
3. **Counted from result rows, never from a stored rate.** A row's figures are counted from
   `evaluation_results` of those runs with that dimension: passed, failed, unmeasured. Evidence count
   is passed plus failed; the agreement rate is passed over that, and null when nothing was measured.
   A provider-refused (`unmeasured`) result is in neither half. A result whose case belongs to
   another role is excluded. The stored `dimension_rates` of each run is shown beside the recount and
   a difference is reported, not hidden.
4. **No inheritance.** The overall row never supplies a dimension row. A dimension with no measured
   result reads "never measured", or "only unmeasured results" when the provider refused them all.
5. **Requirements** are the live grants (active, not expired) that name this agent or no agent and
   carry `min_agreement_rate`: a grant naming a dimension lands on that row, a grant naming none lands
   on the overall row, a grant naming a suite is checked against that suite's run only.
6. **Satisfied only when nothing is unknown.** A row is "not eligible" with every applicable reason
   listed: no requirement configured; never measured; the latest gate run failed (with its counts);
   the latest run had unmeasured cases; the run carries no prompt stamp, or a different stamp from
   the one `PromptLibrary` gives the agent today; the rate is below the required rate (checked
   against the pooled figure and against each contributing suite, so a strong suite cannot carry a
   weak one). "Satisfied" changes nothing: the resolver alone decides a level.
7. **The ceiling now** is the real `AutonomyResolver` asked the way `AgentRunner` asks it (agent key,
   environment, the agent's own ceiling, no business and no context), with its binding reason and
   constraints, beside the agent's status, the count of live grants and the `ai.runtime` flag.
8. **Lineage (point 6).** Every figure carries the run ids, run uuids, suite key, role scope, prompt
   stamp, model, date and the result ids it was counted from, and the page folds them under each
   figure.

*No write path.* One GET route, `/observe/autonomy-evidence`, behind `hq.agents.view` (the permission
AI health already uses, so no seeder change), a sidebar entry under Observe, the shared Observe
panels. No schema change. Flags are read from their rows, not through the cached reader, so the page
does not even write a cache entry.

*Tests.* The service directly: an unmeasured dimension does not inherit the overall rate;
other-role results and other agents' runs are excluded; an unmeasured result is neither pass nor
fail; the latest of several gate runs wins and an unfinished newer run is only a caveat; each reason
string; stamp mismatch; grant requirements per dimension, overall and per suite; viewing changes no
row (counts and `updated_at`). The page: GET only, the gates, which roles open it, and a query
listener proving a render issues no INSERT, UPDATE or DELETE.

**As built (`9915842`).** The plan held with no deviation and no schema change.

- `apps/hq/app/Observe/Autonomy/AutonomyEvidence.php` is the one service; `ObserveController::autonomyEvidence`
  renders `observe/autonomy-evidence` from it; the route is `GET /observe/autonomy-evidence`
  (`observe.autonomy-evidence`, `permission:hq.agents.view`, inside the existing auth and 2FA group);
  the sidebar has "Autonomy evidence" under Observe after Control plane. No seeder, migration, prompt,
  runner, gateway, detector or resolver file was opened.
- The page leads with plain language per agent ("The agent is paused, so it runs nothing at all", "No
  live autonomy grant names this agent", "The resolver answers A0: ..."), then one card per row
  (overall, then the six dimensions) with passed, failed, unmeasured, evidence count, agreement rate
  and required rate ("none configured" when no grant names one), the state badge and the list of
  reasons. Lineage and recent failures are folded under each card: run id and uuid, suite, role
  scope, prompt stamp, model, date, the whole run's counts including skipped and unmeasured, the
  figures stored on the run beside the recount, and the result ids per outcome. Cards stack in one
  column below the widest breakpoint and tables scroll inside their own box.
- Requirement checks are done in whole thousandths (`passed * 1000 >= required_thousandths * measured`),
  so a rate that rounds up to the requirement does not pass.

**What thresholds exist.** Only `autonomy_grants.min_agreement_rate` (with `min_agreement_dimension`
and `evaluation_suite_key`), and production has no grant, so every row in production reads "no
requirement configured" and "not eligible". `evaluation_suites.pass_threshold` (1.0 on the three
seeded suites) is the suite's own pass mark and is shown as that, never as an autonomy requirement.
**Decision for Joy and ChatGPT:** whether a required rate per level and dimension should exist as
configuration independent of a grant (so the view can say "what A1 would need" before anyone drafts
a grant), and what those rates are. Nothing was invented here.

**Decisions taken here, to revisit.**

1. With no suite named on a grant, the requirement must hold for the pooled figure and for each
   contributing suite's latest run. Stricter than the resolver's single-number comparison; chosen
   because it fails closed.
2. A failed latest gate run, a run with unmeasured cases, a missing or different prompt stamp, and a
   stored rate that does not match the recount each make a row "not eligible" even when the rate
   meets the grant. The resolver itself checks none of these.
3. The stamp compared is `evaluation_runs.prompt_version` against `PromptLibrary::version(role)`
   today. Runs carry no model, skill or policy stamp (`hq:agent:evaluate` writes only the prompt
   stamp), so those cannot be compared yet.
4. Evidence is treated as group-wide (like the switches on Control plane), not business-scoped:
   evaluation runs carry no business in practice.
5. Recent failures are the newest 10 per row across the agent's finished runs.

**Found on the way, reported and not fixed.**

- `AgentRunner::resolveAutonomy()` passes no `agreementRate` and no `dimensionRates` to the resolver,
  and nothing else in `app/` builds them. So today a grant carrying `min_agreement_rate` can never
  match at runtime, whatever was measured. That is fail closed and harmless with zero grants, but the
  "mechanical link between we measured and it may act" (ADR-023) has no producer yet; Phase 9 needs
  one, and this service's recount is a candidate source. The view says so on any agent that has such
  a grant.
- `tests/Feature/Facts` run on its own fails one test (`OrderUpdatedTest`, "Call to undefined function
  signedPost()"): the helper lives in `tests/Feature/Identity/ShopWebhookAndIdentityTest.php`. It
  fails identically on the untouched branch; Identity and Facts run together pass.
- `Features::enabled()` writes a cache entry on a miss, so a page that must write nothing cannot use
  it; this view reads the flag rows directly by the same rule.

**Not done.** No walk in a real browser: that needs a signed-in 2FA session, which is Joy's. The page
is covered by the Inertia response test, strict TypeScript and the Vite build only.

**Tests: 13, 286 assertions** (`tests/Feature/Observe/AutonomyEvidenceTest.php`): the blank system;
the ceiling and the flag rule; no inheritance from the overall rate; unmeasured is neither pass nor
fail and never a recent failure; other roles, other agents and inactive suites excluded, with the
recount difference reported; the latest finished run wins and an unfinished newer run is a caveat;
a never-run suite is named; prompt stamp different, missing, matching, and a role with no prompt;
thousandths, per-suite, pooled, suite-named, revoked, expired and other agents' grants; a real
`EvaluationRunner` run recounted to exactly what it stored with every result id accounted for;
viewing issues no INSERT, UPDATE or DELETE and leaves row counts and `updated_at` unchanged across
eight tables; the route is GET only, 405 on every other verb, behind auth, both 2FA gates and
`hq.agents.view`; which roles open it. Two mutations were watched failing (a dimension row reading
the overall results; an unmeasured result counted as a failure).

**Verification, the merge gate until 1 October:** Pest, folder by folder: Agents 106, Ai 53, Ask 8,
Auth 15, Autonomy 14, Commerce 8, Consent 8, Conversation 70, Erp 18, Evaluation 176, Identity and
Facts together 38, Foundation 14, Gateway 174, Media 7, Observe 53, Platform 21, Scheduler 34,
Security 24, Settings 6, Dashboard 5, Unit 1: **853 passed, 0 failed** (840 on main plus these 13).
Pint `--test` passed; `tsc --noEmit` clean; `vite build` succeeded. **Local verification passed;
GitHub Actions validation deferred until the 1 October reset.**

### C.4 Stream D: production, main and unreleased state (point 16), branch `obs/release-state`

#### C.4.1 The plan (written before the code)

**What was found first.** The commit reaches the image only as the label `com.bordeguna.commit`
(`deploy/Dockerfile`, `ARG GIT_COMMIT`), which `deploy.sh` reads back off the running container from
the HOST. Nothing inside the container can read its own label, so today the application cannot say
what release it is. `rollback.sh` builds without the build argument, so a rolled-back image carries an
empty label. `deploy.sh` waits for `/up` and prints `/api/health`, but asserts nothing about the
health answer, so "verified" has no recorded meaning yet. `deploy/RELEASES.md` is a gitignored,
append-only text table on the host. `/api/health` is public (throttled, statuses only). The
deploy-safety suite skips itself on a filesystem that cannot represent mode 600 (Git Bash on NTFS).

**Four facts, four sources, none of them inferred.**

| Fact | Source of truth | How the application reads it |
| --- | --- | --- |
| Current production release | the image itself: `ARG GIT_COMMIT` becomes `ENV HQ_RELEASE_COMMIT` beside the label | `config('hq.release.commit')`; empty means "development checkout", never a guess |
| Last release attempt, and whether it was verified | `deploy.sh` and `rollback.sh` write `release.json` on the host | a host directory mounted read-only |
| Last verified production release | `last-verified.json`, written only when the smoke verification passed | the same mount |
| Current main, commits ahead, migrations ahead | `deploy/release-state-refresh.sh` on the host writes `main.json` from the host's own `git fetch` | the same mount |

The container gets no git checkout, no GitHub credential and makes no outbound call for any of this.

**The manifest directory** is `deploy/release-state/` inside `/opt/bordeguna-hq` (gitignored, so a
checkout or rollback never touches it, and `deploy.sh` keeps its promise of touching nothing outside
the checkout and the backup root). It is mounted as a DIRECTORY, read-only, at
`/var/www/html/release-state` in app, horizon and scheduler; every file is written to a temporary
name and renamed, because a bind-mounted directory sees a rename and a bind-mounted file would not.

**"Verified" is defined from what the deploy already does, made strict and recorded:** `/up` answered,
AND `/api/health` answered with overall `ok`, AND the health answer names the commit that was just
released (so the thing that answered is known to be the new image, not the old one still draining).
Health is retried for a bounded time because Horizon is terminated seconds earlier. A release whose
verification failed or never ran is recorded as such and shows as **unverified**; the deploy's exit
code does not change for a failed health read (it never did), but it now warns loudly and
`last-verified.json` stays where it was.

**A failed release is recorded honestly.** After the checkout moves, an EXIT trap records
`status: failed` with the stage reached (build, up, smoke) in `release.json`. `last-verified.json` is
not touched. The running application compares its OWN commit with the record, so a failed build
reads as "the attempt at X failed at build; production still runs Y", not as a release of X. A
REFUSED deploy (no verified dump) writes nothing at all: the manifest, like the checkout, moves only
after a verified dump. The backup-first ordering is not changed.

**Main and the unreleased changes.** `release-state-refresh.sh` runs at the end of `hq-monitor.sh`
(cron, every 5 minutes) and at the end of a deploy. It fetches `origin main` at most once every 30
minutes (the attempt is stamped BEFORE the fetch so a failing remote is not retried every tick), with
a timeout and no terminal prompt; failure is logged, never paged. It compares `origin/main` with the
RUNNING release (the image label, as `deploy.sh` reads it; the manifest only as a named fallback) and
writes: main's head, when main was last successfully fetched, commits ahead and behind, up to 50
one-line subjects, and the migration files ADDED between the release and main
(`git diff --name-only --diff-filter=A <release>..origin/main -- apps/hq/database/migrations`).
No attempt is made to infer features.

**Absence is never "up to date".** The reader distinguishes: development checkout; manifest directory
missing; `main.json` missing; unreadable; older than the threshold (90 minutes by default, three
missed fetches), shown with its age ("main state unknown, last read N hours ago"); and computed
against a release other than the one running. In every one of those states the "ahead" figures are
withheld, not shown as zero.

**Where it shows.** A compact read-only "Release state" panel at the top of the existing Platform
dashboard (`/observe/platform`, permission `hq.horizon.view`, GET only), in plain sentences built on
the server so they are tested: "Production runs X, released D. Main is N commits ahead, including M
migrations. None of that is live." `/api/health` is public, so it gains ONLY the release commit
(`checks.application.release`, or `development`); subjects, migrations and paths stay behind the
permission.

**Shell.** One sourced library (`deploy/release-state-lib.sh`: atomic JSON writes, JSON escaping), the
refresher, and small additions to `deploy.sh`, `rollback.sh` (which now stamps the commit into the
image it builds) and `hq-monitor.sh`. All tolerate a missing directory and are idempotent.

**Tests.** Pest: the reader in every state above, the panel's props and sentences, the health field.
Deploy-safety: a refused deploy writes no manifest; a healthy deploy writes `release.json` and
`last-verified.json` after the dump; a failed build and a failed verification record the failure and
leave the previous `last-verified.json` byte-identical; the refresher counts commits and migrations
ahead, throttles its fetch, survives a failing remote, and escapes a hostile subject into valid JSON;
a rollback stamps the commit and records itself.

**Not done, on purpose.** No alert on "main is ahead" (it is the deliberate state while gate 11
waits). No write path, no button, no flag. Nothing is switched on. Production is not touched: the new
mount and the refresher take effect only at the next deploy, and the operator steps are in
`deploy/README.md` marked as not yet applied.

#### C.4.2 As built (branch `obs/release-state`, commit `184d6a9`)

Built as planned, with these points decided while building:

- **The image says what it is.** `deploy/Dockerfile`: `ENV HQ_RELEASE_COMMIT=$GIT_COMMIT` beside the
  existing label. `config/hq.php` `release.commit`, `release.state_path` (`base_path('release-state')`),
  `release.main_stale_after_minutes` (90). A value that is not 7 to 40 hex characters is treated as
  no value: "development checkout".
- **`deploy/release-state-lib.sh`** (sourced): atomic write (temporary name, rename, 644 in a 755
  directory), JSON escaping that drops control characters, the two health readers, short-or-long
  commit comparison. **`deploy/release-state-refresh.sh`**: as planned; merges are included in the
  subjects so the list and the count describe the same commits; the basis of the comparison is
  named (`running-image-label`, or `last-verified-record` as the fallback).
- **`deploy.sh`**: sources the library; an EXIT trap that records a failed attempt only once the
  checkout has moved (stages build, up, smoke, record); health retried `HEALTH_TRIES` x `HEALTH_WAIT`
  (18 x 5 s); `RELEASES.md` rows gain a fifth column, `verification passed|failed`; `release.json`,
  then `last-verified.json` only on a pass; the refresher is called last and cannot fail the deploy.
  The backup-first ordering is untouched, and the suite still measures it.
- **`rollback.sh`** now passes `--build-arg GIT_COMMIT` (it did not, so a rolled-back image carried an
  empty label), verifies the same way and records itself as `kind: rollback`.
- **`hq-monitor.sh`** calls the refresher before the heartbeat; a failure is one log line, never a page.
- **`docker-compose.yml`**: `./release-state:/var/www/html/release-state:ro` on app, horizon and
  scheduler (twice in the file, because `app` overrides the anchor's volume list). `.gitignore`:
  `deploy/release-state/`.
- **`App\Observe\ReleaseState`**: the reader. Main states: `known`, `stale`, `missing`, `unreadable`,
  `undetermined`, `other_release`; only `known` carries figures (a stale reading keeps
  `last_known_ahead` beside its age). Everything from the host files is validated before it reaches
  a sentence: commits by pattern, tokens by pattern, subjects as bounded strings (200 characters, 50
  items), migrations as plain `.php` file names. `PlatformDashboard` gains `release`;
  `resources/js/components/observe/release-state-panel.tsx` renders it at the top of
  `/observe/platform`. `/api/health` gains `checks.application.release` and nothing else.
- **`deploy/README.md`**: the Release section corrected (it still described the pre-docs/58 order),
  a "Release state" section, and the by-hand steps for the first deploy, marked NOT YET APPLIED.

**What the next production deploy must do by hand** (full text in `deploy/README.md`): nothing to
create beforehand; run `./deploy/deploy.sh`, then `./deploy/deploy.sh --no-pull` once, because the
first run is executed by the old script and writes no record; confirm root's cron can
`git fetch origin main` without a prompt; confirm the crontab still calls the checkout's
`hq-monitor.sh`; then look at `deploy/release-state/*.json`, `/api/health` and the panel. No new cron
line, no new secret, no flag.

#### C.4.3 Verification (20 September 2026, local; GitHub Actions validation deferred until the 1 October reset)

| Check | Result |
| --- | --- |
| Pest, folder by folder, `DB_DATABASE=hq_test_d` | 873 tests: Agents 106, Ai 53, Ask 8, Auth 15, Autonomy 14, Commerce 8, Consent 8, Conversation 70, Erp 18, Evaluation 176, Facts 29, Foundation 14, Gateway 174, Identity 9, Media 7, Observe 73 (33 new in `ReleaseStateTest`, 244 assertions), Platform 21, Scheduler 34, Security 24, Settings 6, DashboardTest 5, Unit 1. ⚠ `tests/Feature/Facts` run ALONE shows 1 failed, 28 passed, on this branch and identically with this branch's changes stashed: `OrderUpdatedTest` calls `signedPost()`, which is defined in `tests/Feature/Identity/ShopWebhookAndIdentityTest.php`. Facts and Identity together: 38 passed. Pre-existing, reported, not fixed here |
| `vendor/bin/pint --test` | passed |
| `npx tsc --noEmit` | exit 0 |
| `npm run build` | built |
| Prettier and ESLint on the two changed front-end files | clean |
| `bash -n` on every changed script | clean |
| Deploy-safety suite | **91 passed, 0 failed** (31 before, 60 new), run on Windows Git Bash with ONE local aid that is not committed: a `stat` shim on PATH answering `600` for `stat -c %a`, because NTFS cannot represent the mode and the suite otherwise skips itself. Everything else was the real `deploy.sh`, `rollback.sh`, refresher and git |
| Mutation check of the new deploy-safety tests (scratch copy) | three mutations (promote to last-verified unconditionally; remove the failure trap; remove the fetch throttle) produced 10 failures; all three caught |
| Contract check | files written by the real shell library (a subject with quotes, a backslash and Bengali text) were read by `ReleaseState` through artisan and produced the expected sentences |

**Not verified locally:** the suite on a real Linux filesystem (CI job `deploy-safety`, 1 October, or
the VPS); `shellcheck` (not installed); a real `docker build` with the new `ENV` and a real compose
`up` with the new mount (Docker Desktop's daemon was not running and was not started); the panel in a
browser (no walk was done: it needs a 2FA session against a non-test database).

**Decisions to revisit.**
1. An UNVERIFIED release is cleared only by releasing the same commit again (`--no-pull`), which takes
   another dump and rebuild. A `--verify-only` mode would be cheaper; not built.
2. "Verified" requires health to NAME the commit, so a rollback to any release older than this one is
   always unverified. Honest, but it will surprise someone.
3. The refresher's `git fetch` can in principle collide with a deploy's own fetch on the ref lock; the
   deploy would then stop BEFORE the checkout moves (safe, re-run). Not seen, not guarded.
4. The 90-minute staleness threshold and the 50-subject cap are guesses.
5. No alert when main has been "unknown" for a long time; the panel is the only place it shows.
