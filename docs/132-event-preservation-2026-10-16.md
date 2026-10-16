# 132. Event preservation across containment, release and restore (16 October 2026)

Ruling `docs/131-chatgpt-ruling-2026-10-16.txt` section 1.1 (last paragraph: "Verify that stopping event processing cannot discard payloads or lose source acknowledgements. If durable replay cannot be proven, stop and report the exact blocker."), Stage B (last bullet: the event preservation strategy prevents incoming source events from being lost across a restore) and Stage D steps 4 and 10 (no automatic replay after deploy; replay idempotently under the new resolver only after repair verification). Context: docs/121 section 3 (containment Option 2 is `docker compose stop scheduler`), docs/127, docs/128, `deploy/rehearsal/`.

Branch `platform/event-preservation` from `main` `3e3d81a`. Nothing here touched production. Nothing was released.

## Plan (written before the code)

1. Verify from code, as tests where possible: (a) a webhook is committed to `events` before the 202, with the scheduler stopped; (b) nothing but `hq:events:process` drains or expires `events` rows; (c) what a shop does with a 202 (assumption, shop code is not in this repo); (d) replay idempotency (dedupe key, facts upsert key).
2. A restore-safe bridge: `hq:events:export-since` (read only, JSONL with checksum) and `hq:events:import` (dry run by default; `--apply --by --reason` inserts absent rows as pending, never processes, audited, refuses conflicting payloads).
3. A drain pause flag `HQ_EVENTS_DRAIN_PAUSED`, default false, honoured by `hq:events:process`.
4. Wire the bridge into `deploy/rehearsal/rehearse.sh` and the documented restore procedure; no silent change to `deploy/restore.sh`.
5. Tests, gate, this record.

## 1. The answer in one paragraph

Stopping the scheduler cannot discard a payload or lose an acknowledgement HQ has given. The webhook endpoint lives in the `app` container, writes one `events` row with the whole payload in a single autocommitted INSERT and only then answers 202; the only code that moves a row out of `pending` is the drain, which runs only in the scheduler container; nothing deletes `events` rows, and retention only shrinks the payload of rows that are already `processed`. Replay is idempotent on two levels: the delivery (unique `dedupe_key`) and the effect (facts upsert on `(store_id, order_ref)`, identities on `(channel, external_id, source_store_id)`). What was NOT safe is a restore: restoring the pre-deploy dump silently drops every event accepted after the dump, and `deploy.sh` would start a stopped scheduler and drain the backlog through the new resolver straight away. Both are now closed for any release that includes this branch, by the export/import bridge and by the drain pause flag. Two things remain outside code: the shops' retry behaviour (an assumption) and a code rollback to a release without this branch (section 6).

## 2. Findings (a) to (d), with evidence

### (a) Durable before the 202, with the scheduler stopped: PROVEN

- `ShopWebhookController::__invoke` verifies the signature, then calls `EventRecorder::record`, then returns 202 with the row's `event_id`. `MetaWebhookController` and `MetaForwardController` (through `accept`) follow the same shape.
- `EventRecorder::record` is `DomainEvent::create` (one INSERT). No middleware, controller or recorder opens a transaction around it, so Postgres commits it when the statement returns, before the response exists. `synchronous_commit` is not lowered in `deploy/docker-compose.yml` (the db command sets only shared_buffers, max_connections, work_mem and log_min_duration_statement), so the commit is on disk.
- Nothing goes to Redis or a queue in place of the row: the request pushes no job.
- The row is `pending`, `attempts` 0, payload verbatim plus `_store`, `_schema_version` and `_correlation_id`.
- Pinned: `tests/Feature/Platform/EventPreservationTest.php` "(a) records an accepted webhook as one pending row before answering 202, opening no transaction and queueing nothing" (counts `TransactionBeginning` during the request: zero; `Queue::assertNothingPushed`), and "(a) keeps accepting and storing deliveries while the drain is paused".
- Not covered by this: a delivery that arrives while the `app` container itself is down (during `restore.sh`, which requires `stop app horizon scheduler`, or during a deploy's recreate). Nginx answers 502, HQ has acknowledged nothing, and the shop's retry is what saves it (finding c).

### (b) Nothing else processes or expires events while the scheduler is stopped: PROVEN

- The only callers of the handlers are `hq:events:process` (scheduled every minute in `routes/console.php`, run only by the `scheduler` container's `schedule:work`) and the manual `hq:events:replay --apply`. Horizon runs no job that reads or claims `events`; no queue job dispatches the drain.
- Writers of the `events` table, found by a structural scan and pinned by a test: `EventRecorder` (insert), `ProcessDomainEvents` (claim, processed, failed, dead), `EventsReplay` (dead or failed to processed after a successful replay), `RetentionRunner` (payload compaction, `WHERE status = 'processed'` only) and the new `EventsImport`. No code deletes an `events` row. `DomainEvent` is not `Prunable`, so `model:prune` does not touch it.
- Retention also runs only from the scheduler, and even when it runs it never touches `pending`, `failed` or `dead` rows (`retainableEvents()` and the UPDATE both filter `status = 'processed'`).
- Pinned: "(b) leaves pending events byte-identical through every other scheduled command" (scheduler tick, approvals sweep, alerts check, retention run on a three year old pending row, model:prune, queue:prune-failed; whole-row md5 unchanged) and "(b) only the drain, replay, retention (processed rows only), the recorder and the import write the events table".
- One edge, not a loss: a drain killed mid-batch by `docker compose stop scheduler` (SIGTERM, then SIGKILL after 10 seconds) leaves the rows it had claimed at `processing`. Nothing reclaims a `processing` row: the drain selects only `pending` and `failed`, and `hq:events:replay` promotes only `dead` and `failed`. The payload is intact, but the row is ambiguous (its handler may have run in part). Under docs/131 Stage D step 10 it stays for review. The operator check is in section 5 step A2. Resolving such a row is a separate, small decision (not built).

### (c) Does a shop treat 202 as final? ASSUMPTION, to be confirmed in the shop repositories

- LaLaDia and Bionic code is not in this repository. What HQ's own records say: docs/35 (5 Sep) read `SendWebhookJob` in LaLaDia as retrying 3 times (30, 120, 300 seconds); docs/37 records a LaLaDia branch (`feature/hq-phase1-api`) that extended this to 5 attempts over about 2.5 hours and added the delivery id, `occurred_at` and `schema_version` to the envelope. Which version runs on each shop today is not verified here.
- The assumption: a 2xx (202) ends the shop's job (a Laravel job that does not throw is not retried), so after a 202 the delivery exists only in HQ's `events` table; a non-2xx or a connection failure is retried within the shop's window, then dropped.
- What follows: once HQ has answered 202, HQ alone is responsible for the event, which is why the restore bridge exists. While HQ answers 502 (app down for a restore), the shops' retry window is the only protection, so that window must be kept short (minutes, not hours). `hq:facts:reconcile` (admin list poll) is a second net for orders only, not for the other event types.
- To confirm: read `SendWebhookJob` (tries, backoff, what counts as success) on the deployed LaLaDia and Bionic commits.

### (d) Idempotency of processing on replay: PROVEN

- Delivery: `events.dedupe_key` is UNIQUE. The shop key is `shop:{store}:{X-Shop-Delivery}`; the legacy envelope (no delivery id) uses `shop:{store}:{type}:{sha256(body)}`. A redelivery returns the existing row (202, `duplicate: true`). A concurrent insert of the same key loses the race at the unique index and returns the winner.
- Effect: `FactsOrderUpserter` upserts on the unique `(store_id, order_ref)`; identities are unique on `(channel, external_id, source_store_id)`; a status event never regresses a later status; event-triggered jobs and workflow signals are keyed by the event's uuid, so a retried event fires nothing twice.
- Pinned: "(d) a redelivered webhook is one row, and draining then replaying it writes one fact and one customer" (two deliveries, two drains, two `hq:events:replay --apply`: one event, one fact, one customer), plus the existing `tests/Feature/Identity/ShopWebhookAndIdentityTest.php` and `tests/Feature/Facts/ReconcileAndReplayTest.php`.
- A row imported by the bridge after it had already been processed before the restore is re-processed by the drain: the facts it wrote were lost by the same restore, and the upsert keys make the second run land once.

## 3. What was built

- `hq:events:export-since {--since-id=|--since=} {--out=}` (`app/Console/Commands/EventsExportSince.php`). Read only: SELECTs and one new file (mode 600, never overwrites). Inbound rows only: an outbound row is HQ's own output, and processing the inbound rows produces it again. JSON Lines: a header, one line per row with the payload verbatim plus `source_row_id`, `status_at_export` and `attempts_at_export`, and a trailer with the row count and a SHA-256 over every line before it. Stdout carries counts, the source id range, types, statuses and the checksum, never a payload value.
- `hq:events:import {file} {--dry-run} {--apply --by= --reason=}` (`app/Console/Commands/EventsImport.php`). Dry run unless `--apply`. Refuses the whole file on a checksum or count mismatch. Classifies every row: absent (inserted), present with the same type, source and payload (skipped; `_correlation_id` is ignored because the recorder stamps it per request, so a shop redelivery after the restore under a new event_id is recognised), conflicting (same dedupe key or event_id with something different) or malformed (missing field, not inbound, compacted payload, unknown business, too long). Any conflicting or malformed row refuses the whole apply and writes nothing. The apply takes `LOCK TABLE events IN SHARE ROW EXCLUSIVE MODE` for the check and the insert (a webhook arriving meanwhile waits milliseconds), inserts in source id order with the original `event_id`, `dedupe_key`, payload, `occurred_at` and `created_at`, `status` pending, `attempts` 0, nothing processed, and writes one `events.imported` audit record naming the person, the reason, the file checksum and every inserted event_id. It touches only `events` and `audit_logs`, whose shape no migration after 16 September changes, so the candidate's command also works against a restored pre-release schema.
- `App\Support\Events\EventBridgeFile`: the format, the checksum, the reader and the canonical payload comparison.
- `HQ_EVENTS_DRAIN_PAUSED` (`config('hq.events.drain_paused')`, default false). While true, `hq:events:process` returns before the lock and before any read: it claims nothing, increments no attempt, and says so in its output. Off by default, so behaviour is unchanged. It is listed, commented out, in `deploy/.env.example`. NOT enabled anywhere.
- `deploy/rehearsal/rehearse.sh` section 10: the bridge across a real `restore.sh` on the throwaway copy (section 4 below). `deploy/rehearsal/README.md`: the restore pre-step and post-step. `deploy/restore.sh` and `deploy/deploy.sh`: comments only, pointing here; their behaviour is unchanged.

## 4. Tests

`tests/Feature/Platform/EventPreservationTest.php`, 14 tests:

- (a) twice, (b) twice, (d) once, as above.
- The pause flag blocks processing and leaves rows byte-identical; clearing it drains them. The flag is off by default.
- Export is read only (whole-table md5 and audit count unchanged), holds only inbound rows after the cut, verifies its own checksum, prints no phone or name, never overwrites, and needs exactly one of `--since-id` or `--since`; `--since` selects by arrival time.
- Import dry run (default and explicit) writes nothing.
- Import apply restores every lost event verbatim (event_id, dedupe key, payload, created_at), pending, zero attempts, unprocessed, no fact written, audited with the inserted ids; a second apply inserts nothing and changes no byte; once the drain runs, the imported rows produce their facts.
- Import skips a delivery the shop re-sent after the restore (new event_id, same payload).
- Import refuses the whole file when one row conflicts with a different payload, and writes nothing, not even an audit record.
- Import refuses a tampered file (checksum), a file cut before its trailer, an apply without `--by` or `--reason`, and an unknown user.

`rehearse.sh` section 10 (runs on the VPS copy only, with Joy's go; not run here, no Docker locally): stop scheduler and horizon, `pg_dump` the copy, insert three synthetic deliveries (the copy cannot decrypt webhook secrets, so a signed POST would answer 503), export (proved read only), stop app, `restore.sh` the dump into the copy, prove the three are gone, import dry run (proved to write nothing), import apply, prove each is back with the same event_id, dedupe key and payload md5, pending, unattempted, unprocessed, audited, and that a second import inserts nothing. A candidate without the two commands FAILS this section.

## 5. The operator sequences

### A. Containment Option 2 now (production runs code WITHOUT this branch)

1. Only on Joy's explicit decision: `docker compose -f deploy/docker-compose.yml --env-file deploy/.env stop scheduler`.
2. Read only, right after: `SELECT status, count(*) FROM events GROUP BY status` and `SELECT event_id, type, created_at FROM events WHERE status = 'processing'`. Any `processing` row was cut mid-drain: list it for review, leave it as it is.
3. Webhooks keep landing as `pending` (finding a). Paused with the scheduler: the scheduler tick, alerts, approvals sweep, retention, shadow ticks, delivery reconciliation. `/api/health` reports the scheduler as failing after 180 seconds; that is expected and is not an incident of its own.
4. Note the time and `SELECT max(id) FROM events` (no personal value).

### B. The release (docs/131 Stage D), which needs a candidate that includes this branch

1. Before `deploy.sh`: add `HQ_EVENTS_DRAIN_PAUSED=true` to `deploy/.env` (still mode 600). Without it, `deploy.sh`'s `up -d --remove-orphans` starts the stopped scheduler and the drain runs every pending event through the new resolver within a minute, which Stage D step 4 forbids. With it, the scheduler runs (the health tick is fresh, so the release can verify), and the drain claims nothing.
2. `deploy.sh` as documented: verified pre-deploy dump first. Note the dump's start time from the log (`verified dump:` line).
3. Stage D steps 3 to 9 with the drain paused. Events keep accumulating as pending.
4. Stage D step 10, only on Joy's authorisation after repair verification: remove the line from `deploy/.env`, then `docker compose -f deploy/docker-compose.yml --env-file deploy/.env up -d --no-deps --force-recreate scheduler` (the entrypoint caches config, so the container must be recreated to read the change). The drain then processes the backlog in id order under the new resolver; a failure retries with backoff and ends `dead` for review; the `processing` rows from A2 are decided one by one (`hq:events:replay --event=<uuid>` is idempotent, but leaves the status label; see 2(b)).

### C. A restore after the release (back to the pre-deploy dump)

Case C1, the release's code stays (for example to undo a bad repair; this is the path the bridge fully covers):

1. Pre-step, while the code runs: `docker compose ... exec -T app php artisan hq:events:export-since --since="<dump start UTC minus 10 minutes>" --out=/var/www/html/storage/app/event-bridge/restore-<stamp>.jsonl`. Keep the printed sha256. The file is personal data: it stays in the `storage` volume or under `/root`, mode 600.
2. `docker compose ... stop app horizon scheduler`, then `restore.sh` into production with `TARGET_DB` and `CONFIRM_PRODUCTION_RESTORE=yes`. Keep this window short: the shops are being answered 502 (finding c).
3. `docker compose ... up -d --no-deps app` (its entrypoint migrates the restored pre-release schema forward again), with `HQ_EVENTS_DRAIN_PAUSED=true` still set.
4. Post-step: `php artisan hq:events:import <file>` (dry run; must report no conflicting and no malformed row), then `--apply --by=<email> --reason="<why>"`.
5. `docker compose ... up -d --no-deps horizon scheduler`. The drain stays paused until B4.

Case C2, the code is rolled back too (`rollback.sh` to the pre-release commit): see section 6. Export as in C1 step 1 before anything else; it costs nothing and keeps the option open.

## 6. What is still open

- BLOCKER for using any of this in the release: the frozen candidate `a7bb85e` does not contain this branch. Without it there is no export, no import and no pause flag on production, `deploy.sh` resumes the drain on release, and `rehearse.sh` section 10 fails. The candidate has to be re-cut to include `platform/event-preservation` (or this branch merged and a new candidate frozen), with Stage A CI on the new commit.
- Code rollback (C2): `rollback.sh` starts the scheduler (`up -d --no-deps app horizon scheduler web`), and a release without this branch has no pause flag, so its drain would process every pending row (the Option 2 backlog in the dump, and anything imported) through the OLD resolver, which docs/131 section 1.1 forbids. No event is lost on this path; the problem is processing under the wrong code. Not solved here, because `rollback.sh` is a safety-tested production script and the fix (an option to leave the scheduler stopped) needs a decision. Until then: prefer C1; if C2 is unavoidable, run the import with the candidate image before `rollback.sh`, and accept that the old drain will run, or decide the `rollback.sh` option first.
- The shops' retry behaviour (finding c) is an assumption until `SendWebhookJob` is read on the deployed shop commits.
- Stranded `processing` rows after a hard stop (finding b) have no automatic path back; deciding how `hq:events:replay` should label them is a small separate change.
- The pause flag pauses only the event drain. The scheduler tick, alerts, retention and shadow ticks keep running while it is set; whether that is acceptable during containment is part of Joy's Option 2 decision.

## 7. Gate

Full Pest parallel on `hq_test_e`, Pint, `bash -n` on the changed scripts, `deploy/rehearsal/tests/rehearsal-guard-test.sh` and `deploy/tools/structural-searches.sh`: results in section 8.

## 8. Gate results (16 October 2026)

- Full Pest parallel (`DB_DATABASE=hq_test_e php artisan test --parallel --processes=3`): 2,157 passed, 0 failed (32,995 assertions).
- Pint: passed.
- `bash -n` on `rehearse.sh`, `restore.sh`, `deploy.sh`, `local-sql-rehearsal.sh`: clean.
- `deploy/rehearsal/tests/rehearsal-guard-test.sh`: 48 passed, 0 failed.
- `deploy/tools/structural-searches.sh`: S1 to S4 PASS.
- `rehearse.sh` section 10 itself needs Docker and runs only on the VPS copy with Joy's go: NOT run.

## 9. Plan: keep the scheduler stopped across a rollback or a release (open item 2, written before the code)

Branch `platform/rollback-keep-scheduler-stopped` from `main` `69a1bb1`. Nothing here touches production.

1. `deploy/rollback.sh`: an opt-in `--keep-scheduler-stopped` (or `HQ_KEEP_SCHEDULER_STOPPED=1`). With it, the rollback stops the scheduler and starts only `app horizon web`, then prints that the scheduler is left stopped and the exact command that starts it. Without it, the `up` line is unchanged. When the scheduler is not running as the rollback starts, a loud warning recommends the flag; the default is still unchanged. Any other value of the variable is refused before anything moves.
2. `deploy/deploy.sh`: the same opt-in, independent of `HQ_EVENTS_DRAIN_PAUSED` in `deploy/.env`. With it, the release's `up -d --remove-orphans` gets `--scale scheduler=0`, so compose starts everything else and leaves no scheduler running. The same warning when the scheduler is stopped and the flag is not given. Default unchanged.
3. `deploy/tests/deploy-safety-test.sh` (the CI job "Deployment/backup safety invariant", stubbed `docker`): default `up` lines unchanged (scheduler in the list), the flag and the variable exclude it, the warning is printed when the stub reports the scheduler stopped, a bad variable value is refused with nothing moved.
4. This document (sections 5 and 6) and `deploy/checklists/*.md`: the operator sequences with the flag.
5. Gate: `bash -n` on every script, the safety suite, the rehearsal guard suite, `deploy/tools/structural-searches.sh`.
