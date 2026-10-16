# deploy/rehearsal: migration and deployment rehearsal on a throwaway copy

Ruling `docs/120-chatgpt-ruling-2026-10-15.txt` D.1 (migration rehearsal) and D.2 (deployment rehearsal). The record of what has and has not been run is `docs/128-release-readiness-2026-10-15.md`.

There is no dedicated staging environment (`deploy/staging/` is a definition that has never been started). This rehearsal is the "disposable isolated environment built from the current deployment method" the ruling allows. It is not a staging deployment and must never be reported as one.

## Files

- `rehearse.sh`: the whole rehearsal. Restores a production dump into an isolated compose project, runs the candidate's own `deploy/deploy.sh --verify-only --no-pull` and `deploy/deploy.sh --no-pull`, checks schema, constraints, seeder and dry runs, rolls back one migration at a time until a `down()` refuses, proves the pre-deploy dump restores to the BEFORE snapshot, re-applies, compares the posture, tears down. `--plan` prints the plan and touches nothing.
- `make-compose.sh`: rewrites the candidate's `deploy/docker-compose.yml` for isolation (project name, image tags, Traefik labels, the `/root/backups/bordeguna-hq` mount) and refuses to write a file in which any of them survives.
- `sql/snapshot.sql`, `sql/verify-constraints.sql`, `sql/show-quarantine.sql`: read only (each is a `BEGIN READ ONLY ... ROLLBACK`).
- `sql/row-hashes.sql`: read only. One line per table: row count and the md5 of every row's text in a deterministic order. Equal output means identical rows; no row content is printed. Used by `local-sql-rehearsal.sh` to prove the dry runs wrote nothing and the restore is row-for-row equal to BEFORE (docs/128 section 16).
- `sql/seed-corruption.sql`: WRITES. Plants the docs/121 section 5 shape plus null-store duplicates, ambiguous ownership and foreign evidence into the rehearsal copy before it migrates. Written for the `b96a396` schema.
- `local-sql-rehearsal.sh`: the SQL-level part a development PC can run without Docker. Exports the production commit and the candidate with `git archive`, brings a throwaway local database (`hq_rehearsal_local_<x>`) to the production schema with the production commit's own migrations and seeders, seeds `sql/seed-realistic.sql` (and, in `--scenario corrupted`, `sql/seed-corruption.sql`), runs the candidate's migrations, the permission seeder `--class`, the constraint and quarantine reads and the dry runs (detector, cost attribution, decisions report, and W2's `hq:identity:repair --recount`, each also in a read-only session, with a row-level before/after proof), rolls back one migration at a time, restores a pre-migration `pg_dump` into a second throwaway database, re-applies, and drops both databases. Executed on 16 October 2026 on `fdaa839` (docs/128 section 9) and on the candidate `a7bb85e` with `--candidate a7bb85e` (docs/128 section 16). Example: `bash deploy/rehearsal/local-sql-rehearsal.sh --vendor-from apps/hq --work <new scratch dir> --scenario corrupted --pg-bin /c/laragon/bin/postgresql/postgresql/bin`.
- `sql/seed-realistic.sql`: WRITES. Clean, realistic rows (65 customers, 193 identities, 90 orders, 6 conversations, consent, decision records, an evaluation run) for a database that has the `b96a396` schema and no data. Every row says RLOCAL.
- `tests/rehearsal-guard-test.sh`: proves every refusal of both scripts fires before any docker call and writes nothing, that `--plan` calls nothing, and that `make-compose.sh` turns the REAL compose file into one that names neither `hq.bordeguna.com` nor `/root/backups`. Runs anywhere with bash: `bash deploy/rehearsal/tests/rehearsal-guard-test.sh`.

## Running it (on the VPS, only with Joy's go)

It needs Docker (not available on the development PC) and a production dump, so it runs on the VPS, as root, from a checkout that is NOT `/opt/bordeguna-hq`:

```
git clone /opt/bordeguna-hq /root/hq-rehearsal-src && cd /root/hq-rehearsal-src && git checkout <candidate>
bash deploy/rehearsal/rehearse.sh --plan --dump /root/backups/bordeguna-hq/daily/<newest>.dump --candidate <candidate> --seed-corruption
bash deploy/rehearsal/rehearse.sh        --dump /root/backups/bordeguna-hq/daily/<newest>.dump --candidate <candidate> --seed-corruption
```

The dump is copied, never opened for writing. Evidence lands in `/root/hq-rehearsal/<UTC stamp>/evidence`. The run ends with a list of PASS, WARN and FAIL verdicts and `RESULT: rehearsal PASSED` or `FAILED`.

## What it refuses (before any docker call)

The project names `bordeguna-hq` and `bordeguna-hq-staging` and anything not `bordeguna-hq-rehearsal[-suffix]`; the database names `hq`, `postgres`, the production `DB_DATABASE` and anything not `hq_rehearsal[_suffix]`; a root inside `/opt/bordeguna-hq` or `/root/backups/bordeguna-hq`, a relative root, `/`; a missing or empty dump, a dump inside the root; a candidate that is not a commit hash; a slug outside `[a-z0-9-]`; an unknown argument. After the read-only `docker ps` probe: a project whose containers already exist, a root that already exists.

## What it never does

It never runs `deploy/rollback.sh` (a code rollback checks out the target's compose file, which is production's, with the production Traefik rule). It never connects to the production project, reads no production `.env` value except `DB_DATABASE` for the refusal, never uses ssh, never writes outside its root, and never releases anything.

## The one-way door

Migration `2026_10_15_100000_scope_customer_identities_to_business` (W1) refuses its `down()` once any `customer_identity_conflicts` row exists. On production data (docs/121 section 5) such rows will exist after the migration, so the rehearsal records the refusal and proves recovery through `deploy/restore.sh` from the verified dump `deploy.sh` takes before it releases. Rollback of this release is dump and restore, not `migrate:rollback`.

## Event bridge across a restore (docs/132; ruling docs/131 Stage B last bullet)

`deploy/restore.sh` brings `events` back to the moment of the dump. Every webhook HQ accepted after that moment was answered 202, so its shop will not send it again: without a bridge the restore loses it. `restore.sh` itself is unchanged. The bridge is a documented step on either side of it, with the candidate's own commands:

- PRE-STEP, before `restore.sh`, with the code that is running (it must include docs/132): `docker compose -f deploy/docker-compose.yml --env-file deploy/.env exec -T app php artisan hq:events:export-since --since="<start of the dump, UTC, minus 10 minutes>" --out=/var/www/html/storage/app/event-bridge/restore-<stamp>.jsonl`. Read only. The overlap with the dump is harmless: the import skips a row that is already present with the same payload. The file holds payloads (personal data): it stays in the `storage` volume or under `/root`, mode 600, like a dump.
- Then `docker compose stop app horizon scheduler` and `restore.sh` exactly as before.
- POST-STEP, after `restore.sh`, before the scheduler is started: `php artisan hq:events:import <file>` (dry run, writes nothing, must report no conflicting and no malformed row), then `php artisan hq:events:import <file> --apply --by=<email> --reason="<why>"`. The rows come back `pending`, never attempted, never processed, with one `events.imported` audit record. Run it in the `app` container when the stack runs the bridge's code; when it does not (a code rollback), see docs/132 for the sequence.
- Only then may the drain run, and only when Joy has authorised replay (docs/131 Stage D step 10). With `HQ_EVENTS_DRAIN_PAUSED=true` in deploy/.env the scheduler can run while the drain claims nothing.

`rehearse.sh` section 10 does all of this on the throwaway copy: scheduler stopped, a dump, three deliveries after it, export, `restore.sh`, proof the restore removed them, import dry run (writes nothing), import apply, proof each delivery is back with the same event_id, dedupe key and payload hash, pending and unprocessed, audited, and that a second import inserts nothing. A candidate without the two commands FAILS that section.
