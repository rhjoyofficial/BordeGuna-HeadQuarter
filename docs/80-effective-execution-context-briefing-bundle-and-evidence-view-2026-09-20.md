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
