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
| J. Defects found during the milestone (Horizon before 2FA, cached kill switches, the executor's decision rule, the edit's redaction, an email on the agents index, housekeeping), fixed before the candidate | `fix/pre-candidate-defects` | `docs/RESUME-HERE-2026-09-21.md` section 9 |
| K. OpenAI as the model provider, by the owner's decision of 21 September (a driver, the rows, one serving provider, an audited lever, readiness that follows it) | `ai/openai-provider` | 10, 17 (owner decision, C.7) |
| L. The scheduler's zombie leak (a production outage found by the candidate's preflight) and the health readers that could not read a 503 | `fix/scheduler-reaper-and-health-readers` | found on 21 September (C.8) |
| M. One deploy or rollback at a time, and the residuals the milestone found (the deleted ERP host in the template and seed, a stray `RUN_MIGRATIONS`, Anthropic's spend limit as a credit block, `retire()` on a fresh row) | `fix/deploy-lock-and-residuals` | found during the milestone (C.9) |

Rules every stream keeps: a correction is watched failing first; no prompt file changes; nothing is
switched on; no grant, flag row or scheduled job is created; historical rows are never rewritten;
no invented business rule or number; migrations additive and rehearsed apply, roll back, re-apply on
Postgres.

After the merges: full local verification of the combined tree, the release candidate named, and
the by-hand release steps handed to Joy (deploy and gate launch are his to type). Gate 11 attempt 2
still needs Anthropic capacity, which is his.

## C. Record

(filled in per stream as it completes)

### C.1 Stream E: disabled users cannot authenticate, business scope is never stale, a production shadow needs `--by` (branch `fix/auth-scope-and-shadow-opener`)

#### C.1.1 Today's behaviour (read from the code before any change, 21 September; paths under `apps/hq`)

**P0-1, `users.is_active`.** The column is `boolean NOT NULL DEFAULT true`
(`database/migrations/2026_09_05_100400_add_two_factor_and_pgvector.php:27`), cast on the model
(`app/Models/User.php:38`) and not fillable (`User.php:15`). Its only readers are the execution
context resolver (`app/Execution/ExecutionContextResolver.php:109`, `:135`, `:153`). Authentication
never reads it:

- Password step: `app/Http/Requests/Auth/LoginRequest.php:44` calls `Auth::attempt()` with the
  email and password only. The guard is `session` over the stock `eloquent` provider
  (`config/auth.php`), which knows nothing of `is_active`. A disabled user signs in.
- 2FA step: `app/Http/Controllers/Security/TwoFactorChallengeController.php:31` verifies whoever
  `$request->user()` is. A disabled user completes the challenge.
- A live session: `bootstrap/app.php:28` registers no middleware that looks at the account, and the
  two 2FA middlewares (`app/Http/Middleware/EnsureTwoFactor*.php`) only read the 2FA state. A user
  disabled while signed in keeps working until the session expires.
- Remember me: the stock provider's `retrieveByToken` revives a disabled user from the cookie.
- Password reset: `routes/auth.php:20-30` with the stock broker sends a disabled user a link and
  accepts the new password (`app/Http/Controllers/Auth/NewPasswordController.php:46`).
- Horizon: `config/horizon.php:86` mounts it on the `web` group alone, behind the gate
  `viewHorizon` = `can('hq.horizon.view')` (`app/Providers/HorizonServiceProvider.php:18`). A
  disabled user with the permission opens it.
- There is no Sanctum, no API token and no authenticated route in `routes/api.php` (health and the
  two signed webhooks only). The only guard is `web`.
- `bootstrap/app.php` has NO `priority()` list; Laravel's default list applies
  (`vendor/laravel/framework/src/Illuminate/Foundation/Http/Kernel.php:103`). In it `auth`
  (`AuthenticatesRequests`) outranks `SubstituteBindings`, which is in the `web` group, so `auth`
  is pulled ahead of anything appended to the `web` group that is not itself in the list. A check
  appended to `web` would therefore run AFTER `auth`, not before it.
- Lockout: nothing in `app/` writes `users.is_active` (no screen, no command; `hq:user:create`
  leaves the default `true`, `hq:user:update-email` does not touch it). Only SQL or tinker can
  disable anybody today. This stream adds no way to disable a user either.
- Audit today: `app/Listeners/RecordAuthenticationEvents.php` writes `auth.login`, `auth.logout`
  and `auth.login_failed` (system actor, `after.email`). The login form's failure sentence is the
  generic `auth.failed`.

**P0-2, `BusinessScope`.** `app/Support/Authorization/BusinessScope.php:35-42` caches a person's
rows under `hq:business_scope:{id}` for 60 seconds in the default cache store, which is Redis in
production (`deploy/.env.example:31`) and therefore shared by the web containers, Horizon workers
and the scheduler. `forget()` (`:79`) exists and NOTHING calls it. Nothing in `app/` or
`database/` writes `user_business_scopes` at all (no screen, command, seeder or observer; the rows
are made by hand or by tests), so there is no mutation path an invalidation could hang on, and a
raw SQL write could never be seen by one. Every reader goes through `all()`:

- the gateway: `app/Gateway/ToolGateway.php:478` (`allows`, a human's single target) and `:568`
  (`all`, the cross-business filter);
- the resolver: `app/Execution/ExecutionContextResolver.php:163-164`;
- Ask HQ: `app/Ask/AskHq.php:111` and the preview through the resolver;
- screens and badges: `AdminController`, `AgentController`, `AskController:100`, `InboxController`,
  `BriefingController`, the three Observe dashboards, `Scheduling/Nl/EntityResolver`, `NavBadges`.

So for up to 60 seconds a removed row keeps its reach (at the gateway too), and because "no rows
means group-wide" is cached as `[]`, a person given their FIRST row stays group-wide for up to 60
seconds: the dangerous direction. A second finding: `stillStands()`
(`ExecutionContextResolver.php:98-114`), the gateway's per-call re-read, checks the agent and that
both people are alive, and never re-reads business scope; a run resolved group-wide keeps
`businessIds = null` to its end however its people are narrowed meanwhile.

There is no profiling evidence anywhere in the repository for the cache (no benchmark, no doc, no
commit message); the query it saves is one indexed `pluck` on a table that has zero rows in
production.

**`--by`.** `app/Console/Commands/ShadowOpen.php:75-83` treats `--by` as optional in every
environment and does not look at `is_active`. `ShadowPeriods::open()`
(`app/Agents/Shadow/ShadowPeriods.php:163`) accepts `actorUserId = null` and then audits as
`system`. `Requester::forShadowPeriod()` (`app/Execution/Requester.php:60-65`) turns a period with
no opener into `as_enabled`, and `ShadowProbes::ask()` (`app/Agents/Shadow/ShadowProbes.php:75`)
labels the run with the enabling user. `--no-schedule` IS already refused in production, in the
domain class (`ShadowPeriods.php:173`); the command only prints a note (`ShadowOpen.php:55`).

#### C.1.2 The design (written before the code)

**P0-1. Two layers, because each covers what the other cannot.**

1. *The user provider is the chokepoint for getting IN.* `App\Auth\ActiveUserProvider` extends the
   Eloquent provider and returns nobody for a disabled account from `retrieveById` (the session),
   `retrieveByToken` (remember me) and `retrieveByCredentials` (the password step and the password
   broker, which both resolve the account through it). It is registered as the driver of the one
   provider in `config/auth.php`. No route, guard call or future controller can forget it, because
   there is no other way for the framework to turn an id, a cookie or an email into a user. The
   login form answers a disabled account with the same `auth.failed` sentence as a wrong password
   (the framework's 200 ms timebox already equalises the timing), and the reset form answers with
   the broker's own "no user" sentence, which is what it says for an unknown address today. There
   is no environment switch and no role exemption.
2. *A middleware ends the session.* `EnsureAccountIsActive` is appended to the `web` group (so it
   is on every web route, Horizon's included, with no per-route opt-in) and placed in the priority
   list BEFORE `AuthenticatesRequests`, so it runs before `auth` rather than after it. It reads the
   session's user id from the guard, reads `is_active` fresh from the database (one primary-key
   query; correct under a long-lived worker and under `actingAs`), and for a disabled account:
   audits `auth.session_rejected` (system actor, target the user, reason "the account is
   disabled"), cycles the remember token so an old cookie dies even if the account is enabled
   again, logs the guard out, invalidates the session, regenerates the CSRF token and throws the
   framework's `AuthenticationException`. The framework then answers exactly as `auth` does: a
   redirect to `login` for a page or an Inertia visit, 401 JSON for a request that expects JSON.
   The message never says "disabled".
3. *Audit.* A refused password step keeps writing `auth.login_failed` through the existing
   listener; the listener adds `reason: 'the account is disabled'` when the address belongs to a
   disabled account, so the record is truthful while the screen stays generic. A refused reset
   request writes `auth.password_reset_refused` the same way.
4. *Proof that the guard is reached.* A test enumerates every registered route: each route that
   carries `auth` must carry the check, and carry it EARLIER in the sorted middleware the router
   will actually run; every route in the `web` group (Horizon's too) must carry it; and no `api`
   route may carry `auth` at all (the day one does, the test fails and the author must decide).
5. *Lockout.* No way to disable a user is added. `is_active` stays unfillable, and a test holds
   that; `hq:user:create` still creates an enabled user.

**P0-2. The cache is removed; nothing replaces it.** Reasons: (a) there is no mutation path to
invalidate from, and hand-made rows are exactly how scope changes today; (b) the shared Redis store
makes any invalidation a cross-process problem; (c) no evidence anywhere says the query matters;
(d) a request-scoped memo would be wrong inside a Horizon worker or a minutes-long agent run, where
one object outlives many decisions. `BusinessScope::all()` reads the rows every time. `forget()`
goes with the cache (nothing called it). Widening can then only appear once the row is committed,
and narrowing appears on the next read, in every process.

The per-call re-read learns business scope: `stillStands()` re-reads both people's rows, and the
context stands only while its business set is still inside each person's current scope (a
group-wide context does not survive either person gaining rows). When it does not, the rest of the
run's calls are refused, the same fail-closed ending a disabled person or a paused agent has. A
context is never widened mid-run: a row added meanwhile shows at the next resolve.

Tests use a persistent `file` cache store for the cross-process cases, not only the `array` store
the suite defaults to (`phpunit.xml:24`): the key the old code would have written is planted, as
another process would have left it, and must be ignored. Redis itself is not installed on this
laptop (no `phpredis`, no server), so the file store stands in for "a store that outlives the
process"; with the cache gone the store's kind no longer matters.

**`--by`.** Enforced in `ShadowPeriods::open()` (the chokepoint; the command is one caller):
in production the opener must be a user who exists and is not disabled, otherwise it refuses and
changes nothing; `--no-schedule` stays refused there. The command validates `--by` before it
reports, refuses a disabled or unknown user in every environment when `--by` is given, and in
production says so in the dry run as well. `Requester::forShadowPeriod()` in production names the
recorded opener or nobody (which the resolver refuses): it never falls back to `as_enabled` there,
so even a row written around the command cannot run probes on implicit authority. The audit rows
are already written under `actorUserId`; with the opener mandatory, every production opening is
`human`, under the named person. Local and testing keep today's convenience path. What the command
switches on, its preconditions and the probes' questions are untouched.

#### C.1.3 As built (21 September)

No migration. No prompt file, TaskGrounding, TaskScope, detector, policy or approval code touched.
Nothing switched on; no flag row, grant or scheduled job created.

**P0-1.**

- `app/Auth/ActiveUserProvider.php` (new): `retrieveById` `:29`, `retrieveByToken` `:34`,
  `retrieveByCredentials` `:39` return nobody unless `is_active === true`. Registered as the driver
  `active-eloquent` (`app/Providers/AppServiceProvider.php:92`, `config/auth.php:68`).
- `app/Http/Middleware/EnsureAccountIsActive.php` (new): `:43` reads `is_active` by primary key for
  the session's user id; for a disabled account it audits `auth.session_rejected` (`:47`), cycles
  the remember token (`:52`), logs out, invalidates the session and throws
  `AuthenticationException` (`:60`), so pages and Inertia visits go to `login` and JSON gets 401.
- `bootstrap/app.php:40` appends it to the `web` group; `:46` places it in the priority list ahead
  of `AuthenticatesRequests`. Measured with `gatherRouteMiddleware`: on `dashboard` it is index 5,
  `Authenticate` index 6; on Horizon it is index 6, Horizon's own `Authenticate` index 10. With
  line 46 deleted, three tests fail ("two-factor/challenge runs auth before the account check"),
  so the priority entry is load-bearing and held by a test.
- Audit: `RecordAuthenticationEvents::failed` adds `reason: 'the account is disabled'` to
  `auth.login_failed` when the address is a disabled account, and is otherwise byte for byte what
  it was (a test pins the ordinary row). The two reset controllers write
  `auth.password_reset_refused` (`step: link|reset`). The screens say what they said: `auth.failed`
  at login, the unchanged generic status for a reset link, the broker's `passwords.user` for a reset.
- What a disabled account meets now: password step refused; the 2FA challenge redirects to login
  and verifies nothing; a verified session is rejected on its next request (new session id, 2FA
  key gone, remember token cycled, one audit row, and the request after that is a plain guest);
  JSON 401; Inertia visit redirected; `/horizon` redirected and its API 403; remember-me cookie
  dead (the same cookie revives the user while enabled, so the test proves the cookie was good);
  no reset link, no token row, and a token issued before the disabling resets nothing.
- Lockout: no code path disables anybody. A test holds that `is_active` is not fillable, that
  `hq:user:create` makes an enabled user, and that no file under `app/` assigns `is_active => false`
  to a user. The sole owner can be disabled only by SQL or tinker, as before; the recovery is the
  same SQL (`update users set is_active = true where id = ...`), which needs no sign-in.

**P0-2.**

- `app/Support/Authorization/BusinessScope.php:40-45`: `all()` reads the rows (ordered) on every
  call; the cache, its TTL constant and `forget()` are gone. Three tests that called `forget()`
  lost that line and nothing else.
- `app/Execution/ExecutionContextResolver.php:117-129`: `stillStands()` re-reads both people's
  rows; a context whose business set is no longer inside a person's current scope (or that was
  group-wide when a person has since gained rows) ends the run's remaining calls with "narrowed
  after this run was resolved". A widening mid-run changes nothing until the next resolve.
  ADR-047 carries an amendment.
- Callers needed no change: the gateway (`ToolGateway.php:478`, `:568`), the resolver, Ask HQ and
  every screen already go through `all()`.

**`--by`.**

- `app/Agents/Shadow/ShadowPeriods.php:230` `assertOpener()`, called first thing after the
  preconditions in `open()` (`:173`): in production nobody named is a refusal; a named person must
  exist and be enabled in every environment. `--no-schedule` stays refused in production (`:175`).
- `app/Console/Commands/ShadowOpen.php`: the opener is resolved and checked before the report, the
  report prints `opener: ...` with the refusal, and a dry run fails when the opener is refused.
- `app/Execution/Requester.php:69`: in production a period with no recorded opener names nobody
  (the resolver refuses it, audited `agent_run.context_refused`); it answers `as_enabled` only off
  production. The runbook `docs/runbooks/shadow-week.md` says so.

**Tests watched failing on the unmodified code, then passing.**

- `tests/Feature/Security/DisabledAccountTest.php` (15 tests): 11 failed first (password step, 2FA
  challenge, live session, JSON and Inertia, the handed-over guard, Horizon, remember-me, reset
  link, reset token, the route enumeration, the one-door test). The 4 that passed first describe
  behaviour that must not change (ordinary failed-login audit, an enabled user end to end, an
  enabled user's reset, no way to disable anybody).
- `tests/Feature/Security/BusinessScopeFreshnessTest.php` (11 tests with datasets): all 11 failed
  first, each on the stale answer (one also on a test typo, corrected before the fix). The
  cross-process cases run on a `file` cache store with the old key planted as another process would
  have left it. Redis is not installed on this laptop, so Redis itself was not exercised; with no
  cache left in the class the store's kind cannot matter.
- `tests/Feature/Agents/ShadowPeriodTest.php` (+8 with datasets): 4 failed first (no `--by` in
  production, a disabled opener, the domain-class refusals, a production period with no opener
  running `as_enabled`). Passing first, because the behaviour already existed: an unknown `--by`,
  the opening audited under the named person, the probes resolved from the opener, the local
  convenience path. The existing `--no-schedule` production test now passes `--by`, so it still
  reaches the refusal it is about.

**Verification (local; GitHub Actions deferred until 1 October).** Base 977. Full Pest suite in one
run: **1011 passed, 8608 assertions**, 232 s, database `hq_test_e`. Folder by folder, summing to the
same 1011 and 8608: Unit 1, Agents 114, Ai 53, Ask 8, Auth 15, Autonomy 14, Briefing 61,
Commerce 8, Consent 8, Conversation 70, Erp 18, Evaluation 176, Facts 29, Foundation 14,
Gateway 174, Identity 9, Media 7, Observe 86, Platform 21, Scheduler 34, Security 80, Settings 6,
DashboardTest 5. `vendor/bin/pint --test` passed. `npx tsc --noEmit` clean. `npm run build` built.

**Decisions the owner may want to revisit.**

1. A narrowing mid-run ENDS the run's remaining calls, even for a business the person still has.
   The narrower reading was chosen; the alternative is to refuse only the calls that fall outside.
2. The remember token is cycled when a disabled session is rejected, so re-enabling the account
   does not bring an old "remember me" cookie back. The person signs in again.
3. A rejected session is audited once per session (`auth.session_rejected`), as `system` with the
   user as target, because at that moment nobody is authenticated.
4. `--by` naming a disabled or unknown person is refused in every environment, not only production.
5. No cache at all for business scope. If a screen ever shows this query as a cost, the place for a
   memo is that screen's own request, never the shared class.
6. Nothing here adds a way to disable a user. When one is built it needs its own rule for the last
   enabled owner.

**Found on the way, not fixed (outside this stream).**

- Horizon is on the `web` group only, so a user who has passed the password step and NOT the 2FA
  challenge can open `/horizon` if they hold `hq.horizon.view` (`config/horizon.php:86`). The two
  2FA middlewares are not on it.
- `ToolAvailability` (`app/Gateway/Registry/ToolAvailability.php:27`, `:108`) caches the tool kill
  switches for 30 seconds in the shared store, and `Features`
  (`app/Support/Features/Features.php:42`) caches flags for 60 seconds. A switch
  turned off by the screen calls `forget()`; one turned off by SQL, or by another process that
  does not, can stay on for up to 30 seconds at the gateway. The same family as P0-2.
- `phpunit.xml` names `CACHE_STORE` twice (`:24` and `:30`), harmless.
- The brief expected a `priority()` list in `bootstrap/app.php`; HQ has none and ran on Laravel's
  default until this stream added one entry.

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

#### C.3.2 As built (21 September)

No migration. No PHP, prompt, TaskGrounding, TaskScope, detector, policy or approval file touched.
Nothing switched on. Only `deploy/` changed, and nothing in it has run on the VPS.

**The invariant, one implementation.** `deploy/backup-first-lib.sh` (new, sourced, never executed)
holds `running_release` (`:25`, the image label), `bf_stack_is_up` (`:33`), `ensure_backup_boundary`
(`:44`, the dead bind-mount repair and the do-not-delete marker), `backup_mount_is_live` (`:60`) and
`take_verified_dump` (`:105`, `backup.sh once`, then `LAST_STATUS`, `LAST_OK` and a non-empty dump,
each a refusal that names the verb). `deploy.sh` sources it (`:57`) and calls the two at `:126-127`
exactly where its own copies used to be; `rollback.sh` calls the same two (`:132-133`). There is no
second copy of the backup-first logic anywhere.

**`deploy.sh`.** An argument it does not know is refused before anything happens (`:41`,
"Nothing has moved"), so `--nopull` no longer means "pull". `--verify-only` hands over to
`deploy/verify-only.sh` with `exec` (`:45-51`) before the `.env` guard, the sources or any read of
the stack. Everything else is byte-for-byte the release path it was.

**`rollback.sh`, in the order it runs.** The whole body is `main()` (`:45`), called on the last line
(`:214`), so bash has parsed the file before `git checkout` replaces it. Refusals first, every one a
read, so a refused rollback moves nothing and takes nothing: usage (`:47`), `.env` present and 600,
a target the checkout knows (`:61`), the running release from the label, the target already running
(`:76`), neither an ancestor of the running release nor a release `RELEASES.md` names (`:89`), tracked
files modified, named (`:95`), the database not running (`:97`), and then the configuration check:
the override `no-migrations.override.yml` is written to `mktemp -d` under `TMPDIR` (`:109`), the
TARGET's compose file is read with `git show` (never checked out), and the two are resolved together
through `docker compose config`; anything but `0` for every service that sets the key is a refusal
(`:125`). ⚠ **This moved ahead of the dump**, against C.3.1, which put it after: it is a read like the
others, so there is no reason for a rollback that is going to be refused to take a backup first.
Then the boundary and the verified dump, then `git checkout` (`:137`), the stamped build, `up -d
--no-deps app horizon scheduler web` with the override as a second `-f` (`:150`), the started `app`
container's environment read back with `docker inspect`, and a loud failure at stage `up` unless it
is `RUN_MIGRATIONS=0` (`:157`). `RELEASES.md` records `code only, no migrations run, dump <path>`
(`:192`); `release.json` records kind `rollback` with the label-derived predecessor, now `previous_known:
true` when the label is readable (it was always `0` before) and the dump the rollback stood behind.

**Compose and the entrypoint.** `docker-compose.yml:47` keeps `RUN_MIGRATIONS: "1"` on `app` (a deploy
migrates) with a comment saying the rollback overrides it and why it is an override and not a
variable. `entrypoint.sh:45` logs `migrations not run: RUN_MIGRATIONS is '0'` when it skips, so the
`app` log of a rollback (to a release carrying this commit) says so; the test it runs is unchanged.

**The real compose, not only the stub.** Docker Compose v5.5.1 (the client here; `config` needs no
daemon) was run on the exact files: `3314c54`'s `docker-compose.yml` alone resolves `RUN_MIGRATIONS:
"1"`; with the rollback's override it resolves `"0"`; the pipeline `rollback.sh` uses on that output
yields `0`; the new compose file with the override resolves `"0"`; and a `deploy/.env` that set
`RUN_MIGRATIONS=1` (which would make horizon and the scheduler migrate too, through `env_file`) yields
`0 1`, which the rollback refuses. So the override does take effect over every earlier release's
hardcoded `"1"`, which is the case that matters for a rollback to `3314c54`.

**`deploy/verify-only.sh`** (new, 396 lines) is the C.3.1 table as planned: every check `ok`, `WARN`
or `FAIL` with its reason, exit 1 on any FAIL, "It is not a deployment" at the top and `NOTHING WAS
RELEASED` at the bottom. `GIT_OPTIONAL_LOCKS=0` (`:38`) because even `git status` refreshes the index
when it can; `git ls-remote` (`:253`) instead of a fetch; the dead mount found by comparing inodes,
without writing through the mount (`:216`); the destructive-migration scan reads only each pending
migration's `up()` (`:309`). **Added beyond C.3.1:** `HQ_ROOT` (`:36`) names the checkout when the
file is run from anywhere else, because production's checkout at `3314c54` has no `verify-only.sh`:
before the next deploy it is run from a copy read out of git (`deploy/README.md`). An `HQ_ROOT` that
cannot be entered is exit 2 with the reason. `--verify-release` was designed and not built, as
C.3.1 decided.

**`deploy/README.md`.** Release starts with `--verify-only`; a Preflight section says what it checks
and what a clean result does not prove; the "NOT YET APPLIED TO PRODUCTION" section is rewritten for
the deploy from `3314c54` to the candidate, with the lines Joy types (fetch; confirm `origin/main` is
the candidate; verify-only from a copy of the candidate's script with `HQ_ROOT`; `./deploy/deploy.sh`
run by the OLD script; `--verify-only --no-pull` then `--no-pull` run by the NEW one; what to look
at; `./deploy/rollback.sh 3314c54` as the way back); Rollback states the invariant, the refusals,
backup first, the override and its read-back, and that the schema is never rolled back.

**Watched failing first.** The suite runs the real scripts against a stubbed `docker` and a
throwaway git repository. The stub learned three things (C.3.1): `RUN_MIGRATIONS` resolved from the
`-f` files a command is really given, last file wins, recorded at every `up` that starts `app`; a
`config` answer; the environment `inspect`. It carries the real `docker-compose.yml`. The base suite
had 91 checks; it now has **238**. The same new test file and stub were run against the scripts of
`5c4b948` (the base of this branch, copied to a scratch tree): **116 passed, 122 failed**. All 91 old
checks passed there, so **122 of the 147 new checks were watched failing**: every one about the
rollback running migrations, taking no dump, accepting a running, unknown or unrelated target or a
dirty checkout, recording `previous_known` false, and every "nothing changed" assertion of
verify-only (the old `deploy.sh` read `--verify-only` as "pull" and DEPLOYED). The 25 that passed
against the old scripts are guards that must not change (a deploy still migrates, a rollback still
succeeds and moves the checkout, `--no-pull` back to main migrates) or checks that are vacuous there
(for example "an `HQ_ROOT` that is not a directory is refused" passes when the file does not exist).
⚠ The unverified checkpoint itself failed 2 of its own checks on the first run: the counter meant to
prove "no container was touched" counted the backup mount probe (`rm -f` INSIDE the backup
container) as a container removal. The counters now read an `exec` line for its command only, and
the two checks pass because the counting is right, not because the check was loosened.

**Verification (21 September, local; GitHub Actions validation deferred until the 1 October
reset).** Combined with `main` (`074ffae`, streams E and F) by a `--no-ff` merge into this branch.
Deploy-safety suite: **238 passed, 0 failed**, on Git Bash on NTFS with a `stat` shim on PATH (kept
in the scratchpad, NOT in the repository) that answers `600` to `stat -c %a`, because NTFS cannot
represent a file mode and the suite otherwise skips itself; so the one verify-only case that needs a
real mode (`.env` readable by others) was skipped there. ⛔ **Linux parity is unproven** until the CI
job `deploy-safety` runs on Ubuntu after the reset. `bash -n` clean on every script in `deploy/`,
`deploy/tests/` and `deploy/gate/`. ShellCheck is not installed here and was not run. Full Pest suite
in one run on Postgres `hq_test_g`: **1046 passed, 9368 assertions**, 328 s (no PHP changed; the
number the next merge must not reduce). `vendor/bin/pint --test`: passed. `npx tsc --noEmit`: clean.
`npm run build`: built.

**Decisions the owner may want to revisit.**

1. A rollback target must be an ancestor of the running release or a release `RELEASES.md` names.
   Anything else is refused and pointed at `deploy.sh --no-pull`. A hand-typed commit from another
   line is therefore never a "rollback".
2. A rollback refuses when the database container is down (nothing to dump). Starting a stack is a
   deploy.
3. The configuration check refuses unless EVERY service that sets `RUN_MIGRATIONS` resolves to `0`,
   so a `RUN_MIGRATIONS` in `deploy/.env` blocks rollbacks entirely. That is deliberate: it would make
   horizon and the scheduler migrate on every start.
4. A destructive statement in a pending migration's `up()` is a WARN in verify-only, not a FAIL: the
   additive rule is the project's, and a deliberate exception should be possible after reading it.
5. `VERIFY_MIN_FREE_MB` defaults to 5120 and `VERIFY_MAX_DUMP_AGE_HOURS` to 26. These are operational
   defaults chosen here (a dump plus a cached build; the nightly dump plus two hours), not business
   numbers; both are environment overrides.
6. After a rollback, a bare `docker compose up -d` typed by hand recreates `app` with migrations on.
   Documented, not prevented: the way forward is a deploy.

**Found on the way, not fixed.**

- The FIRST run of the next deploy is executed by production's OLD `deploy.sh` (`3314c54`), so none of
  this stream protects that one run: its unknown-argument handling is the old one (anything but
  `--no-pull` pulls). The README's lines type the argument-free form for exactly that reason.
- verify-only does not check `deploy/.env` for a stray `RUN_MIGRATIONS`; the rollback refuses it, but
  a deploy would silently make every PHP container migrate at start.
- `docker compose config` prints every `.env` value (through `env_file`). `rollback.sh` captures it
  in a pipe and prints only the digits; nothing else in `deploy/` runs `config` without `-q`.
- The suite takes about 8 minutes on this laptop (git on NTFS), against well under a minute on Linux.

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

#### C.4.1 As built (21 September)

The branch took `main` first (merge `0bb4072`: streams E and F), so everything below was built and
verified on top of the disabled-account, fresh-scope, `--by` and approval-requester changes. No
prompt file, TaskGrounding, TaskScope or detector file changed; the agent stamps are still
`executive.v8+18d60e98` and `support.v1+0f4a195b`. Nothing switched on: no policy version, no grant,
no flag row, no scheduled job was created, and no seeder or migration writes any of them.

**The schema** is the plan's, one migration (`2026_09_21_100000_create_autonomy_evidence_policy_tables`):
four tables, `agent_runs.autonomy_evidence`, the partial unique index for "at most one active", and
three plpgsql functions bound by five triggers (the policy guard, the two child-row guards, the two
append-only event guards). Rehearsed on Postgres `hq_test_h`: applied (4 tables, the column, 5
triggers, 3 functions, the index present), rolled back (all of them gone), re-applied (all back). No
number is defaulted anywhere in it.

**Governance.** `App\Autonomy\Evidence\EvidencePolicies` is the one writer: `propose()` (numbers may be
null), `activate()` (a named, enabled person holding `hq.policies.manage`, a reason, no undecided
field, at least one requirement, no gap in the ladder; retires the previous version in the same
transaction; content digest written), `retire()`, `snapshot()`, `digest()` (the rules alone, so the
same rules give the same digest whatever the row ids), `structuralRefusals()` (what in the version
itself blocks activation, shown on the page and by `show`). Console only:
`hq:autonomy-policy:propose {file}`, `:activate {version}`, `:retire {version}`, each a dry run until
`--apply`, and `:show {version?}` (reads only). Every act writes an event row with a snapshot and an
audit row under the named person.

**The producer.** `App\Autonomy\Evidence\EvidenceProducer` and the value objects `MeasuredEvidence` and
`RunnerEvidence`:

- `measure(Agent)`: the recount the evidence view did, moved unchanged (applicable suites, the latest
  finished run per suite, the role-applicable results of those runs WITH their `checks`, the declared
  cases, every figure with its run and result ids, the stored figure beside the recount), plus
  `checkSources()` (a grader check recounted from `evaluation_results.checks`, never from
  `check_rates`) and `digest()` (exactly what was counted). More than `MAX_RESULT_ROWS` (20,000)
  result rows is refused: nothing is counted and nothing is met.
- `judge(evidence, policy, now)`: per level A1 to A4 every requirement and hard check beside its
  measured value, `met` or the exact reasons, cumulative eligibility, and the evidence ceiling with
  the reasons it goes no higher. The rules are the plan's rules 3 to 5, each failing closed.
- `forRunner(Agent)`: null when no policy is active (one query). With an active policy and no live
  grant that could name the agent: the version is recorded, `evidence_counted: false`, ceiling A0,
  no evaluation table read. Otherwise the ceiling, and the clean rates (overall, per dimension, per
  suite) floored to thousandths.

**The resolver** (`AutonomyResolver`): the policy enters as one more ceiling (`evidence_policy`),
combined by minimum; a grant naming `evaluation_suite_key` is compared with that suite's own figure
(`AutonomyRequest::$suiteRates`) and never the pooled one; the decision carries the policy and
evidence it was judged under (`AutonomyDecision::$evidence`, key `evidence_policy` in `toArray()`)
only when a policy was applied, on every path including the floors.

**The runner** (`AgentRunner::resolveAutonomy()`) asks `forRunner()` first and passes what it returns;
`agent_runs.autonomy_evidence` stores the record (policy id, uuid, version, digest; evidence digest;
run ids; prompt stamp; ceiling and its reasons; the rates; when it was judged), null when no policy
was active.

**The view** (same GET route, still write-free, now built on the producer): the active version with
its rules, digest and activator, or "No autonomy evidence policy is active, so evidence cannot widen
autonomy."; every proposed version listed as not in force with what blocks it; per agent the policy's
judgement level by level; and the resolver asked exactly the way the runner asks it.

**Tests watched failing first, then passing.**

- `AutonomyResolverTest`: 3 of 16 failed on the unmodified resolver (the suite-named grant, which
  answered A2 on a pooled 0.96; the suite-and-dimension grant; the policy ceiling). ⚠ The existing
  test "requires measured evidence when the grant asks for it" was amended: it asserted that a
  pooled 0.96 satisfies a grant naming `safety.core`, which is finding 3 itself. It now asserts A0
  for a pooled figure, A0 for another suite's figure or a low suite figure, A2 for the suite's own.
- `AutonomyEvidenceRunnerTest` (5): 4 failed on the unmodified runner. The fifth (no policy, nothing
  recorded, the grant decides as before) passed first, as it must.
- `AutonomyEvidencePolicyViewTest` (3): all 3 failed against the view as it was before the policy
  section. The 13 older `AutonomyEvidenceTest` tests are unchanged and green on the producer.
- `EvidencePolicyTest` (9) and `EvidenceProducerTest` (12) test classes that did not exist, so their
  first failure proves nothing. Their teeth were shown by mutation instead, each applied to the
  built code, the three files run, and the code restored:

| Mutation | Caught by |
| --- | --- |
| the overall rate substituted for a missing dimension | 5 producer tests |
| an unmeasured result counted as a pass | the unmeasured test |
| one satisfied dimension treated as enough for a level | the every-requirement test and the hard-check test |
| a policy with a NULL field activated (domain check removed) | 3 policy tests (the trigger still refused underneath) |
| evidence on an old prompt stamp accepted | the producer stamp test and the runner stamp test |

`ExecutionContextIdentityCaseTest` stayed green WITHOUT re-pinning.

**Verification (local; GitHub Actions validation deferred until the 1 October reset).** Base 1046.
Full Pest suite in one run on Postgres `hq_test_h`: **1077 passed, 9718 assertions, 0 failed**, 259 s (1046 on the base plus 31: resolver +2, runner 5, view 3, policy 9, producer 12). `vendor/bin/pint --test`:
passed. `npx tsc --noEmit`: clean. `npm run build`: built. The page was
Prettier-formatted (it was clean on the base commit).

**The decisions left for the owner (every one of them undecided today, and no version exists).**

1. Which levels get requirements at all (a level the policy says nothing about is unreachable
   through evidence, and levels are cumulative).
2. Per level, which of the six dimensions are required (reasoning, tool selection, policy,
   enforcement, execution, outcome).
3. Per requirement: `min_agreement_rate`, `min_sample_count`, `max_evidence_age_days`.
4. Per level, which of the seventeen grader checks are hard checks, and per hard check
   `min_sample_count` and `max_evidence_age_days`.
5. Who activates it (`--by`) and why (`--reason`). Activating it is not granting anything: every
   grant is still a separate owner decision, and production grants stay at zero.

**Decisions to revisit.**

1. Rates reach a grant only while a policy is active. With none, a grant requiring evidence stays
   unmatched, exactly as before; the owner cannot use a rate-gated grant without first activating a
   policy. Chosen because the policy is where "clean evidence" is decided.
2. "Clean" for the rates handed to a grant means current stamp, passed gate, zero unmeasured,
   recount agreeing, every declaring suite run. Age is not in it: age belongs to the policy's
   requirements, and the policy's ceiling bounds the level anyway.
3. A hard check applies the stamp, age and zero-unmeasured rules (plan rule 5), not the gate and
   recount rules a requirement applies. A run that failed its gate for another case can still show a
   hard check at 100 percent.
4. `MAX_RESULT_ROWS` = 20,000 is a cost bound, not an autonomy threshold; above it nothing is
   counted. The latest runs hold a few hundred rows today.
5. `propose` is also a dry run until `--apply`, which the plan did not ask for; `--by` is optional
   on a proposal (it records the proposer when given).
6. A retired version is never reactivated; a change of mind is a new version.
7. The view's "what the agent may do now" panel now asks the resolver with the producer's evidence,
   as the runner does; with no policy its text is what it was.

**Found on the way, not fixed (outside this stream).**

- Locally, `php artisan migrate:fresh` against a worktree database fails with a Redis connection
  error unless `CACHE_STORE=array` is set, because the worktree `.env` names Redis and this PC has
  no Redis. The suite is unaffected (`phpunit.xml` sets the array store).
- `EvidencePolicies::retire()` saves the model it is given, so a caller holding a model with other
  unsaved changes is refused by the trigger (fail closed; seen once as a test artefact).

**Not done.** The page was not walked in a browser (needs Joy's 2FA session). `docs/68` is left for
the merge step.

### C.5 Stream I: Phase 4 read side (product, variant, price, stock, eligibility), reads only

**Status: plan written before the code (this section), then the as-built record below it. Branches:
HQ `p4/erp-read-foundation` (worktree `hq-wt/i`), ERP `feature/hq-api-order-reads` (worktree
`erp-wt/hq-reads`, from `origin/master`). No order is created, changed or cancelled by anything in
this stream, on either side; `take_order` is registered nowhere; no write ability is added to any
token; nothing is switched on.**

#### C.5.1 What both codebases hold today (read, not assumed)

ERP (`erp-wt/hq-reads`):

- The HQ API is `routes/api.php:18-33`: `auth:sanctum` + `throttle:120,1`, then `{business}` under
  `HqApiContext` (`app/Http/Middleware/HqApiContext.php:23-53`: the token must hold
  `hq:business:{id}`, the business must be active, `TenantScope::enter` from the TOKEN, the
  `X-Correlation-Id` header echoed when it matches `^[A-Za-z0-9._:-]{8,128}$`, else a fresh uuid).
  One controller, `app/Http/Controllers/Api/Hq/HqReadController.php`, with inline field arrays (no
  Resource classes), `requireAbility()` (403 `{"error":{"code":"forbidden","message":"Token lacks
  ability X."}}`, lines 253-258), `ok()` (`data` + `meta.as_of`, line 270), `paginated()` (line
  275: `meta.page/per_page/total/as_of`, `links.next/prev`) and `error()` (line 284). Thirteen
  routes; the one write is `POST tickets`.
- `HqApiToken::READ_ABILITIES` (`app/Console/Commands/HqApiToken.php:23-27`) is the whole
  allow-list: `inventory.view`, `inventory.reports.view`, `sales.view`, `sales.reports.view`,
  `sales.customers.view`, `purchasing.view`, `purchasing.reports.view`, `accounting.view`,
  `hrm.employees.view`, `hrm.attendance.view`, `crm.tickets.view`, `crm.tickets.manage`,
  `crm.contacts.view`. ⚠ Finding: `inventory.view`, `sales.view` and `sales.customers.view` are
  NOT Spatie permissions seeded anywhere in the ERP; they are the ERP's AI `ReadableData` names
  (`app/Modules/Foundation/Support/Ai/ReadableData.php:55-87`). The screens themselves gate the
  product list on `inventory.reports.view` (`app/Modules/Inventory/routes/web.php:42`) and the
  price list and customer pages on `sales.reports.view` (`app/Modules/Sales/routes/web.php:26-33`).
  `docs/40`'s "abilities are the ERP's own permission names" is therefore true for the report and
  ticket abilities and loose for the three `*.view` names. Reported, not changed.
- Tests: `tests/Feature/HqApi/HqApiTest.php` (7 tests; `Sanctum::actingAs` with an ability list
  plus `hq:business:{id}`; models created `withoutGlobalScopes()` with an explicit `business_id`).
- Product: `app/Modules/Inventory/Models/Product.php` (`sku`, `barcode`, `name`, `type`
  (`ProductType`, `Service` is not stockable), `is_active`, `availability_override`
  (`AvailabilityPolicy`, narrows only), `lead_time_days`, `low_stock_threshold`; relations
  `brand`, `category`, `unitOfMeasure`; `currentStockQuantity()` sums active batches, normalised
  decimal string). ⚠ There is NO `cost_price` column on products and NO `mrp` column anywhere in
  the schema: cost lives on `stock_batches.unit_cost` (`averageUnitCost()`), and `mrp` and
  `cost_price` are spreadsheet columns the price importer knowingly does not import
  (`app/Modules/Sales/Support/PriceListFile.php:71`, `NOT_A_SELLING_PRICE`).
- Variants: the ERP has no variant concept (confirmed again: `app/Modules/Inventory` and
  `app/Modules/Commerce` hold no variant model). A SKU identifies exactly one product per business
  (`docs/79` C.1, C.2).
- Price: one engine, `app/Modules/Sales/Actions/ResolveSellingPrice.php` (customer rule beats
  price-group rule beats default; highest quantity break reached; RETAIL when no group, lines
  212-233; with no customer and a `priceGroupId`, the store's own group, lines 159-176). The rule
  travels with the number: `ResolvedPrice` (`app/Modules/Sales/Support/ResolvedPrice.php`) carries
  `tier()` (`customer`, `price_group`, `default`) and `explain()`. Rows are `product_prices`
  (`ProductPrice`: `customer_id`, `price_group_id`, `min_quantity`, `unit_price`, `effective_from`,
  `effective_to`, `is_discount`, `discount_label`, `note`). ⚠ Finding: the "10/15/20 percent off
  the selling price" tiers are NOT an ERP rule: the ERP stores absolute `unit_price` rows per price
  group (`RETAIL`, `DEALER`, `DISTRIBUTOR`, `CORPORATE`, `PriceListFile::PRICE_COLUMNS`), and the
  percentages live in the spreadsheet that fills them. The price the ERP would apply to an HQ order
  is the engine's answer at the store's group (`SalesOrderOrigin::website`, `docs/79` C.4).
  Promotions (`app/Modules/Sales/Support/ApplyPromotions.php`, Buy X Get Y) are applied by
  `RecordCounterSale` only; `CreateSalesOrder` applies none. So a sales order carries no promotion,
  and the price endpoint says so rather than pretending one applies.
- Availability: `app/Modules/Commerce/Support/Availability.php` decides per STORE (`policyFor`:
  the store's `availability_policy` narrowed by the product override; `decide`: inactive is "out
  of season", zero stock refuses only under `Strict`, else `madeToOrder`). Without a store there
  is no verdict in the ERP, only the facts it would decide from. `CartRules::refusalFor`
  (`app/Modules/Commerce/Support/CartRules.php:27-47`) adds "published on this store" and "a price
  in force" before the availability verdict. Stock per warehouse is what `HqReadController::stock`
  already aggregates (`stock_batches` active, grouped by product and warehouse, line 79-92).
- Customer: `app/Modules/Sales/Models/Customer.php` (`is_active`, `is_system` for the seeded
  walk-in row, `phone` kept verbatim, `phone_normalized` derived by `PhoneIdentity::normalize`
  on save, `payment_terms`, `credit_limit`, `price_group_id`). ⚠ Findings the eligibility endpoint
  must state and not improve on: the ERP has NO blocked flag, NO COD flag and NO credit-hold flag;
  `CreateSalesOrder` refuses only an inactive customer (line 66); `PlaceWebsiteOrder::identify`
  (`app/Modules/Commerce/Actions/PlaceWebsiteOrder.php:329-363`) matches the FIRST active,
  non-system customer of the business by normalised phone (a household may share a number) and
  otherwise creates a new Retail customer; an INACTIVE match is skipped, not refused; the credit
  limit is "shown, never enforced" (`CustomerExposure` docblock, C-SO-3). No sales order carries an
  idempotency key column, so `docs/79`'s `?idempotency_key=` reconcile read needs a migration and
  belongs to the write gate (P4-G0), not here.

HQ (`hq-wt/i/apps/hq`):

- `app/Erp/ErpConnector.php`: one connector per (business, token); `get()` through
  `ConnectorCallLogger::call` (`app/Commerce/Support/ConnectorCallLogger.php`: correlation id from
  `Context('correlation_id')`, HTTP status observed); `throwForStatus()` (lines 246-260) maps
  401/403 to `Unsupported`, 404 to `Refused`, 422 to `ValidationFailed`, 429 and 5xx to
  `Unavailable`, a connection error to `Unavailable` (`send()`), never to a policy refusal. ⚠
  Finding: `client()` (line 217-224) sends a FRESH uuid as `X-Correlation-Id` on every request
  while the `connector_calls` row records the gateway's `Context` correlation id, so the id the
  ERP echoes and logs is never the one HQ recorded. Stream I sends the Context id when it is one
  the ERP accepts, and the uuid only when there is none.
- `app/Erp/ErpRegistry.php` opens the connector from the `erp_api_token` credential. Tools:
  `app/Tools/Erp/PollErpTool.php` (R3, Write, `agentExecutable: false`: it replaces a snapshot
  row), `ReadErpSnapshotTool` (R1, Read, true), `RunErpReportTool` (R2, Read, true, calls the ERP
  live, `scopeFrom: 'business:args.business_slug'`, `CredentialRequirement(ErpRegistry::PROVIDER,
  'args.business_slug|business.slug')`, `idempotencyKeyFrom`, `replayWithinSeconds: 60`,
  `Unsupported` returned as `ToolResult::failedBy`, `Unavailable` rethrown). Registration:
  `config/hq.php` `tools`. Pinned lists: `tests/Feature/Gateway/ToolNamespaceTest.php` (the key
  list) and `tests/Feature/Ai/AiSafetyPostureTest.php:113-131` (every agent-executable tool is
  Read, below R3, not customer-facing; the R4/R5 list is exactly `demo.request_approval`).
- Fixtures: `tests/fixtures/erp/*.json` consumed by `tests/Feature/Erp/ErpContractFidelityTest.php`
  (captured from production with `hq:erp:inspect`). Stream I adds fixtures the ERP's own tests
  WRITE, so both sides read one file.

#### C.5.2 The endpoints (ERP side), the ability each needs, and what each returns

All under `/api/hq/v1/{business}/order-reads/`, the existing middleware, envelope, pagination and
error shape, a new controller `HqOrderReadController` whose every response body is built from an
explicit field allow-list (a constant per shape) and nothing else. No ability is added to
`READ_ABILITIES`: every endpoint needs one that already exists there.

| Endpoint | Ability | Returns |
| --- | --- | --- |
| `GET order-reads/products` (`filter[q]` name or SKU contains, `filter[sku]`, `filter[id]`, `filter[barcode]`, `filter[brand]` code, `filter[status]` active (default), inactive, all) | `inventory.view` | paginated product identity rows: `id, sku, barcode, name, type, status, is_active, orderable` (type is not Service), `unit {name, abbreviation}`, `brand {code, name}`, `category {name}`, `availability_override`, `lead_time_days` |
| `GET order-reads/skus/{sku}` | `inventory.view` | the one product a SKU names, the same row, plus `variant_model: "none"` and the sentence that the ERP has no variants; 404 when none |
| `GET order-reads/prices/{sku}` (`quantity` default 1, `store` key or `customer_id`, `on` date optional) | `sales.view` | `unit_price` (string), `basis: "selling_price"`, `mrp: null` with `mrp_note`, `quantity`, `as_of`, `priced_as` (`store`, `customer`, `nobody`), `price_source {rule_id, tier, price_group {code,name}, min_quantity, effective_from, effective_to, is_discount, discount_label, explanation}`, `promotions_applied_to_sales_orders: []` with the sentence that Buy X Get Y is a counter-sale rule; 404 `no_price_in_force` when the engine has no rule |
| `GET order-reads/availability/{sku}` (`store` key optional) | `inventory.view`; per-warehouse rows only with `inventory.reports.view`, else `warehouses: null` and `warehouses_withheld: true` | two separate facts: `stock {on_hand, unit, warehouses[{code,name,on_hand}]}` and `availability {rule: "status_based per store", product_is_active, availability_override, store: null or {key, policy, published, has_price, sellable, made_to_order, message}}`; with no store the verdict fields are null and `verdict_needs_store: true` |
| `POST order-reads/customer-eligibility` (body `phone`) | `sales.customers.view` | `phone_valid`, `phone_normalized`, `customer_exists`, `match {id, display_name, is_active}` (the row `identify` would pick) or null, `inactive_matches`, `would_create_new_customer`, `refusals` (only `inactive` exists), `fields_the_erp_does_not_have: [blocked, cod_eligible, credit_hold]`, `credit_limit_enforced: false`. No phone, email, address, credit limit or price group in the body. POST so the phone is not in an access log. |

Absent by construction from every body: `unit_cost`, `avg_unit_cost`, `averageUnitCost`,
supplier, margin, `credit_limit`, `payment_terms`, `bin`, `tin`, `email`, `address`, `note`,
journals, payroll. The ERP tests assert the absence of each by name on every endpoint, and that a
token for business A gets 403 on business B, that a missing ability is 403 and no token is 401.
`HqApiToken` tests gain a case proving every sensitive ability (`sales.orders.record`,
`sales.orders.cancel`, `sales.prices.manage`, `hrm.payroll.manage`, `accounting.journals.post`,
`inventory.stock.adjust`) is still refused.

Fixtures: the ERP tests write `tests/fixtures/hq-api/order-reads/*.json` (the exact response
bodies) and the HQ repo carries a copy under `tests/fixtures/erp/order-reads/`; each side's
fixture test states the origin (ERP test name and commit).

#### C.5.3 The tools (HQ side)

Connector methods `lookupProducts`, `lookupSku`, `readPrice`, `readAvailability`,
`checkCustomerEligibility`, each through `ConnectorCallLogger`, each failure through the existing
classes. Tools, all `ToolScope::Business`, `scopeFrom: 'business:args.business_slug'`,
`business_slug` REQUIRED in the schema, `additionalProperties: false`, `permission:
hq.tools.erp.read`, `ReadWrite::Read`, `idempotent: true` with `idempotencyKeyFrom` and
`replayWithinSeconds: 60`, outputs carrying `business`, `source: "erp"`, `as_of` (the ERP's) and
`read_live_at`:

| Key | Tier | agentExecutable | Why |
| --- | --- | --- | --- |
| `erp.lookup_product` | R2 | true | the same posture as `erp.run_report`: a live read of the ERP, nothing written, no person reached (ADR-027, reading is not acting) |
| `erp.lookup_sku` | R2 | true | same |
| `erp.read_price` | R2 | true | same; the price source is named in the output so a model cannot present a price without its rule |
| `erp.read_availability` | R2 | true | same; `sellable` and `on_hand` are two fields, as the ERP keeps them |
| `erp.check_order_eligibility` | R2 | **false** | it takes a phone number and returns whether a person exists and their display name (`DataClass::Personal`); an agent that can call it can enumerate customers by phone. It is a stage: P4-G3 decides it, after the suite in `docs/79` G measures it |

`take_order`, `quote_order`, `get_order_status`, `cancel_sales_order`: registered nowhere.

#### C.5.4 Verification planned

ERP: `tests/Feature/HqApi` (7 existing + the new file), then `tests/Feature/Sales`,
`tests/Feature/Commerce`, `tests/Feature/Inventory`, then the full suite `--parallel --processes=6`;
Pint on changed files. HQ: the new tool tests, `tests/Feature/Erp`, `tests/Feature/Gateway`,
`tests/Feature/Ai`, then the full Pest suite on `hq_test_i`, `pint --test`, `tsc --noEmit`,
`npm run build`. Numbers in C.5.5.

#### C.5.5 As built (21 September)

Branches: ERP `feature/hq-api-order-reads` commit `5bfb709` (on `origin/master` `43974c1`), HQ
`p4/erp-read-foundation` (main merged in first, then this stream). No migration on either side, no
seeder, no prompt file, no agent tool list, no flag row, grant or scheduled job. Nothing writes.

**ERP.** `routes/api.php` gains the `order-reads/` group inside the existing `{business}` +
`HqApiContext` group; `app/Http/Controllers/Api/Hq/HqOrderReadController.php` (new) answers it.

| Endpoint | Ability | As built |
| --- | --- | --- |
| `GET order-reads/products` | `inventory.view` | filters `q` (name or SKU contains), `sku`, `id`, `barcode`, `brand` (code), `status` (`active` default, `inactive`, `all`); an unknown filter key or `per_page` over 100 is a 422; ordered by SKU then id; rows are exactly `PRODUCT_FIELDS`; `meta.orderable_means` says `orderable` is only the type rule; pagination links carry the query |
| `GET order-reads/skus/{sku}` | `inventory.view` | the row plus `variant_model: "none"` and its sentence; 404 `not_found` |
| `GET order-reads/prices/{sku}` | `sales.view`, and `sales.customers.view` too when `customer_id` is given | `ResolveSellingPrice::find` asked as each caller asks it (a store as its own price group, a customer as themselves, nobody as the default tier); `unit_price` as the stored decimal string, `currency: BDT`, `basis`, `quantity`, `on`, `priced_as`, `store {key, price_group}`, `mrp: null` + `mrp_note`, `price_source {rule_id, tier, price_group {code, name, prices_include_tax}, customer_specific, min_quantity, effective_from, effective_to, is_discount, discount_label, explanation}`, `promotions_applied_to_sales_orders: []` + note, `tier_note` for nobody; 404 `no_price_in_force`, `not_found`, `unknown_store`, `unknown_customer` (the walk-in row counts as unknown); 422 for a bad `on`, a non-positive quantity, or a store and a customer together |
| `GET order-reads/availability/{sku}` | `inventory.view`; per-warehouse rows only with `inventory.reports.view` | `stock {on_hand, unit, warehouses [{code, name, on_hand}] or null, warehouses_withheld}` and `availability {decided_per_store, product_is_active, product_type_is_stocked, availability_override, verdict_needs_store, store}`; the store verdict is `{key, policy (Availability::policyFor), published, has_price (CataloguePublishing::hasPrice), sellable (CartRules::refusalFor is null), made_to_order, message (the refusal or the decision's sentence)}`; 404 `not_found`, `unknown_store` |
| `POST order-reads/customer-eligibility` | `sales.customers.view` | body `phone` (required, at most 32 characters, as the checkout validates it); `phone_matchable`, `phone_normalized`, `customer_exists` (any non-system row with the number), `match {id, display_name, is_active}` (the first active non-system row by id, which is the one `identify` picks) or null, `active_matches`, `inactive_matches`, `would_create_new_customer`, `refusal_rules_the_erp_has: ["inactive_customer"]`, `fields_the_erp_does_not_have: ["blocked", "cod_eligible", "credit_hold"]`, `credit_limit_enforced: false`, three sentences in `notes` |

Every found body carries `meta {business_id, contract: "hq-order-reads.v1", as_of}`. Differences
from the plan in C.5.2, each deliberate:

1. The product row has no `status` field: `is_active` already says it, and a second field would be
   a second opinion about the same column.
2. `phone_valid` became `phone_matchable`. The ERP validates no phone format (the checkout accepts
   any string up to 32 characters), so "valid" would have been an invented rule; "matchable" is
   exactly `PhoneIdentity::normalize` returning something.
3. `refusals` became `refusal_rules_the_erp_has`, a statement of the one rule that exists, because
   on the phone path it never fires (`identify` skips an inactive match and creates a new customer).
   `active_matches` was added, since a household may share a number.
4. A price for a named customer also needs `sales.customers.view`: a customer's agreed price is
   their agreement with the business.
5. **Correction to C.5.1**: the schema DOES hold an `mrp` column, `stock_batches.mrp`
   (`2026_08_17_150001_create_production_runs_tables.php`, C-MFG-13: label data a production run
   sets, "nothing prices off a batch"). There is still no product or price-list MRP, so `mrp` stays
   null and the note says a batch label may carry one; the batch value is not exposed.
6. `currency: "BDT"` is stated (the ERP is single-currency; `Money::ofTaka`), and the price group's
   `prices_include_tax` is passed through as stored (the column default is true; the VAT engine is
   off on all four businesses). Neither is interpreted here.

**Found on the way, not changed (ERP).** A store whose `price_group_id` is null is priced at the
default tier only: `CataloguePublishing::priceFor` passes the null straight to the engine, although
the model's docblock says null means RETAIL. The endpoint asks exactly as the storefront asks, so it
cannot disagree with the shop; whether null should mean RETAIL there is the ERP's own question. The
older `HqReadController` pagination links drop the query string; the new endpoint's links keep it.

**HQ.** `app/Erp/ErpConnector.php`: `lookupProducts` (refuses a filter key outside
`PRODUCT_FILTERS` before sending), `lookupSku`, `readPrice`, `readAvailability`,
`checkCustomerEligibility` (the phone in a POST body; never the recorded `request_ref`), all through
one `orderRead()` that returns `App\Erp\Dto\ErpOrderRead` (new): a 404 whose code the endpoint
declares is an answer (`found: false`, the ERP's code and sentence, logged as a succeeded call with
the 404 it received); every other failure keeps its class; a found body must carry `data`,
`meta.contract = hq-order-reads.v1` and this connector's own `business_id`
(`ErpContract::requireOrderReadsContract`, new). **The C.5.1 correlation finding is fixed for every
ERP call**: `client()` now sends the gateway's `Context` correlation id when the ERP would accept it
(`^[A-Za-z0-9._:-]{8,128}$`), and a fresh uuid only otherwise, so the id the ERP echoes and logs is
the one `connector_calls` records.

`app/Tools/Erp/ErpOrderReadTool.php` (new, abstract) fixes the posture once: R2, Read, Business
scope, `scopeFrom: business:args.business_slug`, `OnePerBusiness`, `hq.tools.erp.read`, the
`erp_api_token` credential, idempotent with a 60-second replay, `business_slug` required,
`additionalProperties: false`, and every output carries `business`, `source: erp`, `contract`, the
ERP's `as_of` and `read_live_at`. The five tools (`LookupProductTool`, `LookupSkuTool`,
`ReadPriceTool`, `ReadAvailabilityTool`, `CheckOrderEligibilityTool`) are registered in
`config/hq.php` `tools`. Differences from C.5.3, each deliberate:

1. `erp.read_price` takes no customer argument. The connector can ask for a named customer; the
   tool cannot, so no agent a tool is ever given to can read a customer's agreed price.
2. "No such SKU" and "no price in force" succeed with `found` / `price_in_force` false and the ERP's
   sentence; an unknown store key fails with the ERP's sentence (a caller mistake, not a fact).
3. `erp.check_order_eligibility` masks `phone` in the recorded arguments and errors, and
   `phone_normalized` and `match.display_name` in the recorded output (`DataClass::Personal`).

The stamps are unchanged (`executive.v8+18d60e98`, `support.v1+0f4a195b`): no agent's
`allowed_tools` names a new key, so the catalogue a model reads is byte for byte what it was, and
`ExecutionContextIdentityCaseTest`'s pinned digests stayed green WITHOUT re-pinning.

**The contract fixtures.** The ERP test writes the twelve bodies (with `HQ_API_WRITE_FIXTURES=1`) to
`tests/fixtures/hq-api/order-reads/` plus a `manifest.json` of their canonical digests; HQ carries
the same files under `tests/fixtures/erp/order-reads/`. Both repositories pin the contract digest
`e646da534fe9549ec5bf72a845e537bc1437072b693c2c367c6e33a022289b22`: the ERP test fails if an endpoint
stops matching its fixture or the digest moves; the HQ test fails if its copy was edited or its pin
differs, and reads every body through the real connector. The canonical form is
`json_encode(json_decode(file))` with fixed flags, so line endings cannot change a digest.

**Watched failing first.** ERP `HqOrderReadTest` (15): 14 failed before the routes existed (302 and
405 for routes that were not there); the token-command case passed first, because refusing the
sensitive abilities already worked, and it is kept as a guard. HQ `OrderReadsContractTest` (6) and
`OrderReadToolsTest` (10): 15 of 16 failed first (missing methods, classes and registrations); the
agent-refusal case passed first, because an unknown tool was refused, and it now passes because the
tool is in no agent's list.

**What the ERP cannot answer (stated, not worked around).** Variants (there are none; a SKU is one
product). A product or price-list MRP. A promotion on a sales order (Buy X Get Y is a counter-sale
rule). A blocked, cash-on-delivery or credit-hold flag, or an enforced credit limit. An
availability verdict without a store. A reconcile read by idempotency key (no sales order column;
a migration that belongs to P4-G0). HQ's own store slugs mapped to ERP store keys (HQ holds no such
mapping; `store_key` is the ERP's `stores.key`, and a wrong one is refused by name).

**Production needs, before any of this answers live (none of it done).** Deploy the ERP branch
(code only: no migration, no seeder, no new permission). The production token holds
`inventory.reports.view`, `sales.reports.view` and `crm.tickets.view` (`docs/40` I), so the new reads
answer 403 until a token holding `inventory.view` and `sales.view` exists; `sales.customers.view`
only if the eligibility check is to be used, which nothing needs yet. Because HQ keeps one
`erp_api_token` per business, the safe order is: issue a second token under another name
(`hq:api:token hq@bordeguna.com 4 --name=hq-p4 --abilities=inventory.reports.view,sales.reports.view,crm.tickets.view,inventory.view,sales.view`),
replace the HQ credential's value, `hq:erp:probe`, then `--revoke` the old `hq` token (revoking by
the default name removes only tokens named `hq:business:4`). Then deploy HQ. Each step is Joy's.

**Verification (local; GitHub Actions validation deferred until the 1 October reset).**
ERP: `tests/Feature/HqApi` 22 passed (7 existing + 15 new), then the full suite `--parallel --processes=6`: **3,979 passed, 20,827 assertions, 0 failed** (3,964 on 20 September plus these 15); Pint on the three changed files passed. The worktree has no `node_modules`, so its `public/build` was copied from the main checkout (clean at `43974c1`, the same tree); without it 553 screen tests fail on a missing Vite manifest, which is the environment, not the change. HQ: `tests/Feature/Erp` 34 passed; Gateway, Ai and Agents 376 passed; `ExecutionContextIdentityCaseTest` 3 passed, not re-pinned; the full Pest suite in one run on `hq_test_i`: **1,062 passed, 9,683 assertions, 0 failed** (1,046 on main plus 16), 301 s. `vendor/bin/pint --test` passed on the whole tree; `npx tsc --noEmit` clean; `npm run build` built. Local verification passed; GitHub Actions validation deferred until the 1 October reset.

**Decisions to revisit.**

1. `inventory.view`, `sales.view` and `sales.customers.view` are the allow-list's names but not
   seeded Spatie permissions (C.5.1). They are used as the plan named them; aligning the names is an
   ERP change of its own.
2. `erp.check_order_eligibility` is not agent-executable; P4-G3 decides it after the `docs/79` G
   suite measures it.
3. `erp.read_price` has no customer argument.
4. The connector refuses a body whose `meta.business_id` is not its own. The older endpoints are
   not checked this way.
5. The correlation id change reaches every ERP call, the old ones included: the ERP now logs the
   same id HQ records, which is the intent, and nothing on the ERP side keys on the id's shape.

### C.6 Stream J: defects found during the milestone, fixed before the candidate (branch `fix/pre-candidate-defects`)

The eight defects listed in `docs/RESUME-HERE-2026-09-21.md` section 9: found by streams E and F or
after the `docs/80` report, in none of the five briefs, and fixed here because the release candidate
is the build the Executive's A0 shadow will run. Base: `main` at `074ffae`, 1046 tests.

#### C.6.1 Today's behaviour (read from the code before any change, 21 September; paths under `apps/hq`)

**(1) Horizon is reachable before the 2FA challenge.** Every HQ page sits in
`routes/web.php:36` behind `['auth', '2fa.enabled', '2fa.verified']`; the only routes a person
reaches with the password alone are the challenge and the mandatory setup (`routes/web.php:22-33`)
and the starter kit's `routes/auth.php:32-50` (email verification, password confirmation, logout).
Horizon is in neither file. It registers its own group `horizon` =
`[SentinelMiddleware:horizon, ...config('horizon.middleware')]`
(`vendor/laravel/horizon/src/HorizonServiceProvider.php:24-27`), and `config/horizon.php:86` sets
that to `['web']`, then adds its own `Authenticate`, which asks the gate `viewHorizon` =
`can('hq.horizon.view')` (`app/Providers/HorizonServiceProvider.php:29`). The gate resolves the
session's user whether or not the challenge was passed, so a person past the password step and NOT
past the challenge opens the dashboard and its API (retry a failed job, delete a monitored tag) if
they hold the permission, and so does a person who has never set 2FA up. The route enumeration of
stream E (`tests/Feature/Security/DisabledAccountTest.php:267`) proves the account check is on every
route and says nothing about 2FA. `route:list` confirms it: apart from the challenge, the setup and
the starter-kit routes named above, the 22 `horizon/*` routes are the only routes that authenticate
somebody and carry neither 2FA middleware.

**(2) Two shared caches of kill switches.** `app/Gateway/Registry/ToolAvailability.php:25-27` and
`:106-121` cache every row of `tools` under `hq:tool_switches` for 30 seconds in the default store,
which is Redis in production and so shared by the web containers, Horizon and the scheduler. The
gateway asks it on every call (`app/Gateway/ToolGateway.php:101`). `forget()` (`:100`) is called by
`ToolSwitch.php:42` and `:60` (the screen's toggle) and `ToolRegistrySync.php:48`.
`app/Support/Features/Features.php:16` and `:40-50` cache every row of `feature_flags` under
`hq:feature_flags` for 60 seconds; the class is a process singleton
(`app/Providers/AppServiceProvider.php:47`), and `forget()` (`:34`) is called by the flag screen
(`AdminController.php:195`, `:206`), the shadow period (`ShadowPeriods.php:100`, `:221`, `:363`) and
`ShadowTick.php:61`. The flags it answers include `ai.runtime` (`ModelRuntime.php:336`, the switch
that stops every model call) and `ai.freeze` (`PolicyEngine.php:41`, `:63`, the switch that freezes
every agent action). So a switch changed by SQL, by a restore, or by any process that does not call
`forget()` keeps its old answer for up to 30 or 60 seconds, at the gateway too. The dangerous cases
are a tool turned off that stays on, `ai.runtime` turned off that stays on, and a freeze turned on
that stays off. It is the family of the business-scope cache stream E removed (C.1.1), with one
difference: here a `forget()` exists and its callers use it, so the screens were right and
everything else was not.

**(3) The executor checks separation but not the rest of "who may decide".**
`app/Models/Approval.php:172-182` `isDecidableBy()` is three rules: the decide permission, the
separation rule of stream F (`:153`), and delegation (`:181`: once delegated, only the delegate).
`app/Gateway/Approval/ApprovalExecutor.php:52` enforces the separation rule alone. The controller
enforces all three (`ApprovalController.php:223-234`, plus the route's `permission:` middleware), and
it is today the only caller, so nothing is exploitable now; a future caller of the executor that
skipped the controller (a queued decision, a command, an agent-facing approval tool) could approve
an approval delegated to somebody else, or approve without the decide permission.
`ApprovalService::approveWithEdit()` (`ApprovalService.php:180`) and `reject()` (`:228`) check
nothing about the person at all, and the successor an edit creates (`:189-196`) does not copy the
delegation, so an edit by the delegate leaves a successor that anybody with the permission may decide.

**(4) An edited payload is redacted without the tool's own paths.** `park()` stores
`$this->redactor->redact($args, $descriptor->redact)` (`ApprovalService.php:62`), the tool's declared
paths plus the leaf deny list. `approveWithEdit()` stores `$this->redactor->redact($editedArgs)`
(`:187`), the deny list only. A tool that declares a sensitive argument whose key is not on the deny
list (say `customer.phone`) has it masked in the original and stored in clear in the successor, which
is then hashed and executed as stored. No registered approval-gated tool declares such a path today,
so no stored row is affected.

**(5) The agents index sends an email.** `app/Http/Controllers/Agents/AgentController.php:78` loads
`enablingUser:id,email` and `:87` sends `enabling_user` as the email;
`resources/js/pages/agents/index.tsx:21` and `:102` print it. The run page names people by name and
user id and never by email (stream F, C.2.3), and holds that by a test.

**(6)** `resources/js/pages/agents/show.tsx` fails `prettier --check` on `main`.

**(7)** `docs/32-architecture-decisions.md:25-26` jumps from ADR-019 to ADR-047; ADR-020 to ADR-046
exist in `docs/adr/` and are not indexed.

**(8)** `phpunit.xml:24` and `:30` both declare `CACHE_STORE` = `array`.

#### C.6.2 The design (written before the code)

**(1)** `config/horizon.php` becomes `['web', '2fa.enabled', '2fa.verified']`: the same two
middlewares, in the same order, that every HQ page has. `auth` is deliberately NOT added: a guest
keeps today's 403 from Horizon's own gate (and `HorizonAccessTest` keeps holding it), while a person
signed in without 2FA set up is sent to the setup and a person who has not passed the challenge is
sent to the challenge, exactly as on `/dashboard`. `EnsureAccountIsActive` stays first (it is in the
`web` group and in the priority list), so a disabled account still ends at the login page. The route
enumeration gains a second assertion: every route that authenticates a person (by `auth` or by
Horizon's `Authenticate`) carries both 2FA middlewares, except a named list of the routes that exist
precisely for the person who has not finished 2FA (the challenge, the setup and its actions, logout,
and the starter kit's email-verification and password-confirmation routes), and the list is checked
to name only routes that exist.

**(2)** Both caches are removed, for the reasons of C.1.2: a shared store makes invalidation a
cross-process problem; a raw write or a restore is never seen by one; a process-singleton memo would
be wrong in a Horizon worker; and no evidence says the query matters (`tools` and `feature_flags`
hold a few dozen rows). `switches()` and `all()` read their table on every call. `forget()` stays on
both classes as a documented no-op, because the production callers and the tests of the other
streams call it; removing it would touch files outside this stream for no behaviour. Tests plant the
old key in a persistent `file` store, as another process would have left it, and require it to be
ignored; and a switch written by SQL is honoured by the next gateway call.

**(3)** One method on the model, `Approval::decisionRefusalReason(User)`, answers null or the reason:
the separation rule first (its sentence unchanged), then the decide permission, then delegation.
`isDecidableBy()` becomes "that reason is null", so the screen is what it was. The executor calls it
before the guard and the claim (a refusal leaves the approval pending and undecided),
`approveWithEdit()` and `reject()` call it and throw before they write anything, and the successor of
an edit copies `delegated_to_user_id`, `delegated_by_user_id` and `delegated_at`, so a delegation
survives the edit. The controller's `assertDecidable` reads the same method, so its 403 says which
rule refused.

**(4)** `ApprovalService` receives the `ToolRegistry`, and `approveWithEdit()` redacts with the stored
tool's descriptor paths, exactly as `park()` does; a tool no longer registered is refused (the
controller already refuses it first). `CanonicalPayload`, the hash, the guard and the claim are
untouched: the hash is still taken over what is stored.

**(5)** `enabling_user` becomes `{user_id, name}` (or null), printed the way the run page prints a
person: "Name (user #id)". A test holds that the page's props contain no email.

**(6)** Prettier on that one file. **(7)** Twenty-seven rows from each ADR's own title and status
line. **(8)** The second declaration goes.

Nothing here touches a prompt, TaskGrounding, TaskScope, a detector, a policy rule, a migration, a
flag row, a grant or a scheduled job.

#### C.6.3 As built (21 September)

No migration. No prompt file, TaskGrounding, TaskScope, detector or policy rule touched. Nothing
switched on; no flag row, grant or scheduled job created. The agent stamps are unchanged
(`executive.v8+18d60e98`, `support.v1+0f4a195b`) and the `ExecutionContextIdentityCaseTest`
digests stayed green without re-pinning.

**(1) Horizon.** `config/horizon.php:90` is `['web', '2fa.enabled', '2fa.verified']`. Measured on
the new tree: every one of the 22 `horizon/*` routes carries the account check, then both 2FA
checks, then Horizon's gate, in that order. A person past the password step is sent to
`two-factor.challenge` from the page, the API and a retry; a person who never set 2FA up is sent to
`two-factor.setup`; a verified person with the permission opens it; a guest still gets 403.
`tests/Feature/Security/TwoFactorEveryRouteTest.php` holds that every route that authenticates a
person (by `auth` or by Horizon's gate) carries both checks, except ten URIs that exist to finish 2FA
(the challenge, the setup and its actions, logout, email verification, password confirmation), and
that each of the ten still exists. The exemption is by URI, not by name, because `POST
confirm-password` has no name.

**(2) Kill switches.** `ToolAvailability::switches()` (`app/Gateway/Registry/ToolAvailability.php:106`)
and `Features::all()` (`app/Support/Features/Features.php:43`) read their table on every call; the
keys, the TTLs and the `Cache` import are gone. `forget()` stays on both (`:103`, `:40`) as a
documented no-op, and its callers were not touched. A tool switched off by SQL is refused at the
very next gateway call (`unavailable`, "disabled at runtime"), a tool switched back on is offered at
the next call, `ai.freeze` set by SQL denies the next call, `ai.runtime` cleared by SQL reads off at
once, and a stale `hq:tool_switches` / `hq:feature_flags` planted in a persistent `file` store is
ignored.

**(3) Who may decide.** `Approval::decisionRefusalReason()` (`app/Models/Approval.php:176`) answers
the separation rule (its sentence unchanged), then "You do not hold the permission to decide
approvals.", then "This approval is delegated to somebody else."; `isDecidableBy()` (`:191`) is that
reason being null. It is read by the executor before the guard and the claim
(`ApprovalExecutor.php:53`), by `approveWithEdit()` (`ApprovalService.php:193`) and `reject()`
(`:253`) before they write, and by the controller's `assertDecidable` (`ApprovalController.php:228`),
whose 403 now names the rule. The successor of an edit copies the three delegation columns (`:214`).
`cancel` and `delegate` are unchanged.

**(4) The edit's redaction.** `ApprovalService` takes the `ToolRegistry` (`:41`); `approveWithEdit()`
redacts with the stored tool's descriptor paths (`:199`) and refuses a tool no longer registered.
The hash is still `CanonicalPayload::hash()` over what is stored, and a test holds it.

**(5)** `AgentController.php:88` sends `enabling_user` as `{user_id, name}` or null; the page prints
"Name (user #id)" (`resources/js/pages/agents/index.tsx:21`, `:102`). **(6)** `show.tsx` formatted
by Prettier, nothing else. **(7)** `docs/32` has the rows ADR-020 to ADR-046. **(8)** `phpunit.xml`
declares `CACHE_STORE` once.

**Tests watched failing on the unmodified code, then passing.**

- `tests/Feature/Security/TwoFactorEveryRouteTest.php` (4): 3 failed first (the unverified person
  and the person without 2FA both got 200 from Horizon; the enumeration stopped at
  `horizon/api/stats`). The fourth (a verified person opens it, a guest gets 403) passed first and
  is the behaviour kept.
- `tests/Feature/Security/KillSwitchFreshnessTest.php` (6): 5 failed first, each on the stale answer
  (the call `succeeded`, the flag read on). The sixth (`forget()` callable and harmless) passed first.
- `tests/Feature/Gateway/ApprovalDecisionRuleTest.php` (9) with the test tool
  `tests/Support/Tools/RedactingGatedTool.php` (R4, declares `customer_phone`, which the deny list does
  not know): 7 failed first (the executor executed after a delegation and without the permission; an
  edit and a rejection by somebody who may not decide went through; the successor had no delegation;
  the edited phone number was stored in clear; the unregistered tool was edited). The two that
  passed first keep a delegate and the undelegated owner deciding as before.
- `tests/Feature/Agents/AgentsIndexPeopleTest.php` (1): failed first on the email it received.

**Verification (local; GitHub Actions validation deferred until the 1 October reset).** Full Pest suite in one run on Postgres `hq_test_e`: **1066 passed, 9771 assertions, 0 failed**, 315 s (base 1046, plus 20: 4 two-factor, 6 kill switch, 9 approval decision rule, 1 agents index). Along the way: Gateway, Foundation, Security, Agents and Ai folders 480 passed after item 2; the Gateway folder 198 passed after items 3 and 4. `vendor/bin/pint --test` passed. `npx tsc --noEmit` clean. `npm run build` built. `npx prettier --check` passes on `agents/show.tsx` and `agents/index.tsx`. That 1066 is the number the next merge must not reduce.

**Decisions the owner may want to revisit.**

1. Horizon does not get `auth`, so a guest still sees Horizon's 403 rather than the login page. The
   alternative (redirect a guest to login) changes a tested behaviour for no security gain.
2. `forget()` is kept as a no-op rather than removed with its callers. If a cache is ever brought
   back it must be invalidated across processes, and these calls mark the places that write.
3. A rejection now needs the same right as an approval. Before, the service would reject for anybody
   who reached it; only the controller stopped them.
4. An edit made by the delegate keeps the delegation on the successor, so the original approver
   still cannot decide the edited proposal. Undelegating is not a feature today.
5. Every call to `Features::enabled()` and to the gateway's availability check is one small query.
   A page that asks for several flags asks the table several times. No measurement says this matters;
   if one ever does, the place for a memo is that one request, never the shared class.

**Found on the way, not fixed (outside this stream).** None beyond what C.6.1 lists.

### C.7 Stream K: OpenAI as the model provider (owner decision, 21 September) (branch `ai/openai-provider`)

#### C.7.1 The decision

Joy, 21 September, verbatim: "i want to skip the antropic api for now, interested to chatgpt api to use,
guide me, i will add for you. i will use antropic after, i will let you know."

So: HQ gains an OpenAI driver, OpenAI serves the model calls until Joy says otherwise, and Anthropic stays
registered and untouched, so that returning to it is an operator step and not a rebuild. This is an owner
decision about the provider, not a change to any prompt, policy or safety rule. Its consequence for the
gate is recorded in C.7.4 point 14: **Gate 11 attempt 2 would be the first gate to measure an OpenAI
model** (gates 1 to 11 attempt 1 all measured Claude), and returning to Anthropic later needs a fresh gate.

#### C.7.2 Today's behaviour (read from the code before any change, 21 September; paths under `apps/hq`)

- **The seam exists.** `app/Ai/Providers/ModelProvider.php` is the one interface (`key`, `available`,
  `complete`, `credentialPair`); ADR-019 says a second provider is "a driver class plus rows, not a
  refactor". `ProviderRegistry` is filled from `config/hq.php` `ai.providers`, which lists
  `AnthropicProvider` alone.
- **`AnthropicProvider::complete()`** reads the key only through `CredentialVault::secret()` inside the
  scope `ModelRuntime::call()` opens, sends the Messages API body, maps a schema to `output_config.format`,
  puts a `cache_control` breakpoint on the system block, and maps 429 and 5xx to `ProviderUnavailable`,
  every other 4xx to `ProviderRefused`. Usage: cache reads and writes are added to `input_tokens`, so both
  are subsets of `inputTokens` (the contract `ModelResponse` and `CostCalculator` depend on).
- **`ModelRouter`** picks, per tier, the active `model_tiers` row with the best priority, then the
  cheaper input price, whose provider has a driver, a credential and a closed breaker.
  `ModelRuntime::run()` then tries up to three models: a `ProviderUnavailable` falls through to the next
  candidate, **a different provider first** (`ModelRouter::candidates()`, docs/48 J.2); a
  `ProviderRefused` stops at once. Anthropic's credit refusal is a 400, so it is refused and never falls
  through. ⚠ So today two providers with active rows would share traffic: by priority and price for the
  first choice, and on any 429 or 5xx for the fallback. Nothing makes "one provider per measurement" true
  except that only one provider exists.
- **`StructuredOutput`** checks the reply with `ArgumentValidator` and allows one repair.
  `AgentResponseSchema` (every agent turn) keeps to a subset: object, `properties`, `required`,
  `additionalProperties: false`, and per property only `type` and `enum`; its three arrays (`facts`,
  `inferences`, `tool_calls`) carry no `items`, deliberately. `JobSpecSchema` (the NL scheduler) keeps to
  the same subset and has no arrays.
- **`CostCalculator`** prices fresh input, output, cache reads and cache writes from the row, and falls
  back to the input price when a cache price is null.
- **`ModelTierSeeder`** upserts four Anthropic rows by (provider, model id, tier) and sets `is_active` only
  on create, so it never re-activates a row an operator switched off (`ModelTierSeederTest`).
- **Readiness.** `ProviderCheck::run()` reads the Anthropic credential by name, sends
  `AnthropicCheck::send()` and classifies the status (`classify()`: 401 invalid, 400 naming the credit
  balance blocked by credit, 403 unauthorized, 429 and 5xx unavailable). ⚠ `AnthropicCheck` picks
  `AiModel::where('tier','low')->where('is_active',true)->orderBy('priority')` with **no provider filter**:
  a second provider's low row at the same priority could send an OpenAI model id to Anthropic.
  `CapabilityReadiness::inventory()` always adds one `hq` row named `anthropic_api_key`, and
  `verifyModel()` always runs the Anthropic check; `hq:credentials:health --verify-model` goes through it.
  The gate toggle's `bypass` action (`deploy/gate/toggle.php`) prints `ProviderCheck::run('gate_bypass')`,
  and `deploy/gate/run.sh` stops unless that line contains `"http":200`.
- **Guards.** `AiSafetyPostureTest`: no model id literal in `app/` outside an allow-list naming
  `AnthropicProvider.php`; no `api.anthropic.com` outside `app/Ai/Providers/`; the driver reads the key only
  through the vault. `hq:ai:probe` is dry by default and sends no schema.
- **Shadow.** `ShadowPeriods::refusals()` knows nothing of the model provider, so a period could open
  while two providers serve, and nothing stops the provider changing under an open period.

#### C.7.3 What OpenAI's own pages say (read on developers.openai.com on 21 September, not recalled)

- **Models** (the three model pages): `gpt-5.6-luna`, `gpt-5.6-terra`, `gpt-5.6-sol`, each with a 1,050,000
  context window, 922,000 maximum input tokens, 128,000 maximum output tokens, structured outputs, the
  Responses API, reasoning tokens, and `reasoning.effort` none, low, medium (the default), high, xhigh and
  max. Terra "roughly corresponds to the mini model tier used in earlier GPT-5 families"; Luna to the nano
  tier; Sol to the unsuffixed tier.
- **Prices** (pricing page, standard tier, short context, per 1M tokens): Luna $0.20 input, $0.02 cached,
  $0.25 cache writes, $1.20 output; Terra $2.00, $0.20, $2.50, $12.00; Sol $4.00, $0.40, $5.00, $20.00, and
  Sol's "promotional pricing is available at least through November 21, 2026" (the model page: a 20% cut in
  input and 33% in output, so normally $5 and $30). "Prompts with >272K input tokens are priced at 2x input
  and 1.5x output for the full request." ⚠ **Cache writes are billed**: "For GPT-5.6 and later, cache writes
  cost 1.25x the standard, uncached input-token rate", and "input tokens use the uncached-input,
  cached-input, or cache-write rate" (so both are subsets of `input_tokens`, as HQ's contract already
  assumes). The brief said there is no cache-write premium; the provider's page says otherwise, so the rows
  carry the page's cache-write price.
- **Responses API** (`POST /v1/responses`): `instructions`, `input` (messages with role user, assistant,
  developer), `max_output_tokens` ("including visible output tokens and reasoning tokens"), `store`
  ("Defaults to true when omitted"), `reasoning.effort`, `text.format` (`json_schema` with `name`, `schema`,
  `strict`), `prompt_cache_options.mode` implicit or explicit. The reply: `status` completed, failed,
  incomplete and others; `incomplete_details.reason` `max_output_tokens` or `content_filter`; `output[]`
  message items whose content is `output_text` or `refusal` parts; `usage.input_tokens`,
  `usage.input_tokens_details.cached_tokens` and `.cache_write_tokens`, `usage.output_tokens` with
  `output_tokens_details.reasoning_tokens`. `output_text` is "SDK-only".
- **Caching** (GPT-5.6): implicit mode places a breakpoint at the end of the latest eligible message, which
  for HQ is the user message, so every call would write its whole prompt at 1.25x and a different case would
  not reuse it. "Top-level `instructions` cannot contain an explicit breakpoint. To mark reusable developer
  instructions, place them in an `input_text` block inside a developer message", with
  `prompt_cache_breakpoint: {"mode": "explicit"}` and `prompt_cache_options: {"mode": "explicit"}`. The
  minimum cacheable prefix is 1,024 tokens.
- **Structured outputs, strict** (the guide's "Supported schemas"): the root is an object and not `anyOf`;
  every object sets `additionalProperties: false`; "all fields must be `required`"; the supported keywords
  are listed (for strings `pattern` and `format`; for numbers `multipleOf`, `maximum`, `exclusiveMaximum`,
  `minimum`, `exclusiveMinimum`; for arrays `minItems`, `maxItems`; `enum`, `anyOf`, definitions and
  recursion); `allOf`, `not`, `if`/`then`/`else` and others are not; at most 5000 properties, 10 levels of
  nesting and 1000 enum values; and "If you turn on Structured Outputs by supplying `strict: true` and call
  the API with an unsupported JSON Schema, you will receive an error."
- **Errors** (the error-codes guide): 401 for a wrong key, a missing organisation, or "IP not authorized" (a
  project allow-list, not the key); 403 for an unsupported country or region; **429 is shared by throttling
  and billing**: `credit_balance_exhausted`, `organization_spend_limit_exceeded`,
  `project_spend_limit_exceeded`, `organization_usage_limit_exceeded`, and "the broader `error.type` can
  still be `insufficient_quota`"; "Retrying billing, spend, or quota errors won't restore API access"; 500
  server error; 503 `server_is_overloaded`.

#### C.7.4 The design (written before the code)

1. **`App\Ai\Providers\OpenAiProvider`** (key `openai`, credential `openai_api_key` / `hq`). The Responses
   API with `store: false` (nothing kept at OpenAI for 30 days), the model id off the row, the key as a
   Bearer header read only through the vault inside the runtime's scope, never in a body, URL, log or
   error. `max_output_tokens` from the request, `reasoning.effort` from config (point 3).
2. **Structured output.** A `json_schema` format named `hq_reply`. `strict: true` only when
   `OpenAiStrictSchema::compatible()` proves the schema is inside the documented strict subset (an
   allow-list, so an unknown keyword is never assumed supported); otherwise `strict: false`, which sends the
   schema to the model without the guarantee. **Measured on HQ's two schemas:** `JobSpecSchema` is
   compatible, so the NL scheduler gets `strict: true`; `AgentResponseSchema` is NOT (its three arrays have
   no `items`, and `tool_calls[].args` is an open object that strict mode cannot express), so every agent
   turn gets `strict: false`. The agent schema is not changed to suit OpenAI: it is the contract every
   earlier gate measured, and AgentRuntimeTest pins its subset. HQ's own `ArgumentValidator` plus one repair
   stays the check on every reply, exactly as for Anthropic. ⚠ That OpenAI accepts a non-strict
   `json_schema` holding array schemas without `items` is documented only by omission, so it is proved
   before any gate by `hq:ai:probe --live --schema` (point 12), which sends the agent schema itself.
3. **Reasoning effort** is a setting, `hq.ai.openai.reasoning_effort` (env `HQ_OPENAI_REASONING_EFFORT`),
   default **`low`**. It is part of what a gate measures, so it is fixed before the gate and printed by the
   dry probe into the gate log. Why `low`: the default `medium` spends reasoning tokens out of the same
   `max_output_tokens` (OpenAI suggests reserving 25,000 when starting) and HQ's agent turn asks for 8,192,
   so medium risks the truncation that failed gate 3 (ADR-038); `none` gives a mini-tier model no
   deliberation at all on exactly the judgement calls the safety corpus is made of. A decision to revisit;
   never tuned after a gate result.
4. **Caching**, the same choice as the Anthropic driver makes: with `cacheSystem` the system prompt goes as
   a developer message whose one `input_text` block carries an explicit breakpoint, and
   `prompt_cache_options.mode` is `explicit`, so only the stable prefix is written (once, at 1.25x) and read
   by later calls at 0.1x; the changing messages after it are billed at the plain rate with no write charge.
   Without `cacheSystem` the system prompt goes as `instructions` with `mode: explicit` and no breakpoint,
   which the page says "does not use prompt caching or create cache writes".
5. **Errors.** A connection failure, a 5xx and a throttling 429 are `ProviderUnavailable`. A billing 429
   (any of the four codes, or type `insufficient_quota`) is `ProviderRefused`: retrying cannot help, it is
   not an outage, the breaker is not touched and nothing falls through, the same as Anthropic's credit 400.
   Every other 4xx is `ProviderRefused`. A 200 with `status: failed` is `ProviderUnavailable` for
   `server_error` and `rate_limit_exceeded`, refused otherwise. The error detail is trimmed and never
   carries the header.
6. **Stop reason and usage.** `completed` is `end_turn`; `incomplete` with `max_output_tokens` is
   `max_tokens`, so ADR-038's single raise works unchanged; `content_filter`, and a reply that is only a
   `refusal` part, are recorded as `content_filter` and `refusal` (the schema check then treats the empty
   text as malformed, which is the truthful record). `inputTokens` = `input_tokens`; `cachedTokens` and
   `cacheWriteTokens` from `input_tokens_details` (subsets); `outputTokens` = `output_tokens`, reasoning
   included, because it is billed as output; `rawUsage` kept whole.
7. **Rows.** `ModelTierSeeder` gains OpenAI rows with the page's prices and `pricing_verified_on`
   2026-09-21: LOW Luna, MEDIUM Terra, HIGH Sol, and Sol again at MEDIUM with priority 90, the same
   degrade-upward fallback the Anthropic rows have with Opus. `context_window` is registered as **272,000**,
   the short-context price band: above it OpenAI bills the whole request at 2x input and 1.5x output, which
   `CostCalculator` does not model, so HQ refuses a larger prompt rather than under-report it (the notes say
   the model itself takes 922,000). No embedding model. **A new row is created active only when its provider
   already serves** (or, when nothing serves yet, only for Anthropic, the provider every existing install
   runs on): seeding OpenAI on production switches nothing on, and a fresh install behaves exactly as today.
   Existing rows are never touched on `is_active`, as before.
8. **`App\Ai\ServingProvider`**: which providers have an active row at a chat tier. One is `single` (that
   provider serves), none is `none`, more than one is `mixed`. Everything that must know "the provider a
   measurement runs on" reads this.
9. **The lever, `hq:ai:provider`.** Without an argument it prints, per registered provider, its credential,
   its readiness state and its rows per tier, and which provider serves. `hq:ai:provider openai` is a
   **dry run** listing every row it would switch on and off. `--apply --by=<email> --reason="..."` does it
   in one transaction: every chat-tier row of the chosen provider on, every chat-tier row of every other
   provider off, and one audit row `ai.provider_selected` under the named person (human actor) with the
   before and after row sets. It refuses, changing nothing, when: `--by` is missing or names a user who does
   not exist or is disabled (every environment); the reason is empty; the provider has no driver; it has no
   stored credential; it lacks a row at any of the low, medium and high tiers; or **a shadow period is
   open** (changing the model under a shadow would make its evidence about two models). Selecting the
   provider that already serves alone changes and audits nothing. The lever switches on ALL of the chosen
   provider's chat rows, including one an operator had switched off; the dry run names each row so that is
   seen before it happens (a decision to revisit). It touches no `ai.runtime`, agent, grant or flag.
10. **Readiness follows the serving provider.** `OpenAiCheck` beside `AnthropicCheck` (both in the driver
    layer): one call to the provider's low row (an active one first, else any row of that provider, so a
    key can be verified BEFORE the lever moves traffic to it), 16 output tokens, no reasoning,
    `store: false`. `AnthropicCheck` gains the provider filter it lacked. `ProviderCheck::run($source,
    $provider = null)` checks the named provider, else the serving one, else Anthropic; when two serve it
    refuses without a call (`"http":null`, the reason in words), so the gate's bypass step stops before
    anything is enabled. `classify()` learns the billing 429 (blocked by credit, never invalid) and the 401
    "IP not authorized" (unauthorized, the key not condemned); Anthropic's rules are unchanged. The JSON
    still opens with `"http"` and now ends with the provider it checked. `CapabilityReadiness` gives the
    `hq` row for each serving provider (Anthropic when none serves, so today's table is the same), and
    `verifyModel()` checks that row's provider; the alert fingerprint therefore names the provider that
    serves. `run.sh` wording loses "api.anthropic.com".
11. **Shadow.** `ShadowPeriods::refusals()` adds one refusal: exactly one model provider must serve. With
    the lever's own refusal, a period's evidence is about one provider from open to close.
12. **Probe.** `hq:ai:probe` (dry) prints the serving provider and, for OpenAI, the reasoning effort.
    `--schema` (with `--live`) sends one small agent-shaped request with `AgentResponseSchema` and 512
    output tokens, so the structured path of point 2 is proven with the real provider before a gate.
13. **Not changed:** no prompt file, TaskGrounding, TaskScope, detector, gateway policy, agent schema,
    `ai.runtime`, grant, flag row or scheduled job; no migration; the stamps `executive.v8+18d60e98` and
    `support.v1+0f4a195b`; the identity-case digests; ADR-038's recovery; the Anthropic driver's wire shape.
14. **Gate consequences, for the report.** After the release: seed the rows, Joy stores the key,
    `hq:credentials:health --verify-model` (or the gate's bypass) proves it, `hq:ai:provider openai --apply
    --by=<joy> --reason=...` moves traffic, and `hq:ai:probe --live --schema` proves the structured path.
    Gate 11 attempt 2 then measures `gpt-5.6-terra` (the Executive's turns route as `operator_question`, the
    medium tier) at reasoning effort `low`: the first OpenAI measurement. A later return to Anthropic is the
    lever the other way, and needs its own complete gate before any shadow runs on it.

Tests are written first and watched failing on the unmodified code: the driver's wire contract (Bearer
header only, body, developer message and breakpoint, strict and non-strict schema, `store: false`, effort),
the reply mapping (text, usage subsets, stop reasons, refusal), the error classes (throttle, billing,
server, failed status), the strict-subset checker on both real schemas, the seeder (rows, prices, inactive
on create, never re-activated), the serving provider, the lever (dry run, every refusal, the switch, the
audit under the person, the no-op), routing after the switch (no fallback to Anthropic), the readiness row
and the bypass for OpenAI (a billing 429 is blocked by credit, not invalid), the Anthropic check's provider
filter, the shadow refusal, and the probe's `--schema`.

#### C.7.5 As built (21 September)

No migration. No prompt file, TaskGrounding, TaskScope, detector, gateway policy, agent schema or ADR-038
recovery touched; `ai.runtime`, agents, grants and flag rows untouched; no scheduled job. No real call was
made to any provider: every wire shape below is held against OpenAI's documentation, not against a live
answer.

**The driver.** `app/Ai/Providers/OpenAiProvider.php`: `complete()` (`:96`) sends `POST /v1/responses`
with the key from `CredentialVault::secret()` as a Bearer header only. `body()` (`:155`) carries the row's
model id, `max_output_tokens`, `store: false` (`:165`), `reasoning.effort` from `reasoningEffort()` (`:82`,
config `hq.ai.openai.reasoning_effort`, default `low`), `prompt_cache_options.mode: explicit`, and the
system prompt as a developer message whose `input_text` block carries the one explicit breakpoint (`:180`),
or as `instructions` when caching is off. A schema becomes `text.format` `json_schema` named `hq_reply`
with `strict` = `OpenAiStrictSchema::compatible()` (`:194`). `failure()` (`:201`) and `isBillingBlock()`
(`:90`): a 429 carrying `credit_balance_exhausted`, either spend-limit code, the usage-limit code, or type
`insufficient_quota` is `ProviderRefused` naming it a billing block; any other 429 and every 5xx are
`ProviderUnavailable`; every other 4xx is refused. A 200 with `status: failed` is an outage for
`server_error` and `rate_limit_exceeded`, refused otherwise. `stopReason()` (`:221`): `max_output_tokens`
is `max_tokens`, `content_filter` and a refusal-only reply are recorded as such. Usage: `input_tokens` as
given (cached and written are already inside it), `cached_tokens` and `cache_write_tokens` from
`input_tokens_details`, `output_tokens` including reasoning.

**The strict subset.** `app/Ai/Providers/OpenAiStrictSchema.php` (`compatible()` `:49`) is an allow-list
from the guide's "Supported schemas": object root and no root `anyOf`; per type only the listed keywords
(so `minLength`, `maxLength`, `allOf`, `not`, `if` and any unlisted keyword make a schema non-strict);
`additionalProperties: false` and every property required on every object (`:131`); an array must describe
its `items`; the listed string formats only; at most 10 levels, 5000 properties, 1000 enum values.
**Finding, on HQ's two real schemas:** `JobSpecSchema` is strict-compatible; `AgentResponseSchema` is not
(`facts`, `inferences` and `tool_calls` have no `items`, and a tool call's `args` is an open object that
strict mode cannot express). Every agent turn is therefore sent `strict: false` and stays checked by
`ArgumentValidator` with one repair, exactly as for Anthropic. That OpenAI accepts a non-strict schema whose
arrays lack `items` is not stated in its documentation either way; the gate runner now proves it with the
real provider before any case (below).

**One serving provider.** `app/Ai/ServingProvider.php` (`active()` `:30`, `current()` `:46`, `state()`
`:53`, `describe()` `:63`). `database/seeders/ModelTierSeeder.php`: four OpenAI rows with the pricing
page's prices of 21 September, cache writes included (Luna 0.20 / 0.02 / 0.25 / 1.20, Terra 2.00 / 0.20 /
2.50 / 12.00, Sol 4.00 / 0.40 / 5.00 / 20.00, Sol again at medium with priority 90), `context_window`
272,000, `max_output_tokens` 128,000, `pricing_verified_on` 2026-09-21; a new row is active only when its
provider already serves (`:66`, `:188`). The Anthropic rows are unchanged.

**The lever.** `app/Ai/ProviderSelection.php`: `refusals()` (`:47`: no driver, no stored key, a chat tier
with no row, an open shadow period), `personRefusals()` (`:75`: `--by` missing, unknown or disabled, in
every environment; no reason), `plan()` (`:102`), `select()` (`:119`: one transaction with the rows locked,
every chat-tier row of the chosen provider on and every other provider's off, one `ai.provider_selected`
audit row under the named person with the before and after row sets; a no-op when it already serves alone).
`app/Console/Commands/AiProvider.php` is `hq:ai:provider`: the state without an argument, a dry run naming
every row it would switch, `--apply --by --reason` to switch. It touches no switch, agent or grant.

**Readiness.** `app/Ai/Providers/ReadinessCall.php` (the interface), `AnthropicCheck` (now its OWN low row,
active first: before, it took the best active low row of any provider), `OpenAiCheck` (Luna, 16 output
tokens, `reasoning.effort: none`, `store: false`). `app/Ai/ProviderCheck.php`: `run($source, $provider)`
(`:60`) checks the named provider, else the serving one, else Anthropic; when two serve it checks nothing
(`:62`) and says why; the JSON still opens with `"http"` and now ends with `"provider"`. `classify()`
(`:120`) adds the billing 429 (blocked by credit) and the 401 "IP not authorized" (unauthorized), both
never condemning the key; Anthropic's rules are unchanged. `CapabilityReadiness` gives one `hq` row per
serving provider, Anthropic when none serves (`:86`), and `verifyModel()` checks that row's provider
(`:146`). `deploy/gate/toggle.php` and `run.sh` wording follow.

**Shadow and probe.** `ShadowPeriods::refusals()` (`:115`) refuses unless exactly one provider serves.
`hq:ai:probe` prints the serving provider (or "MORE THAN ONE provider serves") and OpenAI's reasoning
effort; `--live --schema` asks for one reply under `AgentResponseSchema` with 512 output tokens and says
whether it validated; `--schema` without `--live` is refused. `deploy/gate/run.sh` step 4c runs
`hq:ai:probe --live --schema --task=operator_question` after the runtime is on and **stops before any case**
when it fails, so a provider that cannot handle the agent schema leaves no measurement rather than a gate
of provider errors; the EXIT trap turns everything off as before.

**Docs.** ADR-049 (and an amendment on ADR-019), `docs/32` line 049, `docs/runbooks/openai-billing.md`
(forwardable; Joy's account, credit, limit, project and key, and where the key goes), the runbooks index,
the OpenAI row in `docs/70` section M, and the by-hand steps after the deploy in `deploy/README.md` ("NOT
YET APPLIED TO PRODUCTION: OpenAI as the model provider").

**Deviations from the brief, with the evidence.** (1) The brief said there is no cache-write premium; the
pricing page lists "cache writes" at 1.25x input for all three models and the prompt-caching guide says so
in words, so the rows carry $0.25, $2.50 and $5.00. (2) `context_window` is 272,000, not the model's
1,050,000, because above 272K the whole request bills at 2x input and 1.5x output and `CostCalculator`
does not model that. (3) Sol is registered twice (high, and medium at priority 90), mirroring Opus. (4) The
readiness call uses 16 output tokens, not 8, and no reasoning.

**Watched failing first.** The 75 tests written before the code all failed on the unmodified tree (one run,
75 failed and 37 passed, the 37 being ShadowPeriodTest's existing tests): `OpenAiProviderTest` 40 (with
datasets), `ProviderSelectionTest` 18, `OpenAiReadinessTest` 15, and the two new `ShadowPeriodTest` dataset
rows. One more (`it keeps the key out of a refusal too`) was split out of a test whose second `Http::fake()`
could never take effect, the trap `AiSafetyPostureTest` already records. `AiSafetyPostureTest`'s two
guards (model ids only in the driver layer; endpoints only there) were widened to name OpenAI and passed
before and after, as guards.

**Verification (local; GitHub Actions validation deferred until the 1 October reset).** On the branch
before main moved: full Pest in one run on `hq_test_f`, **1122 passed, 9624 assertions**, 240.6 s (base 1046,
plus 76). Main then gained streams G, H, I and J; merged into the branch (the only conflicts were the
`docs/32` index and the `docs/81` table and record): **1189 passed, 10692 assertions**, 271.7 s.
`vendor/bin/pint --test` passed; `npx tsc --noEmit` clean; `npm run build` built. `bash -n deploy/gate/run.sh`
clean; the deploy-safety suite does not read the gate runner and was not re-run. The identity-case digests
stayed green without re-pinning; the stamps `executive.v8+18d60e98` and `support.v1+0f4a195b` are unchanged
(no prompt file moved).

**Cost of a gate on OpenAI, estimated.** The recorded Anthropic gates averaged about 10,300 input and 1,430
output tokens per call and about $2 per gate (8.6M in, 1.2M out, $19.38 for 837 Sonnet calls; gates 9 to 11
cost $5.64). Terra prices input, cache reads and cache writes the same as Sonnet 5 and output at $12 against
$10, and adds reasoning tokens at effort `low`; with 20 to 50% more output, a gate is about **$2.50 to $3**,
say $2 to $4. Nothing measured yet: the first gate's `ai_runs` will say.

**Decisions to revisit.**

1. Reasoning effort `low` for every OpenAI call. Measured by the first gate, never tuned after its result.
2. The Executive's turns run on Terra, which OpenAI describes as the mini tier of the family; the earlier
   gates measured Sonnet 5. Moving the medium tier to Sol is a row change through the same lever's rules,
   and a new measurement.
3. The lever switches on ALL of the chosen provider's chat rows, including one an operator had switched
   off; the dry run names them. A per-row memory of "parked by the lever" would need a column.
4. The cross-provider fallback of ADR-019 stays; with one provider's rows active it cannot fire.
5. `ai_runs` does not record the reasoning effort a call ran at; the gate log carries it from the probe.
6. The agent schema stays non-strict on OpenAI. Giving its arrays `items` would let OpenAI enforce more of
   it, but it changes the contract every earlier gate measured.

**Found on the way, not fixed.** Anthropic's own "monthly spend limit reached" answer is not one of the two
phrases `classify()` reads for Anthropic (it knows "credit balance" and "purchase credits"), so it would
read as `connector_error`; its exact wording was not checked here. The provider-blocked runbook still speaks
of Anthropic only.


### C.8 Stream L: the scheduler's zombie leak and the health readers that could not read a 503 (branch `fix/scheduler-reaper-and-health-readers`)

Found on 21 September by the read-only preflight of the candidate named in section D, before any
deploy. The preflight FAILed on production's health, and following that FAIL found a production
outage that had been silent for three days.

#### C.8.1 Today's behaviour, and what production showed (21 September, read-only unless stated)

**The outage.** `/api/health` answered HTTP 503 with `"scheduler":{"status":"fail","detail":"no tick
recorded"}`. The scheduler container was up (5 days) and its log showed `schedule:work` starting
commands every minute, yet `Cache::get('hq:scheduler:last_tick')` was null in both the app and the
scheduler containers, and a plain `sh` inside the scheduler container failed with `can't fork:
Resource temporarily unavailable`. `docker stats` counted **9,482 processes** in it against a cgroup
`pids.max` of **9,483** (`ulimit -u` unlimited). The last `job_runs` row was 17 September 22:05 UTC
(five runs a day on every day before: the LaLaDia and Bionic reconciliations, the fact integrity check,
the two ERP snapshots). So from about 18 September 11:37 UTC (the monitor saw the heartbeat stale at 11:40) nothing scheduled ran: not the minute tick
that dispatches HQ's own jobs, not event delivery, not the approval sweep.

**Why.** `routes/console.php` runs most of HQ's commands with `->runInBackground()`. Laravel starts
those as `sh -c "(php artisan <cmd> ; php artisan schedule:finish ...) > /dev/null 2>&1 &"`: the `sh`
exits at once and its background subshell is re-parented to PID 1 of the container. PID 1 is
`php artisan schedule:work` (the entrypoint `exec`s it; the service has no `init`), and it never calls
`wait()` for children it did not start, so every finished background command stays as a zombie.
Measured after the restart below (the container's own `StartedAt` is 05:06:09 UTC): 3 processes at
start, 32 at 05:20, 49 at 05:28 and 52 at 05:29, about 2.1 a minute or 3,000 a day, which reaches 9,483
in about 72 to 75 hours. The first failure fits: the container was started on 15 September 11:45 UTC,
and the monitor saw health fail (a heartbeat older than 180 s) at 11:40 UTC on 18 September, 72 hours
later. The daily jobs place it the same way: the last to run was 17 September 22:05 UTC, and the next
one due, 18 September 20:00 UTC, never ran.

**Horizon and app do not leak.** Horizon's master and supervisors start their children through
Symfony Process and wait for them (8 processes after 5 days); php-fpm's master reaps its workers
(3 processes). The ERP's scheduler has the same shape (PID 1 `php artisan schedule:work`, no init) and
6 `runInBackground` commands, but they run daily or less, so it gathers a handful a day and every
deploy recreates it (2 processes on 21 September). It is recorded here, not changed: another
repository.

**The restart (21 September, 05:06 UTC).** `docker compose restart scheduler`, on the owner-delegated
operational authority, to put production back into its designed state; nothing was switched on. Right
after: 3 processes, `/api/health` 200 with the scheduler `ok` and a tick 24 seconds old. The five
daily jobs have `missed_policy` skip, so their missed slots were skipped and they run again tonight
(20:00 to 22:05 UTC). **Without a fix the restarted container dies again about 72 hours after 05:06 UTC on 21 September,
early on 24 September UTC, so it must be restarted again on 23 September if the fix is not deployed**,
and a seven-day A0 shadow period, whose ticks run on this scheduler, could never complete.

**Why nobody was told the right thing.** `/api/health` answers 503 whenever a component fails
(`HealthController`: overall `fail` is 503, `degraded` is 200). Every reader of it discards the body of
a 503, each measured on production on 21 September:

1. `deploy/hq-monitor.sh:118` asks `http://127.0.0.1/api/health` with a `Host` header. Port 80 is
   Traefik's HTTP entrypoint and answers **301** to HTTPS before any router (measured), so this read
   never reaches HQ at all.
2. Its fallback, `:119`, is `docker exec bordeguna-hq-web-1 wget -qO- http://127.0.0.1/api/health`.
   Busybox `wget` prints **nothing** and exits 1 on a non-2xx answer (measured with a 404).
3. So `HQ_JSON` is empty and the monitor alerts `hq_reachable`: "HQ /api/health unreachable" (sent on
   18 September 11:40 UTC, once, because alerts are state changes), and the daily heartbeat has said
   `HQ unknown` since. The scheduler component check never ran.
4. `deploy/verify-only.sh:179`, `deploy/deploy.sh:182` and `deploy/rollback.sh:178` read health with
   `php -r 'echo @file_get_contents("http://web/api/health") ?: "{}";'`. Without the `ignore_errors`
   context option PHP returns false on a non-2xx answer (measured: a 404 gives `{}`; the same call with
   `ignore_errors` gives the body). So the preflight said `/api/health is 'unreadable', not ok ... {}`
   instead of naming the scheduler, and a deploy or rollback onto a failing component records
   `health: unreadable` and says nothing about which part failed.

**Why the suite did not catch it.** `deploy/tests/docker-stub.sh` answers every `php -r` health read
with the JSON body whatever the status, so it plays a PHP that reads a 503 body, which real PHP without
`ignore_errors` does not do. The suite's "an unhealthy stack fails with /api/health is 'fail'" check
passes against a script that on production prints 'unreadable'.

#### C.8.2 The design (written before the code)

1. **A reaper for the scheduler.** `init: true` on the `scheduler` service in
   `deploy/docker-compose.yml`: Docker runs its bundled init (tini) as PID 1, the entrypoint and
   `schedule:work` become its children, and every orphaned background command is re-parented to the
   init, which reaps it. Only the scheduler: horizon and app were measured not to leak, and a change
   to their PID 1 is a change nobody asked for. After the deploy, `docker stats` should show the
   scheduler's process count staying in single or low double digits for a day.
2. **Every health read keeps the body of a non-2xx answer.** The three PHP one-liners pass
   `stream_context_create(["http" => ["ignore_errors" => true, "timeout" => 10]])`. No answer at all
   (connection refused, a timeout) still gives `{}` and is still `unreadable` (measured).
3. **The failing parts are named.** A helper beside the existing health parsers (`rs_health_failing`
   in `release-state-lib.sh`, `health_failing` in `verify-only.sh`, which runs standalone from a copy)
   lists every component whose status is not ok, with its detail or error:
   `scheduler fail (no tick recorded)`. The preflight's FAIL, the deploy's and the rollback's UNVERIFIED
   line print it. `release.json` keeps its schema: the component list goes to the operator's output
   and to the deploy log, which already prints the health body.
4. **The monitor asks HQ itself.** `curl -s -m 10 --resolve hq.bordeguna.com:443:127.0.0.1
   https://hq.bordeguna.com/api/health` (curl prints the body whatever the status, and `--resolve`
   keeps it on this machine, the same way the ERP check has asked since 13 September); the fallback
   goes through the app container's PHP with `ignore_errors`, never busybox `wget -q`. The component
   message carries the detail: `HQ scheduler status=fail (no tick recorded)`. `STATE_DIR`, the config
   file and the host name become overridable by environment (defaults unchanged) so the suite can run
   the real script.
5. **No retry is added to the deploy's verification for the scheduler.** The heartbeat is in Redis,
   which a deploy does not recreate, with a 600 s TTL and a 180 s staleness limit; the old scheduler
   keeps ticking through the build and the new one ticks within a minute of `up`, and the verification
   already retries for 90 s (18 x 5 s). A scheduler that reads `fail` after that is a real failure.
6. **Tests first, in `deploy/tests/deploy-safety-test.sh`, the one file CI runs.** The stub is made
   faithful (a failing health is HTTP 503; the `php -r` read gets the body only when it asks for
   `ignore_errors`, else `{}`; the body names `scheduler fail` with its detail). Then: the preflight on
   a failing stack names the scheduler and never says `unreadable`; a deploy and a rollback onto a
   failing stack record `health: fail` and print the failing component; the compose file's `scheduler`
   service has `init: true`; and a new section runs the real `hq-monitor.sh` against stubbed `curl`,
   `docker`, `free`, `df` and `journalctl` that behave as measured (port 80 answers 301, busybox `wget`
   prints nothing on a 503, PHP without `ignore_errors` gives nothing): a 503 with a failing scheduler
   alerts `hq_scheduler` with its detail and not `hq_reachable`, through the direct read and through the
   fallback, and nothing answering at all still alerts `hq_reachable`. Each new check is watched failing
   against the scripts of `f736a52` before the change.

#### C.8.3 As built (21 September)

No PHP file, migration, prompt, schedule or application behaviour changed; nothing was switched on.
Only the stack definition, the four host scripts that read health, their test suite, and the docs.

**The reaper.** `deploy/docker-compose.yml:116`: `init: true` on the `scheduler` service, with the
incident in its comment. Resolved with the real Docker Compose on the VPS (v5.3.1, read-only: the
branch's file copied to `/tmp`, `config --format json` with production's project directory and env
file, only the `init` key of each service printed, the copy removed): `scheduler: True`, every other
service `None`. Production's own compose file was not touched.

**The health reads.** `deploy/verify-only.sh:198`, `deploy/deploy.sh:183` and `deploy/rollback.sh:179`
read `/api/health` with `stream_context_create(["http" => ["ignore_errors" => true, "timeout" =>
10]])`. `rs_health_failing()` (`deploy/release-state-lib.sh:108`) and its standalone copy
`health_failing()` (`deploy/verify-only.sh:74`) list every component whose status is `fail` or
`degraded` with its detail or error: on the 503 body production gave on 21 September it prints
`scheduler fail (no tick recorded)`, and on the same body with a queue failure added,
`queue fail (RedisException), scheduler fail (no tick recorded)`. The preflight's FAIL now reads `/api/health is
'fail', not ok, BEFORE any deploy; failing: scheduler fail (no tick recorded). Body: ...`, and a read
that gets nothing says `gave no readable answer (nothing answered, it timed out, or the body is not the
health JSON)` (`:203`). The deploy's and the rollback's UNVERIFIED lines carry `(failing: ...)`
(`deploy.sh:198`, `rollback.sh:189`). `release.json` is unchanged in shape.

**The monitor.** `deploy/hq-monitor.sh:15-17`: `HQ_MONITOR_STATE_DIR`, `HQ_MONITOR_ENV` (and `HQ_HOST`
at `:130`) override the state directory, the config file and the host, defaults as before; the cron
line sets none of them. `:131` asks `https://hq.bordeguna.com/api/health` with `curl -s -m 10 --resolve
hq.bordeguna.com:443:127.0.0.1`; `:132` falls back to the app container's PHP with `ignore_errors`;
busybox `wget` is no longer used for health. `:140` adds the component's detail to its message:
`HQ scheduler status=fail (no tick recorded)`.

**Found on the way, while the monitor still ran the old script:** after the restart at 05:06 UTC the
monitor sent `RECOVERED hq_reachable` at 05:10 UTC, because a healthy 200 was readable even through
busybox. The alert of 18 September and this recovery are the only two messages the incident produced;
the component that failed was never named.

**Tests, in `deploy/tests/deploy-safety-test.sh` (the file CI runs) and `docker-stub.sh`.** The stub now
hands a failing health (HTTP 503, the scheduler failing with its detail) to a `php -r` read only when
that read asks for `ignore_errors`, else the script's `{}`, as measured. New checks: section 12 (a
deploy onto a failing component names it in the UNVERIFIED line, and the deploy log carries the 503's
body), 16b (a rollback onto a failing component records `health: fail`, not `unreadable`, and names
it), 23 (the preflight names the component, never calls a readable 503 `unreadable`, and a health that
gives nothing says "gave no readable answer"), 24 (the compose file's `scheduler` service has `init:
true`), and 25, which runs the REAL `hq-monitor.sh` against stubbed `curl`, `docker`, `free`, `df` and
`journalctl` behaving as measured (port 80 answers 301; busybox `wget -q` prints nothing on a 503; PHP
without `ignore_errors` gives nothing): a 503 naming the scheduler alerts `hq_scheduler` with its detail,
texts it, does not call HQ unreachable and records HQ reachable; the next tick with the scheduler back
reports `RECOVERED hq_scheduler`; health is never read through `wget`; the fallback through the app
container still names the scheduler when the direct read gets nothing; and nothing answering at all is
still `hq_reachable`.

**Watched failing first.** The new suite and stub were run against the unmodified scripts of
`f736a52` (the suite copies its scripts from the tree it sits in, so a tree of `f736a52`'s `deploy/`
with the new `tests/` in it): **242 passed, 18 failed**. The 18 are 16 of the 22 new checks, and two
existing checks that had passed only because the old stub handed over a 503's body: section 12's
"verification recorded as FAILED" (the old deploy recorded `health: unreadable`) and section 23's "an
unhealthy stack" (the old preflight said `'unreadable', not ok ... {}`, word for word what production
printed that morning). The six new checks that pass there are guards that must hold before and after:
a rollback onto a failing component still completes, a preflight whose health gives nothing still exits
non-zero with `RESULT: FAILED` and changes nothing, and nothing answering at all still alerts
`hq_reachable`.

**Verification (local; GitHub Actions validation deferred until the 1 October reset).** The
deploy-safety suite on the branch: **260 passed, 0 failed** (238 on `f736a52`, plus the 22 new checks), on Git Bash with the NTFS `stat`
shim of stream G, never committed; **Linux parity is unproven**. `bash -n` clean on every
`deploy/*.sh`, `deploy/gate/*.sh` and `deploy/tests/*.sh`. No PHP changed, so no Pest run was needed:
the application tree is still the one verified in section D (1189 passed).

**Decisions to revisit.**

1. The ERP's scheduler has the same PID 1 and six `runInBackground` commands. It gathers a handful of
   zombies a day and every ERP deploy recreates it, so it cannot reach a limit now; `init: true` there is
   a one-line change in the ERP repository when next touched.
2. The monitor does not watch a container's process count. With a reaper in place the health read is
   enough: it would have paged `HQ scheduler status=fail (no tick recorded)` on 18 September.
3. `schedule:work` could be replaced by a cron-style `schedule:run` loop in a shell that reaps, but the
   init is the smaller change and keeps `schedule:work`'s sub-minute behaviour.

### C.9 Stream M: one deploy at a time, and the residuals this milestone found (branch `fix/deploy-lock-and-residuals`)

Five things found while this milestone was built, each recorded above as "found on the way, not
fixed", or seen on production on 21 September. None changes what the candidate `87d8658` (running on
production since 21 September 06:32 UTC) does for a user or an agent: four are in `deploy/`, config
and a runbook, and one is a readiness classification. Planned here before the code.

#### C.9.1 Today's behaviour (read from the code on 21 September)

1. **Nothing stops two deploys at once.** `deploy/deploy.sh` and `deploy/rollback.sh` take no lock.
   Two runs at the same time each take a dump through the same `backup.sh once` and the same
   `LAST_OK` marker, each move the checkout (a fast-forward, or a `git checkout <target>`), each build
   and each `up`; a rollback's `up` with migrations off can land between a deploy's `up` with them on
   and its health check, and the two `RELEASES.md` lines and `release.json` records then describe a
   release neither run made. It is not hypothetical: on 21 September the owner ran the release by
   hand while a session was working on the same host. The gate runner already refuses a second copy
   of itself with `flock -n 9` on `/tmp/<gate>.lock` (`deploy/gate/run.sh:35-37`), so `flock(1)` is on
   the VPS (the gate has run there eleven times); the release scripts never had the same guard.
2. **The template still names the deleted ERP host.** `deploy/.env.example:57` says
   `HQ_ERP_BASE_URL=https://erp.srv1808022.hstgr.cloud`, the provider hostname the ERP stopped
   answering on 7 September (`config/hq.php:142` records what that default cost: every ERP read 404,
   silently). Production's `deploy/.env` has no such line, so the config default
   (`https://erp.bordeguna.com`) applies, and the preflight of 21 September WARNed exactly that the
   template has keys the host lacks: the WARN invites copying the template's value, which is the dead
   host. `config/hq-seed.php:35` gives the `erp-counter` store the same base URL, and `DomainSeeder`
   writes it with `updateOrCreate`, so every re-run of the seeder on production puts the dead host
   back on that row.
3. **The preflight does not look for a stray `RUN_MIGRATIONS` in `deploy/.env`** (C.3, found on the
   way). Every PHP service reads `deploy/.env` through `env_file`, and only `app` sets
   `RUN_MIGRATIONS` in `environment`. A `RUN_MIGRATIONS=1` in `deploy/.env` therefore reaches horizon
   and the scheduler, whose entrypoints then run `migrate --force` at the same moment as `app`'s. The
   rollback already refuses it (its resolved configuration reads `0 1`, C.3.2); a deploy says nothing
   and migrates three times at once; the preflight says nothing either.
4. **Anthropic's monthly spend limit reads as a connector error** (C.7, found on the way).
   `ProviderCheck::classify()` calls a 400 a credit block only when the message says "credit balance"
   or "purchase credits". The limit's own answer, measured on production during gate 6 (`docs/69`:
   `anthropic 400: You have reached your specified API usage limits. You will regain access on
   2026-10-01`), says neither, so it falls to `connector_error`: the readiness table calls a billing
   block "an answer HQ could not use", and `docs/runbooks/provider-blocked.md` calls it
   `provider_unavailable`, which the code has never said. OpenAI's spend limit (a 429 with
   `organization_spend_limit_exceeded`) is already `provider_blocked_by_credit`, so the two providers
   disagree about the same fact. The runbook covers Anthropic only.
5. **`EvidencePolicies::retire()` saves the model it is handed** (C.4, found on the way). It checks
   the status of the caller's in-memory model and `forceFill(...)->save()`s that same instance, so any
   other unsaved change the caller holds is written with the retirement, where the trigger refuses it
   (an active version is immutable), and a model loaded before somebody else retired the version is
   judged on its stale status.

#### C.9.2 The design (written before the code)

1. **One host lock, shared by `deploy.sh` and `rollback.sh`**, in a new `deploy/host-lock-lib.sh`
   that both source before they read or change anything (rollback before its `git checkout`, as it
   already does for the other libraries). The lock is `flock -n` on file descriptor 9 of
   `${HQ_DEPLOY_LOCK:-/tmp/bordeguna-hq-deploy.lock}`: the same tool and the same directory as the gate
   runner. The kernel releases it when the process ends, however it ends, so a killed deploy never
   leaves a lock behind. The file is opened for APPEND, so a refused run cannot wipe the holder's
   record by opening it. The holder then writes one line, `pid=<pid> kind=deploy|rollback
   started=<UTC> checkout=<sha>`, and clears it in its EXIT trap. A second run refuses at once, with
   that line, before any read that decides something: no dump, no probe, no checkout, no container,
   no record. A missing `flock` or a lock file that cannot be opened is a refusal too (fail closed).
   `--verify-only` is handed to `verify-only.sh` before the lock, as today, and takes no lock.
2. **The preflight reports a held lock, read-only.** It reads the record and never opens the lock.
   A record whose pid is alive and is a `deploy.sh` or `rollback.sh` is a **FAIL**: everything else
   the preflight reads is mid-change while a release runs, and the deploy it previews would be
   refused. A record whose pid is gone is a **WARN**: a run was killed before it could clear its line;
   the lock itself is free. A missing `flock` or a lock that cannot be opened is a FAIL, because
   `deploy.sh` would refuse.
3. **The ERP host.** `deploy/.env.example` and `config/hq-seed.php` name `https://erp.bordeguna.com`.
   A Pest test reads every file under `config/` (comments stripped) and `deploy/.env.example`
   (comment lines skipped) and fails on the deleted host, so it cannot come back. The historical
   comments that name it on purpose, and every numbered document, stay as they are.
4. **A stray `RUN_MIGRATIONS` in `deploy/.env`.** The preflight FAILs on one whose value is not `0`,
   the same line the rollback refuses (three containers would migrate at once), and WARNs on `0`
   (inert today, but a second place that decides whether a container migrates). `deploy.sh` refuses
   the non-zero case too, before anything moves: the preflight is optional, and the hazard is the
   deploy.
5. **Anthropic's spend limit is a credit block.** `classify()` reads a 400 that says "usage limits"
   (the measured wording) as `provider_blocked_by_credit`, which never condemns the key. The runbook
   gets one table per provider, OpenAI's with its billing codes (C.7.3), and the Anthropic row that
   said `provider_unavailable` is corrected.
6. **`retire()` acts on a fresh row.** It re-reads the version by id, locked for update, inside the
   transaction; judges the fresh status; retires that row; and returns it. The caller's instance and
   its unsaved changes are left alone.

**Tests, each watched failing first.** Items 1, 2 and 4 in `deploy/tests/deploy-safety-test.sh` (the
file CI runs), against the real scripts with the docker stub: a deploy held inside its dump while a
second deploy, a rollback and the preflight run beside it; a rollback held the same way while a deploy
runs; a stale record; a lock that cannot be opened; the preflight never creating the lock. Git Bash
has no `flock(1)`, so the suite installs a stand-in only when the real one is missing: the same
`flock(2)` call on the inherited descriptor, through `perl`, which is how `flock -n 9` itself works
(the lock belongs to the open file description, so it outlives the helper and holds while the script
keeps the descriptor open). Items 3, 5 and 6 in Pest.

#### C.9.3 As built

**The host lock.** `deploy/host-lock-lib.sh` (new, sourced, mode 644 like `backup-first-lib.sh`):
`hl_acquire deploy|rollback` refuses without `flock`, opens `${HQ_DEPLOY_LOCK:-/tmp/bordeguna-hq-deploy.lock}`
on descriptor 9 for append (a failed open is a refusal: "cannot open the host lock"), and on `flock -n 9`
failing reads the holder's line and dies with "another deploy or rollback is already running on this
host (pid=... kind=... started=... checkout=...). Refusing to <verb>; nothing has moved." The holder
truncates the file and writes that line, and `hl_release` clears it on exit. `deploy.sh` takes it right
after the mode-600 check, before `running_release` and everything that decides; its EXIT trap is now
`record_failed_release; hl_release`. `rollback.sh` takes it right after sourcing the libraries, before
the target is read; its trap is `record_failed_rollback; hl_release`. `--verify-only` is still handed
over before either. The lock is on a descriptor, so the rollback's `git checkout` replacing the script
on disk does not touch it. Tested on Git Bash, by hand, before the suite: perl's `flock` on an
inherited descriptor holds across processes while the shell keeps the descriptor, and is released
when the shell ends.

**The preflight** (`verify-only.sh`, new section "host lock", right after the checkout): reads the
record with `head` and never opens, creates or locks the file. A pid that is alive (`kill -0`, or
`/proc/<pid>`) and, where `/proc/<pid>/cmdline` is readable, is a `deploy.sh` or `rollback.sh`: FAIL
naming the record. A record whose process is gone: WARN, the lock is free. A lock the user could not
open, or no `flock`: FAIL. Otherwise `ok no deploy or rollback holds the host lock`. The header's list
of what it runs names the new reads.

**The stray `RUN_MIGRATIONS`.** The preflight's `deploy/.env` section FAILs on a `RUN_MIGRATIONS` line
(with or without `export`, quoted or not; the last one wins, as `env_file` reads it) whose value is not
0, and WARNs on a 0, naming the key and never printing the line. `deploy.sh` refuses the non-zero case
after taking the lock and before anything is read or moved. That refusal goes a step past the brief,
which named the preflight only: the preflight is optional, and the hazard is the deploy. Production's
`deploy/.env` had no such line on 21 September (C.3.2), so this refuses nothing that runs today.

**The ERP host.** `deploy/.env.example` and `config/hq-seed.php` (`erp-counter`) now name
`https://erp.bordeguna.com`. `tests/Feature/Foundation/DeletedErpHostTest.php` (3 tests) reads every
`config/*.php` with its comments stripped by the PHP tokenizer (so `config/hq.php`'s history comment is
allowed), and `deploy/.env.example` with its comment lines skipped, and requires the seed's
`erp-counter` URL. The comments in `config/hq.php` and `deploy/hq-monitor.sh` that record the old host,
and every numbered document, are unchanged. Production's `stores` row keeps the dead URL until the
`DomainSeeder` is next run there, which then writes the right one; nothing reads that row's URL today
(`erp_pos` has no connector).

**Anthropic's spend limit.** `ProviderCheck::classify()` reads a 400 containing "api usage limit" as
`provider_blocked_by_credit`, with the measured wording in the comment. A plain bad request is still
`connector_error` (the dataset case and a new assertion). `docs/runbooks/provider-blocked.md` is
rewritten per provider: which one serves (`hq:ai:provider`), Anthropic's table with both billing 400s
and the note that the usage limit used to read as `connector_error`, OpenAI's table with its five
billing codes, the IP allow-list and region cases, and a warning that a key answers for the
organisation it was made in (on 21 September the stored key answered `credit_balance_exhausted` while
the Bor de Guna organisation showed credit).

**`retire()`.** The whole act runs in one transaction: the version is read again by id with
`lockForUpdate()`, the refusals are judged on that row (a missing row is refused too), that row is
retired, audited and returned. The caller's instance is not touched. The console command
`hq:autonomy-policy:retire` needed no change.

**Watched failing first.** The deploy-safety suite with its 53 new checks, run against the unmodified
scripts of `d2f0e30` (this branch's plan commit; the scripts are those of `6739fe5`): **275 passed,
38 failed**. All 38 are new checks; every one of the 260 existing checks passed. Most of the 15 new
checks that pass there are guards that must hold before and after: the worlds are set up as intended (a
deploy or a rollback really is part way through, inside its dump), the preflight creates no lock file,
a preflight refusing a stray line still changes nothing and prints no `KEY=value`, a `RUN_MIGRATIONS`
of 0 does not fail the preflight, and the release that was part way through completes. One passed for
an unrelated reason: the old deploy beside the held rollback failed at its own `up`, so "is refused"
held without any lock. The failures show the defect itself: the second deploy
and the rollback beside a running deploy both ran to the end and moved the checkout, the rollback
released `61b589d` while the held deploy was still inside its dump, and the held deploy then carried on
over it. The same run exposed a defect in the suite: the `sleep` wrapper looked up the real `sleep` on
a PATH that still carried the previous world's `bin/`, whose removal on NTFS can lag behind a process
that has just ended, and a vanished wrapper made one rollback exit 127. The wrapper now looks it up on
the system PATH only; `lock_record` no longer prints a redirection error for a missing file. In Pest, the
6 new tests failed on the unchanged code for the stated reasons: `hq-seed.php` (and only it) named the
host in a value, the template line named it, the seed URL was the dead host, the spend limit read
`connector_error`, the retirement with an unsaved edit raised the trigger's `QueryException`, and the
stale copy raised a `QueryException` instead of the refusal.

**Verification (local; GitHub Actions validation deferred until the 1 October reset; Linux parity
unproven).** Full Pest suite in one run: **1195 passed, 10,715 assertions, 0 failed, 271 s** (1189 plus
the 6 new). `vendor/bin/pint --test` passed; `npx tsc --noEmit` clean; `npx vite build` built. The
deploy-safety suite: **313 passed, 0 failed** (260 plus 53), on Git Bash with the NTFS `stat` shim of
stream G (never committed) and the suite's own `flock` stand-in; on Linux the real `flock(1)` is used
and the stand-in is not installed. `bash -n` clean on every `deploy/*.sh`, `deploy/gate/*.sh` and
`deploy/tests/*.sh`. `deploy/README.md`: the release order names the lock and the refusal, a paragraph
says what the lock message means and that nothing is ever removed by hand, and the preflight's list of
checks names both new ones.

**Decisions to revisit.**

1. The lock lives in `/tmp`, beside the gate runner's, because `/tmp` exists on every host and the gate
   has used it on the VPS. `/run/lock` would clear the record on a reboot; with `flock` the lock itself
   never outlives its process, so only the record could be stale, and the preflight reads that as a
   WARN.
2. The lock covers `deploy.sh` and `rollback.sh` only. The gate runner has its own; `restore.sh` runs
   inside the backup container and is a deliberate act with its own confirmation; the release-state
   refresher only reads and writes `main.json`. A gate launched during a release is not refused.
3. A missing `flock` is a refusal in both scripts and a FAIL in the preflight, but no check shows it:
   the suite always has either the real `flock` or its stand-in.
4. The first release after this merges is run by production's copy of `deploy.sh`, which takes no
   lock; the protection starts with the release after it.

**Found on the way, not fixed.**

- `EvidencePolicies::activate()` has the shape `retire()` had: it judges the in-memory model and
  saves the caller's instance. The trigger re-checks everything an activation needs, so it fails
  closed, but a caller's unsaved edit to a proposed version would be activated with it.
- `deploy/README.md` still heads the 21 September release steps "NOT YET APPLIED TO PRODUCTION",
  although production runs `87d8658` since 06:32 UTC that day (C.8, D). Left for the merge step.
- The local PostgreSQL was stopped after the power cut and was started again for this run (`pg_ctl`).

## D. Merge record, the combined tree, and the release candidate (21 September)

### D.1 Merge record

Every stream was built in its own worktree and test database, reviewed by reading its diff and
re-running its suite, and merged to main with `--no-ff`, one at a time, with the full suite run on the
combined tree before each push:

| Order | Stream | Merge | Full Pest on the combined tree |
| --- | --- | --- | --- |
| 1, 2 | E, F | `074ffae` | 1046 |
| 3 | J (pre-candidate defects) | `22688b6` | 1066 |
| 4 | I (Phase 4 reads, HQ side) | `69112b8` | 1082 |
| 5 | H (autonomy evidence policy and producer) | `c7725ce` | 1113 |
| 6 | G (rollback invariant, verify-only) | `33e6c5e` | 1113 (G adds deploy-safety checks, no Pest tests) |
| 7 | K (OpenAI as the model provider) | `675d5dd` | 1189 |
| 8 | L (the scheduler's reaper, health readers that keep a 503's body) | `dff1a32` | 1189 (no PHP changed); deploy-safety 260 |

K merged main into its branch first (`61297f4`), so the tree of `675d5dd` is byte-identical to K's
tested head `8ebc5d3`.

**A power cut stopped the session after the K merge and before its push.** Nothing was lost: every
worktree was clean, and the merge commit was on disk. On resuming, the whole verification was run again
on `675d5dd` before it was pushed: **full Pest suite in one run, 1189 passed, 10,692 assertions, 0
failed, 248 s**; `vendor/bin/pint --test` passed; `npx tsc --noEmit` clean; `npx vite build` built.
Deploy-safety suite: 238 checks on Git Bash with a `stat` shim (stream G), not re-run since, because
nothing after G touched `deploy/*.sh` except the gate runner, which it does not read (`bash -n` clean).
**Local verification passed; GitHub Actions validation, and the deploy-safety suite on Linux, deferred
until the 1 October reset.**

### D.2 The release candidate

**The candidate is `origin/main` at the commit that records stream L in this section** (first named as
`f736a52`; it moved when the candidate's own preflight on production found the scheduler dead, C.8).
Its application code is byte-identical to `675d5dd`, the tree verified above (1189 Pest); stream L
changed only `deploy/` and documents, and its merge `dff1a32` is byte-identical to its tested head
`ca7df34` (deploy-safety 260 passed, 0 failed, on Git Bash with the `stat` shim). The deploy also
recreates the scheduler container with `init: true`: after it, `docker stats` must show the
scheduler's process count flat, not climbing about 2 a minute.

What it carries over production's `3314c54`:

- **Nine additive migrations:** `2026_09_16_100000` shadow periods, `2026_09_16_200000` pairing,
  `2026_09_16_300000` retention and alerts, `2026_09_17_100000` Ask HQ, `2026_09_17_200000` connector
  call correlation, `2026_09_20_100000` briefings, `2026_09_20_200000` execution context on agent runs,
  `2026_09_21_100000` autonomy evidence policy tables, `2026_09_21_160000` requester identity on
  approvals. None drops or rewrites anything.
- **New scheduled commands:** `hq:alerts:check` every 5 minutes, `hq:retention:run` 08:15 Dhaka,
  `hq:conversation:compare-sweep` 01:00 Dhaka, and the shadow commands (`hq:shadow:tick` 09:00 and
  17:00, `hq:shadow:evidence --check` hourly and `--store` 00:15), each doing nothing while no shadow
  period is open. ⚠ The first `hq:alerts:check` pages Telegram for any REAL open condition it finds; on
  a blank HQ it opens the provider-readiness incident. Read the Alerts page right after the deploy.
- **No prompt file, TaskGrounding, TaskScope, detector or agent schema changed.** The stamps are still
  `executive.v8+18d60e98` and `support.v1+0f4a195b`.
- **Nothing switches on.** `ai.runtime` stays off, the three agents stay paused at A0, no grant, no
  flag row, no autonomy policy, no shadow period. The OpenAI rows do not exist until the seeder runs,
  and are created off.

**The ERP side of stream I is on ERP production.** ERP branch `feature/hq-api-order-reads` was
fast-forwarded to ERP master `5bfb709` and deployed on 21 September behind a gzip-tested dump of the
ERP database (`/root/backups/bordeguna-erp/erp-pre-deploy-20260921-0457.sql.gz`, 254 tables, the whole
application database). Code only: nothing to migrate. After the deploy: `/login` 200, the five
`order-reads` routes registered, an unauthenticated call refused with 401, no failed jobs, no error in
the app log. The endpoints stay unreachable to HQ until a new ERP token carrying their abilities is
issued (`deploy/README.md`); the gate does not need them.

### D.3 What happens next, in order

1. Joy stores the OpenAI key on `/credentials` (`openai_api_key`, account `hq`, type `api_key`,
   group-wide). Production's form takes the provider as free text (read from `3314c54`), so this can be
   done before the deploy; nothing on `3314c54` reads it.
2. Joy deploys the candidate: `deploy/README.md`, "the next deploy, and exactly what Joy types"
   (preflight from a copy of `verify-only.sh`, the deploy by the old script, then `--verify-only
   --no-pull` and `--no-pull` by the new one).
3. The OpenAI steps after the deploy (`deploy/README.md`, "OpenAI as the model provider"): the seeder,
   the dry run, `hq:ai:provider openai --apply --by=<Joy> --reason=...`, then
   `hq:credentials:health --verify-model` must read `verified`.
4. ONE Gate 11 attempt 2 on the candidate, now measuring `gpt-5.6-terra` for the Executive's turns.
   The runner proves the structured reply before any case and stops without a measurement if it fails.
5. A complete pass only: the seven-day A0 shadow, opened with `--by <a real person>`. Anything else:
   everything stays inert.

### D.4 Where the steps stand (21 September, 07:07 UTC, read on production)

1. Done, but with the wrong key: row #18 `openai_api_key` / `hq` was stored before the deploy, and
   `hq:credentials:health --verify-model` answered `provider_blocked_by_credit` (06:28 UTC). The key
   belongs to another OpenAI account that has no credit; the Bor de Guna organisation, which does have
   credit, is a different account. Joy stores a key made in the Bor de Guna organisation (project
   "Bor de Guna HQ Automation") on `/credentials`.
2. Done. The old script at 06:19 UTC (nine migrations), then `--verify-only --no-pull` and
   `--no-pull` at 06:32 UTC. `deploy/release-state/release.json`: `status: released`, dump
   `hq-20260921-063157.dump` verified at 06:31:58, verification passed, `/api/health` names
   `87d8658`. The scheduler runs under `docker-init`: 2 processes and no zombie at 07:06 UTC.
3. Done except the verification: the seeder, the dry run, then `hq:ai:provider openai --apply` under
   Joy's name (audit #679). `hq:ai:provider` reads `serving: openai`, the four OpenAI rows on and the
   four Anthropic rows off; `hq:ai:probe` routes a diagnostic to `gpt-5.6-luna`; HQ's own budget reads
   $19.59 of $50. Verification waits for step 1's key.
4. Waiting for a verified key.
5. Waiting for step 4.

The P3-G1 production replay (`hq:conversation:simulate --repeat=2`, rolled back, no model) can run
now that production carries the invariants; this session's permission classifier refused it as a
remote write, so it is Joy's line to type.

## E. The cumulative report (point 18), as posted in the ChatGPT thread on 21 September

Posted before Gate 11 attempt 2, because everything else the milestone asked for is done and only an
owner action (the key) stands between the candidate and the gate; the previous instruction set that
cadence ("do not wait for Gate 11 to report all progress"). The gate's result follows as its own
message.

> Cumulative report (your 21 September instruction, docs/81, point 18). From Claude, 21 September. Everything below was read from the repository and from production today.
>
> 1. P0 DEFECTS. All fixed, each watched failing first. P0-1: a disabled account is refused at every door (password step, 2FA challenge, a live session rejected on its next request with its remember token cycled and one audit row, JSON 401, Horizon, password reset); no code path can disable anybody, so no lockout. P0-2: the BusinessScope cache is gone (read on every call), and a run whose people were narrowed after it was resolved ends its remaining calls. P0-3: a rollback never migrates (an override pins RUN_MIGRATIONS=0 and the started container is read back), it is backup-first through the same library as deploy, and every refusal comes before anything is touched. P0-4: an agent's approval records requester, enabler, requester kind and context digest separately (additive migration; old rows untouched and labelled as predating the split); the requester cannot approve what they asked for; one rule at every door. P1-1: facts.query_orders orders by metric then a typed key, and reports a tie at the limit instead of choosing silently.
>
> 2. CANDIDATE AND RELEASE. Eight streams, each in its own worktree, merged --no-ff one at a time with the full suite on the combined tree before each push: E, F, J (defects found on the way: Horizon behind 2FA, kill switches and flags read fresh, an approve-with-edit redacted by the tool's declared paths), I, H, G, K, L. Candidate 87d8658 (application code byte-identical to 675d5dd). Joy deployed it inert, backup-first: the old script at 06:19 UTC (nine additive migrations), then the new script with --verify-only and --no-pull at 06:32 UTC, which wrote the release record: released, verification passed, /api/health names 87d8658.
>
> INCIDENT, found by the candidate's own preflight (stream L). Production's scheduler had been dead since 18 September: PID 1 was schedule:work with no init, every runInBackground child stayed a zombie (9,482 against a pids.max of 9,483), so nothing scheduled ran from 17 September 22:05 UTC to 21 September. The monitor said "unreachable" once, because every reader of /api/health threw away a 503's body. I restarted it by hand; stream L puts init: true on the scheduler and makes deploy, rollback, verify-only and the host monitor keep the 503's body and name the failing component. Now live: 2 processes, no zombie.
>
> 3. EXECUTION CONTEXT ON THE RUN PAGE. The recorded eec.v1 context in sentences: who asked and on what basis, who enabled, what acted, entry point, environment, businesses, brands, tools, what narrowed it, when it was resolved. People by name and id, never email; scope outside the viewer's own is counted, not named; the digest folded away. Read-only, existing permission. A refused context has no run row and stays in the audit log.
>
> 4. VERIFY-ONLY. deploy.sh --verify-only runs the real preflight and changes nothing (shown byte-identical in the passing and the failing case); HQ_ROOT runs a copy against production. Its first real use found the incident above. deploy.sh refuses unknown arguments.
>
> 5. AUTONOMY EVIDENCE POLICY. Built (ADR-048): versioned policy tables guarded by triggers, one writer, console-only propose, activate, retire (dry run until --apply; activation needs --by, --reason and no undecided field). The producer recounts from evaluation_results; the policy is one more ceiling in the resolver and can only lower. A grant naming a suite is now compared with that suite's own figure (the old resolver let a pooled 0.96 satisfy it). agent_runs.autonomy_evidence records what each run was judged under. No numbers and no version exist; production says "No autonomy evidence policy is active". Left for the owner: which levels, which of the six dimensions per level, rate, sample and age per requirement, hard checks, who activates.
>
> 6. M7 BRIEFING BUNDLE. Unchanged since docs/80, as you approved it; narration off; no scheduled briefing.
>
> 7. M6. Unchanged, inert; ask_hq and scheduler.nl off.
>
> 8. P3-G1 AND P3-G2. P3-G1 local: 33 of 33, two replays identical, no residual trace. The production replay (rolled back, no model) can run now that the release carries the invariants; my own attempt was refused by this session's permission classifier as a remote write, so it is one line for Joy to type. P3-G2 prepared, not run: after Gate 11, with Joy as enabling user for the simulated run only; Support stays paused.
>
> 9. PHASE 4 READS. ERP endpoints (products, SKU, price with its rule, availability, customer eligibility; reads only) are on ERP production. HQ tools erp.lookup_product, lookup_sku, read_price, read_availability, check_order_eligibility: R2, held by no agent, eligibility not agent-executable, phone masked; one contract digest in both repositories. Not reachable yet: they need a new ERP token with the read abilities. No order write anywhere.
>
> 10. PROVIDER. OWNER DECISION, 21 September: Anthropic is skipped for now; HQ uses OpenAI's API. Stream K (ADR-049): an OpenAI driver on the Responses API with store: false; exactly one provider serves any measurement, moved by an audited lever under a named person; readiness follows the serving provider; a billing 429 is blocked-by-credit and never condemns the key. Tiers gpt-5.6-luna, terra, sol. On production OpenAI serves (switched through the lever under Joy's name) and the Anthropic rows are off. But the stored key belongs to another OpenAI account with no credit (verify-model: provider_blocked_by_credit); Joy is storing a key from the account that has credit.
> FOR YOUR DECISION RECORD: Gate 11 attempt 2 will be the first gate that measures an OpenAI model (gpt-5.6-terra for the Executive, reasoning effort low); every earlier gate measured Claude. A later return to Anthropic needs its own complete gate before any shadow runs on it. HQ's agent schema is outside OpenAI's strict subset, so turns go strict: false and stay validated by HQ's ArgumentValidator, exactly as on Anthropic; the runner proves one structured reply with the real provider before any case and stops without a measurement if it fails. Estimated cost $2.50 to $3.
>
> 11. GATE 11 READINESS. Ready on the exact candidate, which is production. Missing only a verified key: verify-model must read verified, then one run, zero unmeasured, no tuning, stop for the activation decision.
>
> 12. LOCAL VERIFICATION. Combined tree: 1189 Pest passed (10,692 assertions), Pint, strict TypeScript, Vite; deploy-safety 260 passed on Git Bash with a stat shim. Local verification passed; GitHub Actions validation deferred until the 1 October reset; Linux parity unproven.
>
> 13. MAIN VS PRODUCTION. Production 87d8658 with a verified release record; main is documentation ahead only (a list of what the owner must buy or register per phase, and this report). On a branch, not merged: stream M (one lock so two deploys cannot overlap; verify-only flags a stray RUN_MIGRATIONS in deploy/.env; Anthropic's spend-limit answer read as blocked-by-credit; a deleted ERP hostname left in .env.example).
>
> 14. A0 AND SHADOW. Nothing activated. ai.runtime off; Executive, Analytics, Support paused at A0; grants 0; no autonomy policy; no shadow period; Meta inbound off; no outbound provider. HQ alerts now go to a Telegram bot of HQ's own (the old Hermes agent was removed from the VPS at Joy's request).
>
> 15. BLOCKERS. Joy: (1) the OpenAI key from the account with credit; (2) the P3-G1 production replay line; (3) the new ERP read token; (4) Meta Business Verification for Phase 3 live; (5) a browser walk of the new pages under his 2FA. Mine: stream M; CI reconciliation on 1 October.
>
> MY PROPOSAL, for you to challenge:
> (a) Phase 4 write foundation, built inert: an ERP endpoint that creates a DRAFT sales order only (idempotency key, price and stock re-read by the ERP, never confirmed, invoiced or posted), and in HQ a take_order capability at R3, approval-required, agentExecutable false, held by no agent, with a reconciliation check between the draft and what was proposed. Nothing reaches a customer or the books.
> (b) Design documents for Phases 5 and 6 (marketing and ads; the content factory and research engine), so the owner can start the slow registrations those phases need now rather than when we reach them.
> (c) Order after the key: Gate 11 attempt 2, then P3-G2 on the same provider in the same session if Gate 11 passes, both before the shadow opens. Do you agree, or should P3-G2 wait until the shadow week has run?
