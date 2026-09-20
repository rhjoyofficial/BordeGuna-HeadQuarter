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

### C.3 Stream G: one rollback invariant, and deploy verify-only (branch `deploy/rollback-invariant-and-verify-only`, P0-3 and point 4)

#### C.3.1 The plan (written before the code)

**What was found first (file:line, on `5c4b948`).**

1. `deploy/rollback.sh:39` prefixes `RUN_MIGRATIONS=0` on `docker compose build`. A shell variable on a
   build reaches nothing: the image does not take it as a build argument and the compose file never
   interpolates it. `deploy/docker-compose.yml:42` hardcodes `RUN_MIGRATIONS: "1"` on `app`, and
   `deploy/entrypoint.sh:38-41` runs `php artisan migrate --force` whenever that reads `1`. So
   `rollback.sh:41` (`up -d --no-deps app horizon scheduler web`) DOES run `migrate --force` when the
   old image starts. The comment at `rollback.sh:35` ("Do NOT run migrations on rollback") and
   `deploy/README.md:116-117` ("no migrations") both say otherwise.
2. **`rollback.sh` is not backup-first.** `rollback.sh:20` moves the checkout with no dump taken, no
   backup boundary checked and no `.env` guard. It is the same defect `docs/58` H.1 found in
   `deploy.sh`, and the 10 September work fixed it there only.
3. `rollback.sh:13` takes the predecessor from the CHECKOUT (`git rev-parse --short HEAD`), not from
   the running image label, and records `previous_known` as `0` always (`:30`, `:65`). `deploy.sh`
   reads the label for exactly this reason.
4. `rollback.sh:14` accepts any commit the repository can resolve: a commit that was never released,
   a commit on another branch, or the commit already running. Nothing is refused.
5. `rollback.sh` is read by bash while `git checkout` replaces it on disk (`:20`). It has worked
   because git replaces the file rather than rewriting it in place; it is not something to lean on.
6. `deploy.sh:190` treats every first argument other than `--no-pull` as "pull". A typing mistake
   (`--nopull`) silently fast-forwards production to `origin/main`.

**What `migrate --force` does when OLD code starts against a NEWER schema.** Laravel runs every
migration FILE the starting code carries that has no row in the `migrations` table. It never looks at
the schema, and it knows nothing of migrations that only newer code carries. For a plain rollback
from N to an earlier released T that is normally nothing, which is why this defect has been silent.
It is not nothing in exactly the cases where a rollback is most likely to be needed:

- **the release being rolled back died in its migrations.** Its entrypoint ran some, failed on one,
  and `app` is restarting in a loop. Roll back to a T that also carries the failing migration (an
  intermediate commit, or a re-release) and the old image's entrypoint tries it again, fails again
  under `set -e`, and the rollback crash-loops for the same reason the release did;
- **a migration was undone by hand** (`migrate:rollback`, or a `restore.sh` to a dump older than T's
  own migrations). T carries the file, the table has no row, and starting T quietly re-applies the
  migration somebody deliberately removed;
- **the target is not an ancestor of what is running** (another branch, a reverted line). It may
  carry migration files production has never seen, and a "code rollback" becomes a schema change
  nobody reviewed or rehearsed, with no CI on that combination.

**The invariant (one, explicit):**

> **A rollback changes code and nothing else. It never runs a migration, up or down, and it writes
> nothing to the database itself. It happens only behind a verified dump, exactly like a deploy.**

What that says about SCHEMA, stated once and used everywhere: the schema is NOT rolled back. This
project's migrations are additive, so the old code runs against the newer, additive schema. Undoing a
migration, or returning DATA to an earlier state, is `deploy/restore.sh` from the pre-deploy dump: a
separate, deliberate act with its own confirmation, never a side effect of `rollback.sh`. The dump a
rollback takes first is there because old code writing into newer data is still a change to
production, and the restore point must exist before it starts.

**The mechanism, and why this one.** The obvious change (`RUN_MIGRATIONS: "${RUN_MIGRATIONS:-1}"` in
the compose file, `RUN_MIGRATIONS=0` on `up`) fails in the case that matters: `git checkout <target>`
replaces `deploy/docker-compose.yml` with the TARGET's copy, and every release older than this one
hardcodes `"1"`, so the variable would reach nothing, which is the defect over again. It would also
make the normal deploy depend on what happens to be exported in the operator's shell. Instead
`rollback.sh` writes a small compose OVERRIDE to a temporary file outside the checkout
(`services.app.environment.RUN_MIGRATIONS: "0"`) and passes it as a second `-f` on `up` only. Compose
merges `environment` maps and the later file wins, whatever the target's compose file says. The
normal deploy path is not touched: `docker-compose.yml` keeps `"1"`, `deploy.sh` keeps its `up`, and
the entrypoint keeps its test (it gains one log line saying migrations were skipped).

The rollback does not trust the mechanism, it observes it, twice: BEFORE anything moves it resolves
the target's compose file (read with `git show`, never checked out) together with the override
through `docker compose config` and refuses unless `app` resolves to `RUN_MIGRATIONS` `0`; AFTER `up`
it reads the environment of the container that actually started (`docker inspect`) and says what it
found. If that ever reads `1` the rollback fails loudly at stage `up`.

⚠ Known edge, documented rather than hidden: after a rollback, a bare `docker compose up -d` typed by
hand sees a changed `app` definition and recreates it with `RUN_MIGRATIONS=1`. A container restart
(`restart: unless-stopped`, a reboot) does not: it keeps the environment it was created with. The way
forward from a rollback is `git checkout main && ./deploy/deploy.sh --no-pull`, which is a deploy and
migrates by design.

**`rollback.sh`, in the order it will run:** usage and the `.env` guard (present, mode 600) -> the
target must resolve to a commit -> the running release from the image label (the checkout only as a
marked fallback) -> REFUSE when the target is what is running, when it is neither an ancestor of the
running release nor a commit `deploy/RELEASES.md` names as released, when tracked files on the
checkout are modified (a rebuild would bake a hand edit into the image), or when the stack is down
(nothing to dump; starting a stack is `deploy.sh --no-pull`) -> the backup boundary and the verified
dump, through the SAME functions as `deploy.sh` (moved to a sourced `deploy/backup-first-lib.sh`, so
there is one implementation of the invariant, not two) -> the resolved-config check -> ONLY THEN
`git checkout` -> build stamped with the commit -> `up` with the override -> the container's
environment observed -> smoke and verification as today -> `RELEASES.md` and `release.json` with
`kind: rollback`, the label-derived predecessor and the dump. The body moves into a function so bash
has parsed the whole script before the checkout replaces it.

**`deploy.sh --verify-only`** (combinable with `--no-pull`; an unknown argument is now refused, see
finding 6). It lives in `deploy/verify-only.sh`, which `deploy.sh` hands over to before it does
anything else. Read-only by construction: no `git fetch`, no `mkdir`, no marker, no mount probe
that writes, no dump, no build, no `up`, no record. Each check prints `ok`, `WARN` or `FAIL` with its
reason; any `FAIL` exits 1; it says at the top and bottom that it is not a deployment.

| Check | Fails when | Warns when |
| --- | --- | --- |
| `deploy/.env` | missing; not mode 600; a key the compose file interpolates or the app cannot start without is absent or empty (names only, never values) | a key in the TARGET's `.env.example` is absent |
| docker and compose | not installed; `compose config -q` rejects the file (the target's copy too, when the release changes it) | |
| what is running | | no stack (first deploy); no commit label; checkout differs from the running release; detached HEAD |
| health | `/up` does not answer; `/api/health` is not `ok`; health names a commit other than the running label | health names no commit (a release older than docs/80 C.4) |
| tracked files modified on the checkout | any (each file named) | |
| backup boundary, no dump taken | host backup root or `daily/` missing or not writable; the container's `/backups` is a different inode from the host directory (the dead bind mount, read without writing); free space under the stated minimum (`VERIFY_MIN_FREE_MB`, default 5120); `LAST_STATUS` not 0; the `LAST_OK` dump missing or empty | the newest verified dump is older than 26 hours |
| origin/main | `git ls-remote` cannot reach origin; the target is not a fast-forward of the checkout | origin/main has moved past what this checkout has fetched (the listing is then of the last fetched main, and says so) |
| what the release brings | | any pending migration whose `up()` contains `dropColumn`, `drop`, `dropIfExists`, `renameColumn`, `rename`, `->change()` or raw `DROP`/`TRUNCATE` (file named: the rule is additive migrations); a migration file MODIFIED rather than added |
| release-state directory | present but not writable | absent on the host, or not mounted in `app` (expected once, on the first deploy carrying docs/80 C.4); mounted on a dead inode |

**The fetch decision.** It does NOT fetch. `git ls-remote origin refs/heads/main` proves origin is
reachable with the deploy key and names main's head without moving a ref or writing an object. The
cron refresher already fetches `origin main` at most every 30 minutes, so the commit objects are
normally present; when main has moved since, verify-only says so and lists what the last fetched
main would bring. A tool whose promise is "changes nothing" should not have one exception to explain.

**`--verify-release` (clearing UNVERIFIED without a rebuild): designed, not built.** The safe form
exists: read the running label L; require `/up`, health `ok` and health naming L; require
`release.json` to be a `released` record of L; only then rewrite its verification and copy it to
`last-verified.json`. It cannot verify a commit that is not answering health. It is left out because
it would be a second writer of the most trusted record in the system, rebuilding `release.json` from
a grep of itself, to save one `deploy.sh --no-pull` (a dump and a cached build, a few minutes) on an
event that has happened zero times in production; and a rollback to a release older than docs/80 C.4
can never be verified by any means, since its health names no commit. `--verify-only` reports the
case instead. Revisit if UNVERIFIED releases turn out to be common.

**Tests (watched failing first against the unmodified scripts).** The Docker stub learns three
things: it resolves `RUN_MIGRATIONS` for `app` the way compose does (the last `-f` file that sets it
wins) and records that value at every `up` that starts `app`; it answers `config`; it answers the
environment `inspect`. The test world carries the real `docker-compose.yml`. New assertions: a normal
deploy starts `app` with migrations ON; a rollback starts it with migrations OFF and says so; the
rollback's dump is taken while the checkout is still at the old commit; a rollback whose backup fails,
whose target is running, unknown, not an ancestor, or whose checkout is dirty moves NOTHING (HEAD,
the stub's mutation record, `RELEASES.md`, release state); a rollback whose resolved config still
says `1` moves nothing; verify-only in a passing world and in several failing worlds leaves a digest
of HEAD, every ref, `git status`, every file under `deploy/` and the backup root, and the stub's
mutation record byte-identical, and exits 0 and non-zero respectively with the reason named.
