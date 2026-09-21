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
