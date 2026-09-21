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
