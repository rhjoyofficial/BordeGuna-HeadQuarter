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
(filled in at the end)
