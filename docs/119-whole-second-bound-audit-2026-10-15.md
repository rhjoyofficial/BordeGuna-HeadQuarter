# 119 - Whole-second bound audit (15 October 2026)

Status: IN PROGRESS on branch `platform/whole-second-bound-audit` (worktree `hq-wt/f`, test database `hq_test_f`, from `main` `3e91c2f`). Asked by `docs/118` §8 (follow-up of fix `6726f51`, `docs/111`).

## Plan
1. Establish the precision of every timestamp column (migrations and the live test schema) and how each one is written (by the application, or by a database default).
2. Establish how a time bound reaches Postgres (the query grammar's date format).
3. For each named reader (AiBudget, ShadowEvidence, AdBudgetGuard, FactsOrdersDeliveredSales, AiHealthDashboard, ControlPlaneDashboard, ToolGateway, ToolRegistryController, CrossBusinessDetector) and any other reader that bounds a `*_at` column at "now": classify SAFE or AFFECTED with a concrete scenario.
4. Fix any AFFECTED reader with the `6726f51` pattern; a test that fails on the old code and passes on the new, proved by a temporary revert.
5. Pin the assumptions the SAFE verdicts rest on with tests, so a later change cannot quietly break them.
6. Gate: full Pest suite (parallel), Pint. Record the result here.
