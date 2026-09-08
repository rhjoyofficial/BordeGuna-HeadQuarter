# 49 - Phase 2 M1: the control-plane foundation, complete (8 September 2026)

**Status: built, tested, deployed and verified on production. HQ only.**

M1 of the Phase 2 Command Center (`docs/48`), under Joy's approved decisions V.1 to V.11 of
8 September. It builds the deterministic layer every later AI capability will depend on, and builds
none of those capabilities: there is no agent, no model call, no Approval Center, no Ask HQ, no
natural-language scheduling, no briefing and no Telegram.

| | |
| --- | --- |
| Starting release | **`184d133`** (docs/47) |
| Ending release | **`c341e93`** (PR #2, merged) |
| Rollback target | **`184d133`** |
| CI | **160 passed / 855 assertions**, from a 105 / 560 baseline |
| Migrations | 2, additive only, reversible, verified fresh + rollback + fresh + seed on Postgres 17 |
| Shop or ERP code changed | **none** |
| Autonomous write capability enabled | **none** |

---

## A. What was verified before anything was written

`docs/48` was written against the code rather than the roadmap, and M1 re-checked every claim in it
before touching a file. Two things it found are worth restating because they shaped the build:

**Phase 0 had already left these seams open**, so M1 is largely a matter of closing them rather than
building a parallel stack: `Tool`, `ToolRegistry`, `ToolContext` and `ToolResult` all existed;
`scheduled_jobs` already carried `tool_key`, `timezone`, `approval_state`, `created_from` and
`nl_source_text`; `audit_logs` was already append-only by a Postgres trigger; `ai_runs`,
`tool_calls`, `connector_calls`, `decision_records` and `owner_preferences` all existed and **no code
path had ever written to four of them**.

⚠ **The live tool keys are not the names in `docs/48` D.6.** Production runs `erp.poll_overview`,
`erp.poll_low_stock`, `commerce.reconcile_facts` and `commerce.check_fact_integrity`. The
architecture used shorter aspirational names. **Five `scheduled_jobs` rows reference the real keys,
so renaming them would have broken the live scheduler**; the real keys stand and `docs/48` is the
document that is wrong. A tool key is an identity other tables point at, not a label.

---

## B. The Tool Gateway

`App\Gateway\ToolGateway::execute(ToolRequest): ToolOutcome`. One door, one order:

```
 1. registry + runtime switch   -> unavailable
 2. schema validation           -> invalid       (before policy, before any credential)
 3. business scope + context    -> denied
 4. permission (intersection)   -> denied
 5. policy (deterministic)      -> allow | require_approval | deny
 6. approval                    -> M3; in M1 an approval requirement is a refusal, not a wait
 7. idempotency                 -> replay a recorded result, or reconcile an uncertain one
 8. credential pre-flight       -> unavailable, with the missing credential named
 9. execute
10. record: tool_calls always, audit_logs for R3 and above
```

The order is the design. Each step is placed so the cheapest and most certain refusals happen before
anything expensive, anything irreversible, and above all before a credential is resolved.

**A `ToolRequest` is a typed tool key plus typed arguments plus a typed identity. There is nowhere in
it to put prose.** By the time a request exists the model, if there ever was one, has finished. That
is the line the whole control plane rests on and it is why a prompt injection cannot escalate
anything: enforcement reads structure, not language, and cannot be talked to.

**What the gateway records:** `tool_calls` is opened on entry with `status = started` and completed
on exit. A row left reading `started` therefore means the process died mid-call, which is a fact
worth being able to see. A recorder that wrote once at the end would record nothing at all for
exactly the failures hardest to diagnose.

⚠ **Every invocation gets a row, including the ones that never reach a tool.** An unknown key,
invalid arguments and a permission refusal all leave one. This deviates from `docs/48` D.3, which
said an unknown key would produce only an audit line. The brief was right and the architecture was
wrong: a control plane that records only what succeeded cannot tell you that something spent a week
being refused, and an unknown key is either a bug or a probe.

---

## C. ToolDescriptor

Every tool now declares its contract with the control plane on the class, not in configuration,
because these are statements about what the code does. A risk tier that could be edited in a config
file would be a risk tier that could be edited to make an expensive action look like a cheap one.

`key · version · title · purpose · riskTier · permission · auditAction · scope · readWrite ·
agentExecutable · supportedBusinesses · supportedStores · policyClass · credentials · idempotent ·
idempotencyKeyFrom · timeoutSeconds · retry · costHint · redact · outputSchema · replayWithinSeconds`

`inputSchema()` stays on the `Tool` interface where it already was and where the scheduler already
reads it. `outputSchema` lives on the descriptor because nothing consumed it before.

The six registered tools, as deployed:

| tool | ver | tier | r/w | agent | permission |
| --- | --- | --- | --- | --- | --- |
| `noop` | 1.0.0 | R0 | read | never | `hq.tools.diagnostics` |
| `test.fail` | 1.0.0 | R0 | read | never | `hq.tools.diagnostics` |
| `erp.poll_overview` | 1.0.0 | **R3** | write | never | `hq.tools.erp.read` |
| `erp.poll_low_stock` | 1.0.0 | **R3** | write | never | `hq.tools.erp.read` |
| `commerce.reconcile_facts` | 1.0.0 | **R3** | write | never | `hq.tools.commerce.read` |
| `commerce.check_fact_integrity` | 1.0.0 | R1 | read | never | `hq.tools.facts.read` |

**The classification rule: the tier is the highest tier of any effect the tool has.** The ERP polls
read the ERP (R2) and then write a `facts_erp_snapshots` row, which is HQ state (R3), so they are
R3. Calling them R2 because "they are polls" would describe the intention rather than the effect.

Recording the call itself, in `tool_calls`, `connector_calls` and `audit_logs`, is **not** counted as
an effect. Every call makes those, and counting them would flatten the whole scale to one value.
That is why `commerce.check_fact_integrity`, which writes an audit row, is R1.

---

## D. Registry and the two switches

```
 registered in config('hq.tools')   AND   not disabled in the `tools` table
```

They compose by AND and the configuration list always wins. The important asymmetry: **the absence
of a database row means ENABLED.** The table can only ever record a decision to turn something off.

That single choice buys three things:

- "A runtime flag must never create a capability absent from the deployed code" becomes true by
  construction rather than by a check somebody has to remember.
- There is no startup-ordering problem. A newly deployed tool works immediately; the sync is never
  load-bearing.
- A `tools` table lost to a bad restore fails **open to exactly the set of capabilities the deployed
  code contains**, which is a set a human already reviewed, rather than closed and mysterious.

`hq:tools:sync` materialises rows for the registry screen and reports orphans. It never deletes,
disables or re-enables: a row saying "disabled on 8 September because it double-charged" is worth
more than the tidiness of removing it, and if the class comes back the disablement should come back
with it.

**The kill switch** is `/tools` (permission `hq.tools.view` to see, `hq.tools.manage` to switch) and
`hq:tools:toggle` for when the UI is what is broken. Disabling requires a reason and is audited in
both directions. Disabled means unavailable to every caller, with no per-caller exemption, because a
kill switch with an exemption is not a kill switch.

---

## E. Identity

Four kinds, kept apart because most authorization accidents come from letting two of them blur:

| Identity | What it is | What it grants |
| --- | --- | --- |
| Human | a person with a password and TOTP | the ceiling on everything done in their name |
| Agent | configuration: prompt, tools, tier, scope | never more than its enabling human |
| Service | the scheduler, the event drain, a future API token | a fixed narrow list |
| **Credential** | who HQ **is** at an external provider | **nothing inside HQ** |

**A credential is an identity at a provider, never an authorization in HQ.** Holding the LaLaDia
admin token does not mean a caller may cancel an order; it means that *if* the caller is permitted
to, this is the token the connector will use. Systems that forget this end up read-only by design
and read-write in practice, because the token could always do more than the design intended.
`ActingIdentity::holds()` returns false for a credential in every branch, and that is a test.

**The intersection, in one place, with no fallback:** tool permission AND the enabling human's
permissions AND the agent's allowed tools AND the agent being active AND business scope AND policy.
Every term narrows; nothing widens; there is no wildcard identity and no break-glass path, because a
break-glass path is the thing an attacker aims at. If Joy needs something an agent cannot do, he
does it as himself, and that is a feature.

The `system` identity is the four permissions in `config('hq.gateway.system_permissions')` and
nothing else. It is deliberately narrow enough that the five Phase 1 poll and reconcile jobs fit
inside it and anything wider must name an owning human.

`agents` exists and holds **zero rows**. M1 runs no agent. Its defaults are the safe direction:
`status = paused`, `max_autonomy_level = a0`, so an agent row created by accident does nothing.

---

## F. Business scope

`user_business_scopes(user_id, business_id, access)`, read only through `App\Support\Authorization\
BusinessScope`.

⚠ **No rows means group-wide.** Joy has no rows, so nothing about his access changed when this
shipped. A user gains a restriction by gaining rows, never by the absence of them, so a failure to
seed something cannot lock an operator out of his own business.

Deliberately **not** spatie's teams feature: that would re-key every permission check in the
application for a requirement that today has one user (V.11). An explicit narrow mechanism now beats
a large refactor of a working authorization system for an imagined case.

**Known limitation:** an `hq`-scoped tool that reads across businesses must call `BusinessScope::all()`
itself. The gateway enforces scope on the business a call *names*; it cannot know what a tool will
read. No such tool exists yet, and the first one must do this.

---

## G. Policy

⚠⚠ **`PolicyGate` is not the M2 policy engine, and the difference matters.** It has no `policies`
table, no per-business guardrail rules, no value thresholds, no budgets, no quiet hours and no
per-tool policy classes. Those arrive in M2 with their own table-driven tests. What is here is the
part it would be unsafe to leave out of a gateway that already executes tools, written in the order
and the vocabulary the full engine will keep:

1. Kill switches, including the global `ai.freeze` flag that stops every tool without a deploy.
2. `agent_executable = false`, checked before anything that could be relaxed, because no approval
   and no configuration may ever lift it.
3. Environment guard: a write tool is refused in `staging`. HQ has no staging today, so this is a
   guard put in place before it is needed.
4. Risk tier baseline: R0 to R3 allow, R4 and R5 require approval.
5. **Default: deny.** Not `require_approval`, which is what `docs/12` said. A call nobody classified
   must not reach a human as a plausible-looking approval card, because a human approving an
   unclassified action is exactly the failure the approval system exists to prevent.

**Decision V.1 in force:** every R4 and R5 call requires human approval with no value threshold.
Since the Approval Center is M3, such a call is **refused outright**, with a reason that says so,
rather than parked in a queue nobody can act on. Fail closed, and be honest about why.

A completeness test asserts every registered tool resolves to a decision, so a tool nobody
classified fails CI rather than production.

---

## H. Redaction, and the trap that was fixed

Two opposite failures are guarded at once, and it is easy to fix either by causing the other: a
secret reaching storage, and redaction so aggressive that the record no longer says what happened.

**The trap `docs/48` N.2 identified, now fixed.** `Auditor::REDACT` contained the literal key
`payload`. On an `integration_credentials` row that key holds the encrypted blob; on a
`scheduled_jobs` row it holds the tool's arguments. Auditing a job therefore wrote
`{"payload":"[redacted]"}`, recording that something happened while destroying every fact about
what. It now masks a **scalar** `payload` and **recurses into an array** one, keeping the credential
protection and giving back the evidence.

Three layers now: the tool's declared `redact` paths (dot paths, `*` for list elements), a leaf key
deny list as a backstop, and the existing Monolog processor.

The gateway also collects the **values** it masked from the caller's arguments and scrubs them from
any error text before it is persisted, because a connector that interpolates the key it was given
into its own exception message would otherwise write it straight into `tool_calls.error`.

Proven both ways by test: `store_slug`, `hours` and the output stay readable; `api_key` and a
declared `nested_token` never appear in `tool_calls`, `audit_logs` or an error string.

---

## I. Credential boundary

Resolution is step 8, below every deterministic check. `CredentialResolver` answers "does the
credential this tool declared exist, is it active, is it unexpired" and records **which credential
id** was involved. It has no method that returns a value, and `CredentialResolution` has no field
that could hold one, so a future change cannot carry a secret into `tool_calls` by adding a line to
a constructor.

A missing or revoked credential now fails at the gateway with a named reason, before a connector
produces a confusing 401.

⚠ **Known limitation, deliberately not half-done.** The connectors still read their own secrets, as
they always have: `CommerceRegistry` and `ErpRegistry` each look up `integration_credentials` at call
time. M1 reuses that infrastructure rather than inventing a second store. The consequence is that the
gateway can scrub a sensitive *argument* from an error message but not a *credential the connector
fetched for itself*, because it has never seen that value. **Closing that gap is the connector-side
refactor in M2.** Proven today by test: the real ERP token does not appear in `tool_calls` or
`audit_logs` when `erp.poll_overview` runs against the captured production fixture.

---

## J. Idempotency

ADR-011 unchanged: **at-least-once execution, idempotent tools, reconciliation when provider state is
uncertain.** Nothing here claims exactly-once.

The distinction that makes it correct: **idempotency protects against a duplicate DELIVERY of one
logical request, not against two genuine requests that happen to look alike.** Two operator clicks
on the same button are two requests and both should run; one queue message delivered twice is one
request and must run once.

So a derived key includes the caller's run identity, and only replays inside the tool's declared
`replayWithinSeconds` window (zero for almost everything). A caller that *knows* two invocations are
one operation supplies an explicit key, and an explicit key always replays.

The uncertain path calls `reconcile()` before anything is attempted again. If the tool recovers the
effect, HQ adopts it and executes nothing. If it cannot and the tool is not idempotent, the call is
refused and a human decides.

**The replay test counts executions rather than comparing output**, because identical output proves
nothing: a deterministic tool that simply ran twice would produce it.

---

## K. Failure vocabulary

`Refused · Unavailable · Uncertain · ValidationFailed · Unsupported · Internal`, mapped by
`FailureClassifier` from the five exceptions that already exist in `App\Commerce\Exceptions`. They
stay where they are: they are used by connectors proven against three production systems, and moving
them would be churn.

The default is **Internal, not Refused**. An exception nobody classified is a defect in HQ and should
look like one, rather than being quietly recorded as the far side saying no. Only `Unavailable` is
retryable.

---

## L. Correlation

One id from the caller through `Context::scope` into the tool, its log lines, `tool_calls` and the
audit row. **Never regenerated at a layer boundary**, because a new id at each layer is the same as
having none. Verified on production: the `noop` tool's own log line carries
`"correlation_id":"2e91da67-..."`, matching `tool_calls` row #1.

---

## M. Scheduler: what M1 deliberately did NOT do

**`RunScheduledJob` is untouched. It still calls `$tool->execute()` directly, exactly as it does in
production.** The six live jobs run through the same code they ran through yesterday, which is why
this deploy could not change their behaviour.

What M1 adds is the seam: `ToolRequest::forScheduledJob(job, run, owner)`, tested against a real job
and a real run. It resolves the acting identity to the job's owning human, falls back to the narrow
`system` identity for an ownerless job, and uses the run's own correlation id as the idempotency key,
which is what makes at-least-once delivery harmless.

**Migrating the scheduler onto the gateway is M2, and its acceptance test is that the six live jobs
produce byte-identical output through it.**

---

## N. Tests

**160 passed, 855 assertions**, up from 105 / 560. Five new files plus three test doubles.

| Area | What is proven |
| --- | --- |
| Registry | unregistered key refused and still recorded; runtime-disabled tool refused for every caller; a runtime row cannot invent a capability |
| Schema | malformed arguments stop at the gateway with no policy result and no execution; an unaccepted argument is refused; every registered tool's schema uses only keywords the validator understands |
| Permission | missing permission denied; system identity limited to its configured list; agent intersection in all four failing directions; a credential identity authorises nothing; `ToolContext` has no field that could carry a secret |
| Scope | no rows means group-wide; a scoped user is refused another business; a business-scoped tool naming no business is refused |
| Policy | R4 refused with V.1's reason; the freeze flag stops everything; every tool classifies |
| Redaction | secret absent from `tool_calls`, `audit_logs` and error text; ordinary arguments survive; declared and wildcard paths masked; the `payload` scalar-versus-array fix |
| Audit | append-only enforced by the database for UPDATE and DELETE |
| Idempotency | replay proven by execution count; two genuine calls both run; uncertain then reconcile; canonical key ordering |
| Credentials | missing credential named before execution; real ERP token absent from every record |
| Correlation | one id survives request to tool call |
| Scheduler seam | a valid request built from a real job; ownerless job falls back to `system` |
| Commitments | **no registered tool is R4 or above; no registered tool is agent-executable** |

Two of those are commitments expressed as tests rather than as promises in a document.

**Two failures CI found were test-side, and both are worth recording** because they would have
recurred. Pest reads a bare interface name in `toThrow()` as a message substring, so
`toThrow(Throwable::class)` was passing for the wrong reason. And a raised exception aborts the
surrounding transaction, so the second refused write was failing with "current transaction is
aborted" rather than reaching the trigger: it was testing Postgres error recovery instead of the
guarantee it meant to test. Each refused write now runs in its own savepoint.

---

## O. Production verification, 8 September 2026

Release `c341e93`, deployed 04:31 UTC after a verified pre-deploy dump.

**Backup:** `hq-20260908-042946.dump`, 262,613 bytes, `pg_restore --list` reads **38 tables of
data**. Rollback target `184d133`.

**Migration SQL, inspected with `--pretend` before it ran:** three `CREATE TABLE`, and on
`tool_calls` only `ADD COLUMN` (every one nullable or defaulted) plus three indexes. No drop, no
column alteration, no data touched.

| Check | Result |
| --- | --- |
| Containers | 7/7 up, app / horizon / db / redis / web healthy |
| `/api/health` | `status: ok`; db 8 ms, redis 0 ms, queue 1 supervisor, scheduler tick 55 s |
| Migrations | both ran, batch 4 |
| Permissions | 32 total, 9 new tool permissions; owner and operator 32, brand_manager 11 |
| `user_business_scopes` | **0 rows** - every account stays group-wide |
| `agents` | **0 rows** - M1 runs no agent |
| `tools` | 6 rows, all enabled |
| ERP probe | `ok:true`, business 4, 136 ms |
| Commerce probe | LaLaDia and Bionic, public/assistant/admin all **200** |
| Failed queue jobs | none |
| Errors in logs | none |
| Secret scan of 30 min of logs | **0 matches** |
| `/tools` anonymous | 302 to `/login` (not a 500); page present in the built manifest |

**Every Phase 1 job run once through its production path, after the deploy:**

| job | tool | result |
| --- | --- | --- |
| 4 | `erp.poll_overview` | succeeded, `as_of 2026-09-08T10:32:16+06:00` |
| 5 | `erp.poll_low_stock` | succeeded, 0 items |
| 2 | `commerce.reconcile_facts` laladia-com | succeeded, checked 14, **inserted 0** |
| 6 | `commerce.reconcile_facts` bionic-garden | succeeded, checked 1, **inserted 0** |
| 3 | `commerce.check_fact_integrity` | succeeded, 15 checked, 15 ok, **0 discrepancies** |

`inserted: 0` on both shops is the proof that **no shop data changed and no external write
occurred**. Every shop call was a read.

**The gateway itself, exercised on production:**

```
allowed  : succeeded    policy=allow    tool_calls #1
invalid  : invalid      "[message] must be at most 500 characters."   (no policy result recorded)
unknown  : unavailable  tool_calls #3
```

The middle row is the interesting one: no policy result was recorded, which is the evidence that
validation runs before policy rather than beside it.

---

## P. Rollback

```
cd /opt/bordeguna-hq && ./deploy/rollback.sh 184d133
```

The two migrations have working `down()` methods, verified by CI's rollback step. Nothing needs to be
un-migrated to roll back the code: every schema change is additive, so `184d133` runs correctly
against the M1 schema. Roll the code back first, and drop the tables only if the rollback is meant
to be permanent.

---

## Q. Known limitations

1. **The connector-side credential refactor is not done** (section I). The gateway pre-flights and
   records credentials; connectors still fetch their own. Its consequence is bounded and stated.
2. **`PolicyGate` is not the policy engine** (section G). No thresholds, budgets, guardrail rules or
   per-tool policy classes.
3. **The scheduler does not use the gateway yet** (section M). Deliberate; it is M2's acceptance
   test.
4. **`App\Scheduling\Tools` is the wrong namespace now** that tools serve more than the scheduler.
   Moving it means editing `config('hq.tools')`, which the live scheduler reads to find these
   classes, so it moves in M2 in the same commit that migrates the scheduler and is already testing
   that path.
5. **An `hq`-scoped tool that reads across businesses must apply `BusinessScope` itself** (section F).
6. **The environment guard is inert** until a staging environment exists (section G).
7. **The registry screen is minimal.** It lists, and it switches. Schemas, per-call history and
   filtering are M2.
8. `apps/hq/.github/workflows/` still holds the Laravel starter kit's SQLite workflow. GitHub Actions
   only reads the repository root, so it never runs; it is dead weight, not a second CI.

---

## R. The M2 boundary, explicitly

M2 is **Tool Gateway migration, the Policy Engine and the Permission model completed**. It is not
started, and nothing in M1 assumes it.

**M2 will:**
- Route `RunScheduledJob` through the gateway. **Acceptance: the six live production jobs produce
  byte-identical output.** A feature flag returns them to direct execution if they do not.
- Build the real policy engine: the `policies` table, versioned rows, per-business guardrail rules,
  value thresholds, budgets, rate limits, per-tool policy classes that may only make a tier stricter.
- Move `App\Scheduling\Tools` to a namespace that no longer says Scheduling.
- Refactor the connectors so the gateway hands the secret down, closing limitation 1.
- Complete the registry screen.

**M2 will not** build the Approval Center (M3), connect a model provider (M4), create agents (M5),
build Ask HQ or natural-language scheduling (M6), or generate a briefing (M7).

**Nothing at R4 or R5 is enabled, and no tool is agent-executable, until the milestone that has a
human in the loop for it exists.** Both are enforced by a test, not by a promise.

---

## S. Backlog carried forward, unchanged

Recorded in `docs/48` W and not touched by M1: Joy should delete `~/hq-bionic-credentials.txt`; ERP
snapshot freshness monitoring (M7); the `erp-counter` store's dead base_url, to be fixed in code and
never by SQL; the shop admin order service dropping `district` and `thana`; a direct Bionic
duplicate-delivery demonstration; and the six inert `dead` LaLaDia events, deliberately left as they
are.
