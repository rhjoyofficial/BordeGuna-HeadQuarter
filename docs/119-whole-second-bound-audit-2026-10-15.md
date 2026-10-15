# 119 - Whole-second bound audit (15 October 2026)

Status: DONE on branch `platform/whole-second-bound-audit` (worktree `hq-wt/f`, test database `hq_test_f`, from `main` `3e91c2f`). Pushed, not merged. Asked by `docs/118` §8, the follow-up of fix `6726f51` (`docs/111`). Nothing activates; no production access; no migration.

## Plan (committed first, `cea779f`)
1. Establish the precision of every timestamp column (migrations and the live test schema) and how each one is written (by the application, or by a database default).
2. Establish how a time bound reaches Postgres (the query grammar's date format).
3. For each named reader (AiBudget, ShadowEvidence, AdBudgetGuard, FactsOrdersDeliveredSales, AiHealthDashboard, ControlPlaneDashboard, ToolGateway, ToolRegistryController, CrossBusinessDetector) and any other reader that bounds a `*_at` column at "now": classify SAFE or AFFECTED with a concrete scenario.
4. Fix any AFFECTED reader with the `6726f51` pattern; a test that fails on the old code and passes on the new, proved by a temporary revert.
5. Pin the assumptions the SAFE verdicts rest on with tests, so a later change cannot quietly break them.
6. Gate: full Pest suite (parallel), Pint. Record the result here.

## 1. What actually decides the effect

The CI failure in `docs/118` §8 looked like a property of the column. It is a property of how the value reaches the column. Two facts settle it.

- **Every timestamp column in HQ is precision 0.** Laravel 12's `Schema\Builder::$defaultTimePrecision` is 0 and no migration passes a precision or alters one in SQL. The live `hq_test_f` schema agrees: 272 timestamp columns, none with a precision other than 0.
- **The application never sends a fraction.** Eloquent formats a date with the model's `getDateFormat()`, which is the grammar's `'Y-m-d H:i:s'` (no model overrides `$dateFormat`), and `Connection::prepareBindings()` formats every bound `DateTimeInterface` the same way. So an application write at 10:00:05.6 is stored as 10:00:05 (the fraction is dropped, which is a floor), and a bound at 10:00:05.6 is sent as 10:00:05.
- **Only a database default rounds.** A `useCurrent()` column left out of the insert takes `CURRENT_TIMESTAMP`, which carries microseconds, and Postgres rounds it into the precision-0 column: 10:00:05.6 becomes 10:00:06. That is exactly `decision_records.created_at` (`DecisionRecord` has `$timestamps = false` and never sets the column), which is why `DecisionCorpusReader` was the one that broke.

What follows from this:

- An application-written row and a Carbon bound are cut the same way, and the cut is monotone. A row written at or before "now" is always inside a window that ends at "now" with `<=`, and always inside a window that starts at or before it with `>=`. The only operators that can lose a same-second row are a strict `<` at "now" and a strict `>` at a start, and both are covered below.
- A database-default row can sit up to half a second after the PHP clock. Only an upper bound at "now" can lose it; a lower bound cannot (a floored bound is never later than a rounded stamp of a later instant).
- Over-inclusion at a lower bound (a row from up to one second before the start) is possible everywhere. For a budget, a rate or a replay window this is the safe side: it counts more, never less.

## 2. Verdict per reader

- **AiBudget: SAFE.** `ai_runs.created_at` is written by Eloquent (floored). Every query has only a lower bound, `startOfDay()` / `startOfMonth()` (whole seconds) or the same `$since`, and no upper bound. A run written in the current second is always counted. The one-second drift goes the safe way: a run written in the last half second before midnight is counted in the new day as well. Pinned by a test (a run written at 10:00:05.6 is in the daily and monthly spend at once).
- **ShadowEvidence: SAFE.** `today()` ends at the next whole second and the queries use `< $to`; every table it reads (`agent_runs`, `tool_calls`, `ai_runs`, `conversation_messages`, `message_deliveries`, `audit_logs`, `consent_events`) is written by the application, so a row written at 10:00:05.6 is stored as 10:00:05 and is inside `< 10:00:06`. `store()` ends a closed day at `closed_at + 1 s`, where `closed_at` is read back fresh (both callers pass `fresh()` or a queried row), or at local midnight for a complete day; a row in the last half second is never lost, it only moves to the next day's window, which starts at that midnight with `>=`. The code's own comment says "whole seconds" and was right for floored writes; it would be wrong for a rounded column. Pinned by a test: the hourly stop-on-violation check sees another agent's run written at 10:00:05.6. Shown to bite: with `AgentRun` keeping microseconds the test fails (the row is rounded to :06), and with `today()` ending at "now" instead of the next second it fails too.
- **AdBudgetGuard: SAFE.** Month-to-date spend sums `facts_ad_spend.spend_date`, a DATE column, between local month start and local today: no time precision involved. Staleness compares `fetched_at` in PHP against `now - 24 h`; a floored stamp makes the account look up to one second older, so it turns stale up to one second early (fail-closed). The budget policy is found by `Policy::activeAt($now)`: `PolicyRepository` writes `active_from` and the old row's `active_to` from one PHP instant, both floored, so at any later moment exactly one of them is in force. A brand new budget is visible from its own second on.
- **FactsOrdersDeliveredSales: SAFE (for rounding).** The bounds are whole local days (`startOfDay`, `endOfDay` cut to 23:59:59), not "now", and `delivered_at` is written by the application (floored), so a delivery at 23:59:59.7 is stored as 23:59:59 and is inside. See §4 for a separate time-zone observation that is not part of this audit.
- **AiHealthDashboard: SAFE.** Every `created_at` query is a lower bound at `now - N days`. The one upper bound, `sla_due_at <= until`, is on an application-written deadline (floored), so a deadline that has passed is always counted. Display only.
- **ControlPlaneDashboard: SAFE.** Lower bounds only (`tool_calls.created_at`, `approvals.decided_at`, both application-written). Display only.
- **ToolGateway: SAFE.** The replay lookup is `tool_calls.created_at >= now - window`, lower bound only, on an application-written column. A prior call written a moment ago is always found, so it is replayed and never executed twice; the drift can only find a call up to one second older than the window (more replay, the safe side).
- **ToolRegistryController: SAFE.** `tool_calls.created_at >= now - 1 day`, lower bound only, display only.
- **CrossBusinessDetector: SAFE.** Not bounded at "now". It compares `memories.created_at <= customer_merges.created_at`, two application-written stamps floored the same way, so a memory written before the merge is always found. A memory written after the merge in the same second is also flagged, which only adds a row for a person to review (detection, nothing undone). `CrossBusinessSuppression` uses the matching `>` the other way round, so the two agree.

## 3. The wider sweep

Every other `*_at` query in `app/` with an upper bound or a strict bound was read (`ConsentLedger`, `FollowUpController`, `SchedulerTick`, `Approval` scopes, `ApprovalsPastDeadline`, `DeliveryLedger`, `ShadowComparisons`, `ShadowProbes`, `QueryOrdersTool`, `Anomalies`, `ScheduledToday`, the retention passes, the autonomy `expires_at > now` checks, `ShadowPeriodStopped`). All of them bound application-written columns, so none can lose a just-written row. The strict `expires_at > now` checks treat a grant as expired up to one second early, which is fail-closed.

Most `useCurrent()` columns are in fact set by the application (`now()` in the insert, or Eloquent's own `created_at` on the models with `UPDATED_AT = null`), so they are floored like any other. The ones the database really stamps are `decision_records.created_at` and `connector_calls.created_at` (both models have `$timestamps = false` and leave the column out), plus `autonomy_grants.granted_at` where a grant is written without it. Apart from `DecisionCorpusReader` (fixed in `6726f51`), nothing bounds these from above at "now": `connector_calls` is read only with a lower bound (`AdminController`, last 24 hours), which a rounded stamp cannot escape, and no query bounds `granted_at` at all.

So: **no reader needed a code change.** The audit found nothing AFFECTED beyond the reader already fixed.

## 4. What changed
- `tests/Feature/Platform/WholeSecondBoundsTest.php` (4 tests), pinning the assumptions every SAFE verdict rests on: no model keeps a fraction in its date format; an application write at 10:00:05.6 is stored as 10:00:05, never :06; a Carbon bound is sent cut to its second while the column itself rounds a fraction it is given (the `decision_records` case); and a model run written in the current second is counted against the budget at once.
- `tests/Feature/Agents/ShadowPeriodTest.php` (1 test): the stop-on-violation window sees a run written late in the current second.
- Mutation proof, both reverted and checked with `git diff` (empty afterwards): giving `AgentRun` the date format `'Y-m-d H:i:s.u'` failed 3 of these tests; making `ShadowEvidence::today()` end at "now" instead of the next second failed the shadow test.
- No shared helper: only one reader (`DecisionCorpusReader`) needed the widening, so a helper would have had a single caller.

## 5. Observations outside this audit (not fixed)
- **The zone of a bound, not its second.** `ContributionReport` passes Dhaka-zoned day bounds to `FactsOrdersDeliveredSales`, and a bound Carbon is sent without its offset, so Postgres (session zone UTC) reads Dhaka wall-clock as UTC. Whether that matches the stored `delivered_at` depends on how the order was written: `FactsOrderUpserter` parses the shop's string with `CarbonImmutable::parse()` and no `->utc()`, the same shape as the `docs/45` E.3 defect. Not verified against a real shop payload; worth its own small check before the contribution report is used for money decisions.
- **A database default inside a long transaction** stamps the transaction's start, not the row's write. Harmless for every reader above; noted because it is the other half of why a DB-default stamp and the PHP clock disagree.
- **A future model that keeps microseconds** (or a raw insert of a formatted string with a fraction) would turn a floored column into a rounded one. `WholeSecondBoundsTest` now fails first if that happens.

## 6. Gate
- Full Pest suite, `DB_DATABASE=hq_test_f php artisan test --parallel --processes=4`: GATE_RESULT.
- Pint: passed. No TypeScript touched.
