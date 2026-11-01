# 150. Durable autonomy lapse (1 November 2026)

**Ruling (ChatGPT, 1 Nov 2026, binding).** An autonomy grant becoming invalid is an immediate authorization failure, whether or not the caller's database transaction later rolls back. It replaces the earlier default in docs/145-d (ruling 17): "a lapse inside a caller's rolled-back transaction rolls back; the next decision or the minute sweep lapses again".

## What changed

| Part | Where | What it does |
|---|---|---|
| Decision-time check | `LadderGrantLapse::stands()` | Every condition is evaluated at the decision, independent of the stored status. The answer is false the moment one fails, whether or not the lapse is written. |
| Durable write | `DurableLapseWriter::writeDurably()` | Writes the lapse and its audit row on the dedicated connection `autonomy_lapse`, in its own short transaction, committed before `stands()` returns. |
| Pending marker | `PendingLapses` (process singleton + cache) | Set before any write, cleared only once the write is known committed. While it exists the grant is refused even if the conditions recover, and each decision retries the write. |
| Fallback write | `DurableLapseWriter::writeInCaller()` | When the dedicated session cannot see the row (created in the caller's open transaction) or its write fails, the lapse is written in the caller's transaction (savepoint). The marker is cleared only after the caller commits. |
| Recovery | `hq:autonomy:lapse-sweep` (every minute) | First writes every pending lapse with its original code and reason (`writePending()`), then lapses every active ladder grant whose conditions fail. Recovery, not the safety boundary. |
| Audit on another connection | `Auditor::recordSystem(..., connection:)` | The lapse audit row commits with the lapse, on the same session. |

Lapse conditions (codes): `evidence_below_threshold`, `ai_paused`, `pause_unreadable`, `threshold_retired` (retired, missing or replaced), `grant_expired` (new), `safety_condition_invalid` (level above the threshold's, tool unregistered or now human-only, scope mismatch, or the check itself failed). An expired grant was already refused by the resolver's query; the sweep now also records it as lapsed.

## Why a dedicated connection

A Laravel nested `DB::transaction()` is a savepoint inside the caller's transaction, so anything written on the default connection rolls back with the caller. The options were:

1. **A second connection (chosen).** A separate PostgreSQL session commits on its own; nothing the caller does afterwards (commit, roll back, throw, or the worker process dying) can undo it. It is synchronous, so the lapse is committed before the decision returns, with no queue in between.
2. A queued job with `afterCommit = false`. On the `sync` driver it runs inside the caller's transaction (same problem); on the `database` driver the job row itself rolls back with the caller; and a worker crash before the job runs loses it. Rejected.
3. An after-rollback callback. Lost on a worker crash, and runs only after the caller decides. Rejected.

Lock safety: the dedicated session sets `lock_timeout = 3s` for its transaction. If a caller holds the grant row locked, the write times out instead of waiting forever on its own process, and the fallback writes it with the caller (who holds the lock).

## Deliberate exception: retiring a threshold

`LadderGrants::retireLocked` lapses its grants with `lapseWithCaller()`, in the retirement's own transaction. The retirement is what makes `threshold_retired` true: if it rolls back, the threshold was never retired and the grants rightly stand; if it commits, both commit. (It also holds the grant rows locked, which the dedicated session would wait on.)

## When the lapse cannot be written

- The grant is still refused: `stands()` returned false before any write was tried.
- The pending marker keeps it refused even if the condition clears (for example a pause state that was unreadable for a moment). Each decision retries the write; the sweep reconciles.
- Limit: the marker lives in process memory and in the cache store. With `CACHE_STORE=database` the cache shares the database's fate, so another process only has the conditions themselves as the guard (evaluated again at every decision). A non-database cache store (Redis) makes the marker cross-process.

## No automatic reactivation

Unchanged from ruling 17 and enforced in three places: the database trigger `autonomy_grants_lapse_one_way` refuses any update that brings a lapsed (or ladder grant that left `active`) back to `active`; the resolver only reads `status = active`; nothing in the sweep, reconcile or pause/resume path can grant. Only `LadderGrants::grant` by a person, which re-verifies every condition, makes a NEW grant.

## Tests

`tests/Feature/Completion/DurableAutonomyLapseTest.php` (real PostgreSQL). The first two leave the per-test transaction (the dedicated session cannot see it) and rebuild the database with `migrate:fresh` afterwards.

1. Lapse inside a caller transaction that then rolls back: the caller's own work is gone, the lapse and one audit row (`durable: true`) persist, no pending marker, still A0.
2. Worker crash after the decision: the session is dropped with its transaction open, memory and cache emptied; the lapse is committed; resuming AI restores nothing.
3. Both writes fail (mocked writer): refused, row still `active`, marker set; evidence recovers and it is still refused; the sweep with a working writer writes the original lapse once.
4. Unreadable pause with a failing writer: refused; readable again: still refused; then lapsed `pause_unreadable`.
5. A retirement that rolls back leaves threshold and grant standing at A2; a changed threshold lapses the grant `threshold_retired`.
6. Expired grant: refused at once; the sweep records `grant_expired`; extending the expiry restores nothing and the database refuses reactivation.

The existing `AutonomyLapseRulingTest` and `AutonomyLadderTest` still pass unchanged (evidence below threshold, pause, unreadable pause, retired threshold, safety condition, sweep, one-way trigger).
