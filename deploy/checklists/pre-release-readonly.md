# Pre-release read-only checklist (ruling docs/120 D.3)

For Joy, on the VPS, BEFORE a release of waves 1 to 4. Every command below only reads. Nothing here releases, repairs, backfills, flags or switches anything. The readiness record is `docs/128-release-readiness-2026-10-15.md`.

Production today runs `b96a396`. Three of the commands D.3 names (the cross-business detector, the cost-attribution dry run, the unassigned-decisions report) do not exist in `b96a396`: they arrive with the release. Before the release they run on the throwaway copy in the rehearsal (`deploy/rehearsal/rehearse.sh`, section 8 below); on production they run in the post-release checklist. On production, before the release, their SQL twins run instead.

## 0. Conventions

```
ssh vps
cd /opt/bordeguna-hq
C="docker compose -f deploy/docker-compose.yml --env-file deploy/.env"
```

Why these cannot write, once for all sections:

- Every `psql` runs with `-e PGOPTIONS=-cdefault_transaction_read_only=on`: PostgreSQL itself makes every transaction of that session read-only and answers any INSERT, UPDATE, DELETE or DDL with `ERROR: cannot execute ... in a read-only transaction`. Every SQL file here also wraps itself in `BEGIN READ ONLY ... ROLLBACK`.
- Every `artisan` command runs with the same `-e PGOPTIONS=...` on `exec`: PHP's PostgreSQL driver (libpq) reads `PGOPTIONS`, so the application's own database session is read-only too. A command that tried to write would stop with SQLSTATE 25006 instead of writing. (Proven on the local rehearsal, docs/128 section 9: the detector, cost and decisions commands completed under it.)
- `exec -T` starts no new container; nothing here runs `up`, `build`, `migrate`, `db:seed`, `--flag`, `--apply`, `tools:toggle`, `shadow:open` or any `deploy.sh` without `--verify-only`.
- Not covered by `PGOPTIONS`: Redis and log files. None of these commands queues a job or writes the cache; Laravel may append to `storage/logs`. Neither is a record of business data.

## 1. Release identity, health and migration state

```
git rev-parse --short HEAD
docker inspect -f '{{ index .Config.Labels "com.bordeguna.commit" }}' $($C ps -q app)
cat deploy/release-state/release.json
$C exec -T app php -r 'echo @file_get_contents("http://web/api/health", false, stream_context_create(["http" => ["ignore_errors" => true, "timeout" => 10]])) ?: "{}";'; echo
$C exec -T -e PGOPTIONS=-cdefault_transaction_read_only=on app php artisan migrate:status --no-ansi | tail -3
./deploy/deploy.sh --verify-only
```

Expected: `b96a396` from git and from the image label; `release.json` names `b96a396` with `"verification":{"status":"passed"`; `/api/health` overall `ok` with `"release":"b96a396"`; `migrate:status` ends with `2026_10_04_100000_create_workflow_tables ... Ran` and lists no `Pending`; `--verify-only` ends with `0 FAIL`.

Why read-only: `git rev-parse` and `docker inspect` read metadata; `cat` reads a file; `/api/health` is the GET the monitor makes every five minutes; `migrate:status` SELECTs the `migrations` table in a read-only session; `verify-only.sh` is read-only by construction (its header lists every command it runs; it sets `GIT_OPTIONAL_LOCKS=0` so even `git status` writes nothing).

## 2. Identity scope (the detector's pre-release twin) and the cross-scope checks

```
$C exec -T -e PGOPTIONS=-cdefault_transaction_read_only=on db sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 -f -' < deploy/tools/identity-scope-readonly.sql
```

Covers D.3's "identity cross-business detector dry-run", "customer/order/conversation/message/participant cross-scope checks" and "ambiguous identity counts" on the `b96a396` schema. Expected (docs/121 section 5, counted by Joy on 15 October; any increase is a new finding and stops the release):

- liveness: `order.created` and `order.updated` processed, nothing pending; no `customer.registered`, no `conversation.inbound_received`;
- cross-business rows: `facts_orders` 1 (order #16, Bionic, on LaLaDia customer #1); conversations, participants, messages, consent events, consent state, memories, cross-business merges all 0;
- store-scoped identities under another business's customer: `shop_order` 1, `shop_user` 1; phone identities matched to another business's orders: 1; identities whose evidence names more than one business: 3;
- duplicate null-store identities: 0; identities re-pointed away from their order's customer: 0.

Why read-only: the file is SELECTs inside `BEGIN READ ONLY ... ROLLBACK`, in a read-only session; it prints counts and row ids, never a phone, e-mail or message.

## 3. Posture: permissions, agents, autonomy, grants, AI runtime, Meta, shadow, scheduler, consent, cost rows

```
$C exec -T -e PGOPTIONS=-cdefault_transaction_read_only=on db sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -At -v ON_ERROR_STOP=1 -f -' < deploy/tools/release-posture-readonly.sql | tee /root/hq-posture-before-release.txt
$C exec -T -e PGOPTIONS=-cdefault_transaction_read_only=on db sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -At -c "SELECT count(*) FROM permissions" -c "SELECT r.name || chr(32) || count(rp.permission_id) FROM roles r LEFT JOIN role_has_permissions rp ON rp.role_id = r.id GROUP BY r.name ORDER BY r.name"'
```

Expected:

- `release_db <db> read_only=on` (the session really is read-only), `migrations 57 last=2026_10_04_100000_create_workflow_tables`;
- `flags_on_that_must_be_off 0` (`ai.runtime`, `channels.meta.inbound`, `channels.meta.forwarded` off or absent);
- `scheduler_gateway_row 1` or `0`: the obsolete row may still exist; it stays until section 7 of the post-release checklist;
- every `agent ... status=paused max_autonomy=a0`, `agents_not_paused_or_above_a0 0`, `autonomy_grants_active 0`, `shadow_periods_open 0`;
- `scheduled_jobs state=paused` includes jobs 2 and 6 (docs/121 section 5);
- `consent_events` and `consent_state` 0 rows (production holds no consent rows), `consent_projection_drift 0`;
- `evaluation_cost_attribution column_absent (pre-release schema)`;
- permissions: the count before the release (the release's seeder adds thirteen; keep the number for the post-release comparison).

Keep `/root/hq-posture-before-release.txt`: the post-release checklist compares against it. `tee` writes that one file on the host, outside the repository and outside the database.

Why read-only: as section 2.

## 4. Cost: what HQ has spent and what evaluation runs carry

```
$C exec -T -e PGOPTIONS=-cdefault_transaction_read_only=on app php artisan hq:ai:cost --no-ansi
```

Expected: the month's AI spend (no model has been called while `ai.runtime` is off; `0 runs` is the expected line unless a probe ran). The cost-attribution dry run and its completeness report (`hq:evaluation:attribute-cost`) do not exist in `b96a396`; they run on the rehearsal copy (section 8) and post-release.

Why read-only: `hq:ai:cost` reads `ai_runs`; the session is read-only.

## 5. Scheduler and Gateway configuration; no effectful-execution bypass

```
$C exec -T app php -- --root /var/www/html < deploy/tools/structural-searches.php
$C exec -T -e PGOPTIONS=-cdefault_transaction_read_only=on db sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -At -c "SELECT id || chr(32) || name || chr(32) || state || chr(32) || coalesce(tool_key, chr(45)) FROM scheduled_jobs ORDER BY id"'
```

Expected on `b96a396` (measured on 16 October against the source of `b96a396`, docs/128 section 6): `FAIL S1 identity-scope` with five offenders (the unscoped lookups in `ConversationService` and `IdentityResolver`: the leak this release fixes), `FAIL S2 tool-gateway` with five offenders (`RunScheduledJob` calls `$tool->execute(` and `$tool->reconcile(` directly behind the `scheduler.gateway` flag; `FactsReconcile` reaches the reconciler around its tool: the bypass docs/116 retired), `PASS S3`, `PASS S4`, `RESULT: 2 passed, 2 failed`. These two FAILs are the known defects the release removes; after the release the same command must print `RESULT: 4 passed, 0 failed` (post-release checklist section 6). Scheduled jobs: the paused ones stay paused.

Why read-only: the search script is piped into `php` on standard input; it reads PHP source files and prints, opens no database and writes no file.

## 6. Containment state (docs/121 section 5)

Not a command: confirm which containment option Joy chose (Option 1 accept the small risk until the release, or Option 2 stop the HQ scheduler) and that nothing has changed since. If Option 2: `$C ps scheduler` shows it stopped, and the release follows docs/132 section 5 B: `HQ_EVENTS_DRAIN_PAUSED=true` in `deploy/.env`, and `./deploy/deploy.sh --no-pull --keep-scheduler-stopped` run by the candidate's script (production's `b96a396` `deploy.sh` refuses the flag and moves nothing; it silently ignores `HQ_KEEP_SCHEDULER_STOPPED`, so never rely on the variable with it). A default `deploy.sh` or `rollback.sh` starts the scheduler again.

## 7. What these checks are not

A detector dry run with findings, or quarantine rows, is not a clean-data certificate. Neither quarantine repair nor cost backfill runs here (ruling D.5): each needs its own dry run, review and Joy's explicit authorization after the release is verified.

## 8. The rehearsal (D.1, D.2), on a copy, with Joy's go

The detector, cost and decisions dry runs on production-shaped data run in the rehearsal, which restores the newest verified dump into an isolated throwaway project:

```
git clone /opt/bordeguna-hq /root/hq-rehearsal-src && cd /root/hq-rehearsal-src && git checkout <candidate>
bash deploy/rehearsal/rehearse.sh --plan --dump /root/backups/bordeguna-hq/daily/<newest>.dump --candidate <candidate> --seed-corruption
bash deploy/rehearsal/rehearse.sh        --dump /root/backups/bordeguna-hq/daily/<newest>.dump --candidate <candidate> --seed-corruption
```

It writes only inside its own project, volumes and `/root/hq-rehearsal/<stamp>`, and refuses the production project name, database name and paths before any docker call (`deploy/rehearsal/tests/rehearsal-guard-test.sh`). Expected: `RESULT: rehearsal PASSED`, with the W1 migration's `down()` REFUSED and the restore proof PASS.
