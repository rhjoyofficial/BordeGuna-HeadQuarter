# docs/106 - Evaluation cost attribution (10 Oct 2026)

Authority: `docs/103-chatgpt-ruling-2026-10-10.txt` §2 and `docs/101` §13 ("Evaluation cost attribution: APPROVED as a separate small change"). Branch `platform/eval-cost-attribution`, worktree `hq-wt/f`, from main `c12792f`.

Finding being fixed: `docs/103-p3-g2-attempt-5-record-2026-10-10.md` §5 finding 2. Both evaluation runs of P3-G2 attempt 5 (#45, #46) recorded `evaluation_runs.cost_usd = 0.000000` while the 96 `ai_runs` in the gate window total about $0.89 estimated.

## 1. Plan (written before any code)

### Root cause to confirm
- `evaluation_runs.cost_usd` is a column with `default(0)`, listed in `EvaluationRun::$fillable`, and nothing in `app/` writes it. `EvaluationRunner::run()` never sets it. The zero is the column default, not a computed zero.

### The evidence chain (as traced in the code)
- `AgentRunner::run()` mints one fresh `correlation_id` (UUID) per `agent_runs` row. Every model call of that run (each turn, the closing turn, the reply-script repair) passes the same id in its `RoutingContext`.
- `ModelRuntime` writes one `ai_runs` row per provider attempt (fallbacks and retries are `attempt` 2, 3, failed attempts included), every row keyed on that `correlation_id`. The bounded raise/repair of one attempt is billed on that attempt's own row.
- `AgentEvaluationSubject::observe()` stores `evaluation_results.ai_run_id` = ONE (unordered, arbitrary) `ai_runs.id` with that correlation. So a result points at one model call of a case that may have made several.
- Thread cases run inside a rolled-back transaction; `EvidenceCarryOver` re-inserts the run's `ai_runs` rows with their own ids, so the link survives.

### Fix
1. Carry the agent run's `correlation_id` explicitly: new `EvaluationObservation::$correlationId`, set by `AgentEvaluationSubject`, stored on a new nullable `evaluation_results.correlation_id`. Make the `ai_run_id` pick deterministic (lowest id).
2. New `App\Evaluation\EvaluationCostAttributor`: for one evaluation run, the set of `ai_runs` that are evidence for it = rows whose `correlation_id` is any result's correlation (the stored one, else the one on the result's linked `ai_run_id` row, which is existing evidence for older results) UNION the linked rows themselves. A SET, each `ai_runs` row counted once however many results lead to it, so the sum cannot double-count and re-running gives the same number (it overwrites, never increments).
3. Two facts kept apart (`ai_runs` stores both columns; `actual_cost_usd` + `cost_source` exist but nothing writes them today, see `ModelRuntime::recordUsage`):
   - `evaluation_runs.cost_estimated_usd` = sum of `estimated_cost_usd`;
   - `evaluation_runs.cost_provider_usd` = sum of `actual_cost_usd` over rows that HAVE one; NULL when no row has a provider-reported figure (today: always NULL; not fabricated);
   - `evaluation_runs.cost_usd` = sum of `COALESCE(actual_cost_usd, estimated_cost_usd)`, the convention the budget ledger (`AiBudget`), `CostDashboard` and `ShadowEvidence` already use;
   - `evaluation_runs.cost_attribution` (jsonb): method/version, counts (ai_runs, provider-reported, retry attempts, failed attempts, results linked, results provably without a model call, results unaccounted), `complete` flag, computed-at.
4. Completeness: a result is accounted for when it links to `ai_runs` evidence, or its observation says `model_turns = 0` (provably no call). Anything else (a run that threw before the subject could read its correlation, a scenario not replayed, an old result with no link and no turn count) is "unaccounted" and makes `complete = false`. Never guessed.
5. `EvaluationRunner` calls the attributor AFTER status, rates and regressions are saved, inside a try/catch: a failure is reported and recorded on `cost_attribution.error`, and can never change status, pass/fail, rates or the exit code. It writes only the cost columns.
6. Backfill command `hq:evaluation:attribute-cost` (dry-run by default, `--apply` to write, `--run=` to pick): recomputes from existing `ai_runs` evidence only; writes only runs whose attribution is `complete`; incomplete ones are listed and left untouched. No estimate, no window-based guess.
7. `hq:agent:evaluate` prints the cost line per run.

### Tests (Pest), written first and watched fail on unmodified code
success, failure (failed agent run with failed ai_runs), multiple model calls per case, retries/fallback attempts, missing costs (no model call; no evidence; no provider figure), provider-reported kept separate, idempotence (twice = same, shared correlation counted once), gate outcome unchanged when attribution throws, end-to-end through `AgentEvaluationSubject` with the fixture provider, and the backfill command (dry-run writes nothing, apply writes complete runs only).

### Out of scope
No production access, no deploy, no merge to main. No change to graders, status, thresholds, autonomy or activation.

## 2. What was built

### Root cause (confirmed)
`evaluation_runs.cost_usd` (`decimal(10,6) default 0`, migration `2026_09_09_320000`) was in `EvaluationRun::$fillable` and was never written anywhere in `app/`. `EvaluationRunner::run()` set status, counts, rates, external outcomes and regressions, never cost. Every evaluation run ever recorded therefore holds the column default; #45 and #46 are not special. Secondary defect: `evaluation_results.ai_run_id` held one arbitrary `ai_runs` row (`->value('id')` with no order) of a case that may have made several calls, so even a naive "sum the linked rows" would have under-counted multi-turn cases and every retry.

### Files
- `apps/hq/database/migrations/2026_10_10_100000_add_cost_attribution_to_evaluation.php`: `evaluation_results.correlation_id` (string 64, nullable, indexed); `evaluation_runs.cost_estimated_usd` and `cost_provider_usd` (decimal 12,6, nullable) and `cost_attribution` (jsonb, nullable). Schema only; no data touched.
- `apps/hq/app/Evaluation/EvaluationCostAttributor.php` (new): `compute()` / `attribute()`. Evidence = the SET of `ai_runs` rows whose correlation is any result's (`COALESCE(result.correlation_id, linked ai_run.correlation_id)`) UNION the directly linked rows; one SQL aggregate; overwrite, never increment. Writes `cost_usd`, `cost_estimated_usd`, `cost_provider_usd`, `cost_attribution` and nothing else.
- `apps/hq/app/Evaluation/EvaluationRunner.php`: records `correlation_id` on every result (graded and unmeasured); after the verdict is saved, attributes cost inside a savepoint and a try/catch (failure: `report()`, `cost_attribution.error`, `complete=false`; status, counts, rates and exit code untouched); the `evaluation.run_completed` audit row carries the cost figures.
- `apps/hq/app/Evaluation/EvaluationObservation.php`: new `correlationId` (last, optional; every existing caller unchanged).
- `apps/hq/app/Agents/AgentEvaluationSubject.php`: sets `correlationId` from the agent run; `aiRunId` now the run's FIRST model call (`orderBy('id')`), deterministic.
- `apps/hq/app/Models/EvaluationRun.php`, `EvaluationResult.php`: fillable and casts.
- `apps/hq/app/Console/Commands/EvaluationAttributeCost.php` (new): `hq:evaluation:attribute-cost [--run=ID ...] [--apply]`.
- `apps/hq/app/Console/Commands/AgentEvaluate.php`: one `cost` line per suite in the gate report.

### The figures, defined
| Column | Meaning |
|---|---|
| `cost_estimated_usd` | Sum of `ai_runs.estimated_cost_usd` over the evidence set. NULL = never attributed (every run before this change until backfilled). |
| `cost_provider_usd` | Sum of `ai_runs.actual_cost_usd` over the calls that carry one. NULL = no call carries a provider-reported cost. **Today always NULL**: nothing in HQ writes `actual_cost_usd` (`ModelRuntime::recordUsage` leaves it for a future billing reconciliation). Not filled from the estimate. |
| `cost_usd` | Sum of `COALESCE(actual_cost_usd, estimated_cost_usd, 0)` per call: the convention of `AiBudget`, `CostDashboard`, `ShadowEvidence`. Equal to the estimate today. |
| `cost_attribution` | `method` (`ai_runs.correlation_id.v1`), `complete`, `ai_runs`, `ai_runs_provider_reported`, `retry_attempts` (`attempt > 1`), `failed_attempts`, `correlations`, `results`, `results_linked`, `results_without_model_call` (`model_turns = 0`), `results_unaccounted`, `provider_cost_note`, `computed_at`; or `error`. |

A result is **unaccounted** when it reaches no `ai_runs` row and its observation does not record `model_turns = 0`: the subject's `observe()` caught an exception thrown by `AgentRunner::run()` (no run returned, so no correlation to read), a scenario not replayed, or an old result with neither link nor turn count. Its cost is not guessed; the run is marked `complete = false`.

### Backfill (`hq:evaluation:attribute-cost`)
- Dry run by default: prints, per finished run, recorded `cost_usd`, computed `cost_usd`, estimated, provider, ai_runs count, results linked/no-call/unaccounted, and the action. Writes nothing.
- `--apply` writes only runs whose attribution is `complete`; incomplete runs are listed as "skipped: incomplete evidence" and never written. `--run=` narrows to given ids.
- Computes only from `ai_runs` rows that exist (`ai_runs` with an `agent_key` or a linked evaluation result are kept forever by `RetentionPolicy`). No time window, no average, no estimate of a missing call.
- Not run anywhere but the test DB. Running it on production (first dry, then `--apply`, e.g. `--run=45 --run=46`) is Joy's line after merge and release.

### Tests: `apps/hq/tests/Feature/Evaluation/EvaluationCostAttributionTest.php` (10, all red on `c12792f` + the plan commit, green after)
1. attributes EVERY model call of a case to the run, not the one call the result links to (multiple calls)
2. counts every retry and fallback attempt once, failed attempts included, and nothing outside the case (retries; foreign and null-correlation rows ignored)
3. attributes the cost of a FAILED case exactly as a passed one (failure)
4. is idempotent: attributing again gives the same number, and a correlation shared by two results counts once
5. keeps the provider-reported cost apart from the estimate, and leaves it NULL when no provider figure exists
6. records a case that made no model call as zero and accounted, and a case with no evidence as unaccounted, never guessed (missing costs)
7. never changes the gate outcome: an attribution failure is recorded on the run and the verdict stands
8. attributes a real agent run end to end (Executive, fixture provider down, fallback serves: two `ai_runs` rows, attempt 2)
9. attributes a Support run on a rolled-back thread: the model calls carried across the rollback (`EvidenceCarryOver`) are the evidence (three turns)
10. backfills only from existing evidence: a dry run by default, and `--apply` writes complete runs only

### Gate outcomes and posture
Unchanged by construction: attribution runs after status/counts/rates/regressions are saved, writes only cost columns, cannot throw out of the runner, and nothing reads the cost columns for any gate, grant or autonomy decision. Test 7 holds it.

### Decisions for a ruling
1. **Headline `cost_usd` = `COALESCE(provider, estimate)` per call** (the budget-ledger convention), with both pure figures beside it. The alternative is `cost_usd` = estimate only. Identical today, because no provider figure exists.
2. **Backfilling production runs** (#45, #46 and earlier): the command is ready and safe (dry run, complete-only). Whether and when to run `--apply` on production is Joy's call after release.
3. **Exception path stays unaccounted**: when `AgentRunner::run()` itself throws, the subject never learns the run's correlation, so any model calls made before the throw are not attributed and the run says `complete = false`. Closing it would mean the runner exposing the correlation on its exceptions; a separate change if wanted.

### Verification (local, 10 Oct)
- Full Pest suite `DB_DATABASE=hq_test_f php artisan test --parallel --processes=4`: **1857 passed** (21660 assertions; 1847 before + the 10 new).
- The 10 new tests: 9 red on the unmodified code (WIP commit `8d3625c`), all green after; the thread-path test was added with the fix.
- Migration applied, rolled back and re-applied on Postgres 17 (`hq_test_f`).
- `vendor/bin/pint --test`: passed. `npx tsc --noEmit`: exit 0. No frontend file touched, so no `vite build`.
- Not merged, not deployed; production untouched.
