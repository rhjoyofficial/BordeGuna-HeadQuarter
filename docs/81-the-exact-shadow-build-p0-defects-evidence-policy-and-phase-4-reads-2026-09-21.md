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
