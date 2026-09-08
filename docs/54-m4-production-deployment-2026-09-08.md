# 54 - M4 production deployment record (8 September 2026)

**Status: deployed and verified. The live safety gate is NOT run; it waits on the Anthropic API key
(Q4), which is Joy's to install through the HQ UI.**

This is the **production evidence** for M4. What M4 is, and the defects found while building it, are
in `docs/52` and are not restated or revised here. That separation is deliberate: a build record
that quietly acquires deployment outcomes stops being a record of what was built.

| | |
| --- | --- |
| Deployment commit | **`ae41291`** (`ae412913e107ad4e42e1af2e8aad647cfcd6d7f1`), PR #6 |
| Previous release | **`ff34d7d`** |
| Rollback commit | **`ff34d7d`** (`./deploy/rollback.sh ff34d7d`) |
| Released at | 8 Sep 2026, **12:08:37 UTC** |
| Authorised by | Joy, explicitly, after the plan in `docs/53` section 9 was agreed |
| Migration | 1, additive, reversible, ran once as batch 7 |
| Model provider called | **none.** `ai_runs` is still empty |
| Live safety gate | **NOT RUN.** Pending the provider credential |

---

## A. Pre-flight

Three checks, all passed before anything was touched.

`origin/main` was `ae412913e107ad4e42e1af2e8aad647cfcd6d7f1`. Production was `ff34d7d`.
`deploy/.env` was mode `600`, which `deploy.sh` requires and refuses to run without. Seven
containers were up, five reporting healthy and two (`backup`, `scheduler`) running without a
healthcheck by design.

## B. Backup

The pre-deploy dump is taken by `deploy.sh` before anything else and **the script exits rather than
deploying if it fails**. It did not fail.

Before: newest dump `hq-20260908-084446.dump` (08:44 UTC).
After: **`hq-20260908-120757.dump`, 333,389 bytes, 12:07 UTC**, with `LAST_OK` pointing at it and
`LAST_STATUS` `0`.

The dump predates the migration, so it is a restore point for the schema as it stood before M4.

## C. Release

`./deploy/deploy.sh` fast-forwarded to `origin/main`, built the `app` and `backup` images, recreated
the stack, terminated Horizon gracefully, and recreated `web` so nginx re-resolved the new `app`
container. Production `HEAD` is now `ae41291`.

Smoke checks passed. `/up` answered, and `/api/health` returned:

```
{"status":"ok","checks":{"application":{"status":"ok","environment":"production"},
 "database":{"status":"ok","latency_ms":10},"redis":{"status":"ok","latency_ms":0},
 "queue":{"status":"ok","supervisors":1},"scheduler":{"status":"ok","last_tick_age_seconds":33}}}
```

Recorded in `deploy/RELEASES.md`:
`| 2026-09-08T12:08:37Z | ae41291 | previous ff34d7d | {"status":"ok",...} |`

All seven containers healthy after release and again after the regression run.

## D. Migration and seeder

The migration ran in the `app` entrypoint under `RUN_MIGRATIONS=1`, as batch 7:
`2026_09_08_400000_create_model_tiers_and_ai_cost_columns ... [7] Ran`.

Schema verified directly rather than inferred from the migration having run:

| Object | Present |
| --- | --- |
| `model_tiers` table | yes |
| `ai_budgets` table | yes |
| `ai_runs.cache_write_tokens` | yes |
| `ai_runs.actual_cost_usd` | yes |
| `ai_runs.degraded_from_tier` | yes |
| `ai_runs.routing_reason` | yes |

`ModelTierSeeder` was then run by full namespace, on its own:

```
php artisan db:seed --class="Database\Seeders\ModelTierSeeder" --force
```

Four rows, matching the prices verified on 8 September:

| tier | model | ctx | in $/M | out $/M | cache read | cache write | prio |
| --- | --- | --- | --- | --- | --- | --- | --- |
| low | `claude-haiku-4-5-20251001` | 200,000 | 1.0000 | 5.0000 | 0.1000 | 1.2500 | 10 |
| medium | `claude-sonnet-5` | 1,000,000 | 2.0000 | 10.0000 | 0.2000 | 2.5000 | 10 |
| medium | `claude-opus-5` | 1,000,000 | 5.0000 | 25.0000 | 0.5000 | 6.2500 | 90 |
| high | `claude-opus-5` | 1,000,000 | 5.0000 | 25.0000 | 0.5000 | 6.2500 | 10 |

All active, all stamped `pricing_verified_on 2026-09-08`. Opus 5 sits at medium with the worse
priority, so that tier degrades upward in capability at a higher price rather than downward in
quality when Sonnet cannot serve a call.

## E. The runtime is off, and was not switched on by deploying

Checked before any probe, and it is the check that matters most in this release.

`feature_flags` holds **one** row: `scheduler.gateway`, enabled, from M2. There is no `ai.runtime`
row, and `Features::enabled()` returns false when no row exists, so **`ai.runtime` is OFF by
absence**, which is the intended fail-closed default rather than a setting someone remembered to
apply. `ai.freeze` is likewise off, which is correct: freeze is the emergency stop, not the resting
state.

**Deploying M4 did not enable anything.** That is the whole design of shipping the runtime behind a
flag, and it held.

## F. The dry probe

`php artisan hq:ai:probe` on production, sending nothing:

```
Providers
  anthropic    credential: NOT CONFIGURED breaker: closed
Budget
  scope global: spent $0.0000 of $50.00 (0%)
Routing refused: No usable model for task [diagnostic] at the low tier or below.
  Rejected: Claude Haiku 4.5: provider [anthropic] has no credential configured.
```

It listed the four registered models with their prices, reported the budget, and then **refused to
route** rather than falling back to something it could reach. That refusal is the router behaving
exactly as designed: a missing credential produces a named, visible refusal, not a silent
substitution.

No request was sent. `ai_runs` remains empty.

## G. Six-job regression check

The release changed `PolicyEngine`, which sits on the hot path of every scheduled job, so all six
live jobs were re-run through the gateway immediately after deployment.

Run with `ScheduledJobService::runNow()`, **not** `RunScheduledJob`, which takes a job RUN id rather
than a scheduled-job id and returns silently when handed the wrong one, looking indistinguishable
from a clean run.

| job | tool | job_run | status | attempt |
| --- | --- | --- | --- | --- |
| 1 Phase 0 acceptance | `noop` | 35 | succeeded | 1 |
| 2 LaLaDia reconciliation | `commerce.reconcile_facts` | 36 | succeeded | 1 |
| 3 Order fact integrity | `commerce.check_fact_integrity` | 37 | succeeded | 1 |
| 4 ERP overview snapshot | `erp.poll_overview` | 38 | succeeded | 1 |
| 5 ERP low stock snapshot | `erp.poll_low_stock` | 39 | succeeded | 1 |
| 6 Bionic reconciliation | `commerce.reconcile_facts` | 40 | succeeded | 1 |

All six succeeded on the first attempt. **Zero failed queue jobs.**

Scope resolution, which is what M2's `ScopeResolver` exists to get right, still resolves from the
payload where the request leaves it null:

| tool_call | tool | policy | business | store |
| --- | --- | --- | --- | --- |
| 33 | `noop` | allow | - | - |
| 34 | `commerce.reconcile_facts` | allow | 2 | 1 |
| 35 | `commerce.check_fact_integrity` | allow | - | - |
| 36 | `erp.poll_overview` | allow | 1 | - |
| 37 | `erp.poll_low_stock` | allow | 1 | - |
| 38 | `commerce.reconcile_facts` | allow | 3 | 2 |

LaLaDia at business 2 / store 1 and Bionic at business 3 / store 2, matching what M2 recorded.
**Zero denials in the window.** Four connector calls, all `succeeded`: `shop:laladia-com`,
`shop:bionic-garden`, and `erp:bor-de-guna` twice.

## H. Safety posture, verified on production after the release

| Claim | Verified |
| --- | --- |
| Tools registered | 7 |
| Agent-executable tools | **NONE** |
| Permanently human-only tools | none; all seven are "not yet" (ADR-025) |
| R4 or R5 tools registered | `demo.request_approval` only |
| R4 or R5 tools **enabled** | **NONE** |
| Agents | **0** |
| `ai_runs` rows | **0.** No model has ever been called |
| `ai.runtime` | **OFF** (no flag row) |
| `audit_logs` immutability | triggers `audit_logs_no_update` and `audit_logs_no_delete` present |
| Approval single-use index | `approvals_resulting_tool_call_unique` present |
| Approval consumed check | `approvals_consumed_has_result` present |

Approvals stand at 12 rows, 4 consumed, carried over from M3 and its UI follow-up. Nothing in this
release created, consumed or altered one.

The controls that are properties of the code rather than of the database are enforced by tests that
passed in CI on `main` at this commit: the model runtime cannot reference a tool registry, a
gateway, an ERP or commerce connector, `DB::`, `Artisan::`, `shell_exec` or `proc_open`; and
`CredentialVault` remains the only decryption point, enforced by a filesystem-scanning test.

## I. What remains, and what it is waiting on

**The live safety gate has not been run.** It needs an `anthropic_api_key` credential for account
`hq`, added through the HQ UI at `/credentials`. It is not an env value and never will be
(`config/hq.php` says so, and the credential boundary depends on it).

The remaining sequence, once the key exists, is unchanged: dry probe again to confirm the credential
is seen, enable `ai.runtime` deliberately for the gate, `hq:ai:probe --live`, then verify the
`ai_runs` row for a non-empty reply, correct token counts, correct cache read and write accounting,
a recorded estimated cost, a recorded routing reason, no secret in any log or output, and
`hq:ai:cost` reporting the run.

Until then the honest statement is the one in the table above: **no model has ever been called.**

## J. Limitations carried forward, unchanged by this release

`actual_cost_usd` is never written, because no billing reconciliation exists; every figure is an
estimate from token counts, and the two columns are deliberately not equal by construction.
`BudgetLedger::checkAgentBudget()` still sums `estimated_cost_usd` alone rather than the coalesced
cost, which is harmless while `actual_cost_usd` is always null and should move when it is not.
`stop_reason` is not stored on a run. The probe makes one call rather than one per configured
provider, which is theoretical with a single driver. Approve-with-edit remains tested and never
exercised on production, and `/approvals` still wants Joy's eyes on the rendered screen.
