# 50 - Phase 2 M2: the scheduler through the gateway, and the real policy engine (8 September 2026)

**Status: built, tested, deployed, verified on production, and running in gateway mode.**

M2 of the Phase 2 Command Center (`docs/48`), following M1 (`docs/49`). It makes the Tool Gateway
the only path in fact as well as in design, replaces M1's minimal gate with the real policy engine,
closes M1's credential-boundary limitation, and moves the tool namespace.

| | |
| --- | --- |
| Starting release | **`c341e93`** (M1) |
| Ending release | **`16919f6`** (PR #3, merged) |
| Rollback target | **`c341e93`** |
| CI | **204 passed / 1041 assertions**, from 160 / 855 at M1 |
| Migrations | 1, additive only, reversible |
| Scheduler mode in production | **gateway** (feature flag `scheduler.gateway` on) |
| Shop or ERP code changed | **none** |
| Model provider connected | **none** |
| Anything enabled at R4 or above | **none** |

---

## A. The defect found before any code was written

The brief required verifying production truth first. That verification found a defect that would
have taken out most of the nightly work on its first run.

**Every live scheduled job names its target in its PAYLOAD and leaves the request's business and
store null.** Read off production:

| job | tool | payload | business_id |
| --- | --- | --- | --- |
| 2 | `commerce.reconcile_facts` | `{"hours":168,"store_slug":"laladia-com"}` | null |
| 3 | `commerce.check_fact_integrity` | `{"hours":168}` | null |
| 4 | `erp.poll_overview` | `{"business_slug":"bor-de-guna"}` | null |
| 5 | `erp.poll_low_stock` | `{"business_slug":"bor-de-guna"}` | null |
| 6 | `commerce.reconcile_facts` | `{"hours":168,"store_slug":"bionic-garden"}` | null |

That is how they were written in Phase 1, long before a gateway existed. M1's scope check looked
only at the request:

```php
if ($descriptor->requiresBusinessContext() && $request->businessId === null && $request->storeId === null) {
    return "... the call named neither a business nor a store.";
}
```

`erp.poll_overview` and `erp.poll_low_stock` are `ToolScope::Business`; `commerce.reconcile_facts` is
`ToolScope::Store`. **Routing the scheduler through the gateway unchanged would have DENIED four of
the six production jobs**, silently and nightly.

**The fix is not to loosen the check.** A scoped tool must still name a business. What changed is the
recognition that **naming it in `business_slug` IS naming it**. Tools now declare where their target
comes from, exactly as they already declare where a credential's account reference comes from:

```php
scopeFrom: 'business:args.business_slug',   // the ERP polls
scopeFrom: 'store:args.store_slug',         // the shop reconcile
```

A new `ScopeResolver` reads the request context first and the declared argument path second, resolves
a slug against a real row (an unknown slug refuses rather than widening to "all businesses"), and a
store implies its business.

**A useful side effect.** `tool_calls.business_id` and `store_id` are now filled for these tools,
which would otherwise have recorded null for every ERP poll and every reconcile forever. Verified on
production below.

---

## B. Scheduler migration (ADR-016)

`RunScheduledJob` now has two paths chosen by the feature flag `scheduler.gateway`:

| mode | behaviour |
| --- | --- |
| **gateway** | The scheduler is an ordinary caller. Registry, schema, scope, permission, policy, idempotency, credential resolution, `tool_calls` and audit all happen in the gateway |
| **direct** | The Phase 1 path, kept as the immediate rollback switch |

**What stayed with the scheduler**, in both modes: creating the run, the attempt log, backoff,
dead-lettering, missed-job behaviour and job state. **Nothing the gateway owns is duplicated**, because
two implementations of one rule eventually disagree. The reconcile-on-retry that direct mode did by
hand now lives in the gateway, where every caller inherits it.

A gateway refusal becomes a failed run: the job did not do its work, and the reason is on the record
in both `job_runs` and `tool_calls`.

### B.1 ⚠ The rollback switch had quietly stopped working

The same milestone moved credential resolution into the gateway, so a connector can no longer look
one up for itself. Direct mode did not open a credential scope, which meant **four of the six live
jobs would have failed on the rollback path**. A rollback that does not work is worse than no
rollback, because it is only discovered at the moment it is needed.

Direct mode now opens the credentials the tool declared, and nothing else. It is the one piece of
gateway-adjacent work it does, it exists only because the two changes landed in one milestone, and it
disappears when direct mode does.

---

## C. Equivalence, measured on production

Each job was run on the direct path **after the deployment**, then the flag was turned on and each was
run again. Only these fields were normalised away, because a re-run necessarily changes them:
`as_of` (the ERP stamps its own clock), `since` (derived from now minus the window), `executed_at`,
`idempotency_key`, `attempt`.

| job | tool | direct | gateway | verdict |
| --- | --- | --- | --- | --- |
| 4 | `erp.poll_overview` | `kind=overview, items=null, business=bor-de-guna` | identical | ✅ |
| 5 | `erp.poll_low_stock` | `kind=low_stock, items=0, business=bor-de-guna` | identical | ✅ |
| 2 | `commerce.reconcile_facts` (LaLaDia) | `store=laladia-com, checked=14, inserted=0, hours=168` | identical | ✅ |
| 6 | `commerce.reconcile_facts` (Bionic) | `store=bionic-garden, checked=1, inserted=0, hours=168` | identical | ✅ |
| 3 | `commerce.check_fact_integrity` | `checked=15, ok=15, without_lines=10, discrepancies=0, findings=[], store=all, hours=168` | identical | ✅ |
| 1 | `noop` | `message="phase-0 acceptance"` | identical | ✅ |

**Every substantive field matches.** `inserted: 0` on both shops on both paths is the safety
assertion that matters: no shop data changed and no external write occurred.

### C.1 The tool calls the gateway recorded

```
erp.poll_overview             succeeded  tier=r3 policy=allow action=auto biz=1 store=-  actor=system
erp.poll_low_stock            succeeded  tier=r3 policy=allow action=auto biz=1 store=-  actor=system
commerce.reconcile_facts      succeeded  tier=r3 policy=allow action=auto biz=2 store=1  actor=system
commerce.reconcile_facts      succeeded  tier=r3 policy=allow action=auto biz=3 store=2  actor=system
commerce.check_fact_integrity succeeded  tier=r1 policy=allow action=auto biz=-  store=-  actor=system
noop                          succeeded  tier=r0 policy=allow action=auto biz=-  store=-  actor=joy@…
```

Three things worth reading twice. **`biz` and `store` are populated**, resolved from the payload,
which is section A working on live data. **The ownerless jobs run as `system`**, the narrow fixed
permission list, while job 1 runs as its owning human. And **direct mode wrote no rows at all**:
`tool_calls` stayed at 3 across the whole direct-path baseline, which is the cleanest possible proof
that direct mode does not touch the gateway.

---

## D. The policy engine

M1's `PolicyGate` is gone, replaced by `PolicyEngine`. Deterministic, model-free, versioned,
reproducible. It reads a tool key, typed arguments and an identity. **It never reads prose**, which
remains the whole prompt-injection defence.

### D.1 Evaluation order, unchanged in shape from the design

1. **Kill switches**: global freeze flag, paused agent, inactive business.
2. **`agent_executable = false`** when the caller is an agent. Before anything that could be relaxed,
   because no approval and no configuration may ever lift it.
3. **Environment guard**: a write tool outside production.
4. **Permission intersection.** Moved out of the gateway and into the engine, so it is asked once
   rather than in two places that could one day disagree.
5. **Risk tier baseline**: R0 to R3 allow, R4 and R5 require approval (decision V.1, no threshold).
6. **The tool's own policy class**, which may only make it **stricter**. A class that tries to loosen
   is ignored and the attempt is logged; a class that throws denies.
7. **Guardrail rules** from the versioned `policies` table.
8. **Budgets and rate limits.**
9. **Default: deny.**

### D.2 Versioning and reproducibility

```
policies(id, key, version, business_id?, tool_key?, rules jsonb, note,
         active_from, active_to?, created_by, timestamps)
```

**Selection is by specificity, most specific first, and the first match wins:**
`tool + business` > `tool` > `business` > `global`. No merging: "which rule decided this" must have a
one-line answer.

**A change never rewrites history.** `supersede()` closes the current version and opens the next in
one transaction; the old row keeps its rules verbatim. `tool_calls.policy_id` and `policy_version`
point at the exact row that decided, so a past decision still resolves to rules that read as they did.
There is deliberately **no method that updates a policy's rules in place**.

Tested: an old call still points at version 1 and version 1 still says what it said, while the next
call is governed by version 2; a closed policy is not selected; a policy whose window has not opened
is not selected.

### D.3 Guardrails

Typed PHP reading data-driven values, as ADR-004 decided. No rule language, no policy sidecar, no
configuration interpreter. Recognised keys: `deny`/`deny_reason`, `require_approval`/`reason`,
`dry_run_only`, `relax_to`/`relax_reason`, `max_calls_per_hour`, `max_calls_per_day`, `quiet_hours`,
`arg_max`, `arg_min`, `arg_allowed`.

⚠ **An unrecognised rule key is REFUSED, not ignored.** A rule that silently does nothing is worse
than no rule, because somebody believes it is protecting them. A typo like `maximum_calls_per_hour`
denies the call and names the key.

**Loosening has exactly one narrow path**: an explicit `relax_to` with a `relax_reason`, audited, and
**never for R5**. An unexplained relaxation is not a relaxation. Material and irreversible actions
need a code change and a review, which is a different kind of decision made by a different mechanism.

### D.4 Budgets and rate limits

Per-policy call rate (hour and day), per-agent daily cost and call caps, and the AI spend budget.
Counters live in the cache and **fail open** for rate limiting, which is the correct direction: the
policy engine is the thing that refuses, and a cache outage must not become a business outage.

A refused call **does not consume quota**, or a refusal would tighten the limit for the next
legitimate caller. Tested.

**Decision V.8 honoured literally**: the AI spend cap is *soft advisory*. Notify at 80 per cent,
downgrade the tier at 100 per cent, and **never refuse work because of it**. It reads zero today
because no model has been called.

---

## E. The credential boundary, closed

`CredentialVault` is now **the only place in the application that decrypts a credential**. The gateway
resolves what a tool declared, opens it for the length of one call, and clears it in a `finally` so an
exception cannot leave a secret in memory.

| Before M2 | After M2 |
| --- | --- |
| `ErpRegistry` and `CommerceRegistry` each queried and decrypted their own credential | Both ask the vault; neither can resolve one |
| The gateway could scrub a sensitive **argument** from an error but not a **credential it never saw** | The gateway knows every secret in play for a call |
| A connector could silently widen its own access by looking up a different credential | There is no lookup to widen |

A connector asking for a credential nobody opened gets a clear refusal that names the gateway, rather
than a silent second lookup.

**The structural guarantee is a test**: it walks `app/` and fails if any class outside a short
allowlist reads a credential payload. A future developer cannot quietly reintroduce a hidden lookup.

**Diagnostic callers open a scope explicitly.** `hq:erp:probe`, `hq:erp:inspect`, `hq:commerce:probe`
and `hq:facts:reconcile` talk to a connector without a tool, so they open the scope themselves in
plain sight rather than through a quiet fallback. The webhook controller does the same for the
inbound signature secret, because an exception carved out there would be the first step back towards
secrets being read wherever they are convenient.

**Credential is still not authorization.** A token present, active and perfectly capable of reading
the ERP does not help a caller who lacks the permission: the gateway decided several steps before a
credential was considered. Tested, with the assertion that nothing was sent.

---

## F. The namespace move

`App\Scheduling\Tools\*` → `App\Tools\*` (`Diagnostics/`, `Erp/`, `Commerce/`).

The risk was never the classes. It is `config('hq.tools')`, which the **live scheduler** reads to find
them: a stale class reference there is an outage at 02:00 rather than a failure in CI. Four tests
guard it: every configured class exists and implements the interface; the six production keys are
unchanged; a job can be created for each live key; and no reference to the old namespace survives
anywhere in `app`, `config`, `database` or `routes`.

⚠ **The keys did not move.** A tool key is an API identity that five `scheduled_jobs` rows reference,
not a display label.

---

## G. The tool registry screen

Completed: input and output schemas, the governing policy row and its version, supported businesses
and stores, declared credentials, scope and `scopeFrom`, timeout, replay window, idempotency, 24-hour
call and success counts, the last five calls with their status and policy result, filtering by text
and by enabled state, and the audited runtime kill switch.

Disabled still means unavailable to **every** caller, with no exemption for the scheduler. Proven by
test: a disabled tool fails a scheduled run in gateway mode and executes nothing.

---

## H. Tests

**204 passed, 1041 assertions**, up from 160 / 855. Four new files and one new test double.

| Area | What is proven |
| --- | --- |
| Scheduler | direct by default; gateway when flagged; the flag switches back; equivalence on the ERP polls, the integrity check and a reconcile; scope resolved from the payload; a scoped job with an unresolvable target refused; ownerless job runs as `system`; the kill switch stops a scheduled job; retry, history and dead-lettering still belong to the scheduler |
| Policy | tier baselines; R4 refused until M3 with no fake approval record; every tool classified; guardrail deny, approval and argument limits; an unknown rule key refused; R5 never relaxed; an unexplained relaxation ignored; rate limit reached and a refusal not charged; specificity ordering across all four levels; the deciding version recorded; supersede without rewriting history; closed and not-yet-open policies ignored; freeze flag; inactive business; permission denial from inside the engine; agent-executable refusal |
| Credentials | a connector refused when nothing is open; given the credential when the gateway opened it; the scope cleared on success and on exception; a real token absent from every record; missing credential named before execution; **the vault is the only decryptor** (filesystem scan); a credential cannot authorise an operation; only declared credentials opened |
| Namespace | configured classes exist and implement the interface; production keys unchanged; a job creatable for each live key; no stale references |

Two commitments remain tests rather than promises: **no registered tool is R4 or above, and no
registered tool is agent-executable.**

### H.1 What CI found, and what it was

Four rounds. Every failure was real and worth recording:

1. **The diagnostics tools referred to `Tool`, `ToolContext` and `ToolResult` unqualified**, which
   resolved while they shared a namespace with the interface and stopped resolving in
   `App\Tools\Diagnostics`. The container could not build the registry, so 90 tests failed at once.
2. **`CredentialVault` was not a singleton.** It holds the open scope in instance state, so the
   caller's vault and the connector's vault were different objects and every scope was invisible.
   One binding; most of the failing suites.
3. **The rollback path was broken** (section B.1). Found by the equivalence test rather than in
   production, which is exactly what the equivalence test is for.
4. **Three test-side corrections**: the queue is `sync` in tests, so a failed run's delayed retry
   executed immediately and recursively and every failure arrived at `dead` rather than the state it
   first reached; the scope refusal message changed with the ScopeResolver; and a jsonb round trip
   does not promise key order, so comparing a policy's rules by identity tested Postgres rather than
   the guarantee being made.

One test was reported **risky** and was fixed rather than shrugged at: it looped over `scheduled_jobs`
to prove every referenced key resolves, and a fresh test database has none, so **the loop body never
ran and the test asserted nothing while looking like it asserted everything.**

---

## I. Production verification, 8 September 2026

| Check | Result |
| --- | --- |
| Pre-deploy backup | `hq-20260908-060349.dump`, 293,725 bytes, `pg_restore --list` reads **41 tables of data** |
| Migration SQL, inspected with `--pretend` before it ran | One `CREATE TABLE`, three nullable `ADD COLUMN`, two indexes, three constraints. No drop, no alteration, no data touched |
| Release | `c341e93` → **`16919f6`** |
| Containers | 7 of 7 healthy |
| Health | ok; database 9 ms, cache 0 ms, 1 supervisor, tick 10 s |
| Migration applied | batch 5 |
| Flag on deploy | **absent, so direct mode**, which is the safe default the brief required |
| Direct-path baseline after deploy | all five jobs succeeded; `tool_calls` unchanged at 3 |
| Gateway-path run | all six jobs succeeded; six new `tool_calls` rows |
| Equivalence | every substantive field identical (section C) |
| `facts_orders` | 16, unchanged |
| customers / identities | 14 / 57, unchanged |
| events | 23, unchanged |
| ERP snapshots | 2, unchanged (current-state, so it never grows) |
| Failed job runs in the last hour | **0** |
| Duplicate logical slots | **0** |
| Policy denials recorded | 0 (nothing was refused; nothing should have been) |
| Failed queue jobs | none |
| Errors in logs | none |
| Secret scan of 20 minutes of logs | **0 matches** |
| Rollback rehearsed | flag off → direct run succeeded, `tool_calls` unchanged → flag on again |
| `/tools` anonymous | 302 to login, not a 500 |
| Registry | 6 tools, all enabled, none agent-executable, none above R3 |

**No shop order was created, no ERP write occurred, no customer record changed, no credential
appeared anywhere, and no policy was bypassed.**

---

## J. Rollback

Two independent levers, in increasing order of weight:

1. **The feature flag.** Turn `scheduler.gateway` off and the scheduler returns to the Phase 1 path
   immediately, with no deployment. **Rehearsed on production today**, in both directions.
2. **The release.** `./deploy/rollback.sh c341e93`. Code only: the migration is additive, so
   `c341e93` runs correctly against the M2 schema. Its `down()` works if the rollback is permanent.

---

## K. Known limitations

1. **No approval mechanism exists**, so an R4 or R5 call is refused outright with a reason that says
   so. Correct for now: fail closed and be honest. **M3.**
2. **No model has been called.** `ai_runs` is empty, the agent budget path reads zero, and the AI
   spend cap has nothing to measure. **M4.**
3. **Direct mode is not quite the Phase 1 path any more** (section B.1). It opens the credentials the
   tool declared. Documented rather than hidden, and it disappears with direct mode.
4. **Direct mode remains available.** It is the rollback switch. Removing it is a separately approved
   step, not a tidy-up.
5. **`quiet_hours` uses the configured HQ timezone**, not a per-business one. Businesses share one
   timezone today; when that stops being true this needs the business's own.
6. **Rate-limit counters live in the cache and fail open.** Deliberate: the policy engine refuses, and
   a cache outage must not become a business outage. A hard limit would need a durable counter.
7. **No policy rows exist in production.** Everything runs on tier baselines, which is correct while
   nothing needs a guardrail. The engine is exercised by tests rather than by live rules.
8. **An hq-scoped tool that reads across businesses must apply `BusinessScope` itself.** Unchanged
   from M1; no such tool exists and no user is restricted.
9. **The environment guard is still inert** because no staging environment exists.
10. **Econic's store row is still marked active with no adapter**, and the `erp-counter` row still
    holds a decommissioned hostname. Both inert, both unchanged by M2.

---

## L. The M3 boundary, explicitly

M3 is the **Approval Centre**. It is not started, and nothing in M2 assumes it.

**M3 will**: build the approvals table and lifecycle; bind an approval to a canonical payload hash and
a tool version; make approvals single use; support approve, reject, approve-with-edit, delegate,
cancel and expire; add deadlines and escalation; write a `decision_record` on every decision; and
build the mobile-first approval screen. It will then let policy's `require_approval` outcome **park**
a call rather than refuse it, which is the one behaviour M2 deliberately leaves incomplete.

**M3 will not**: connect a model provider (M4), create agents (M5), build Ask HQ or natural-language
scheduling (M6), or generate a briefing (M7).

**Nothing at R4 or R5 is enabled and no tool is agent-executable**, and both remain enforced by a
test rather than by a promise.

---

## M. ADR status changes

- **ADR-016** (one gateway for every caller): the scheduler migration is done and verified on
  production, so its outstanding half is complete.
- **ADR-004** (typed tool gateway with a deterministic policy engine in front of every action): the
  policy engine now exists as described, so the decision is fully realised rather than partly.
- ADR-017 and ADR-011 are unchanged and continue to hold.
- ADR-018 and ADR-019 remain **proposed**, for M3 and M4 respectively.
