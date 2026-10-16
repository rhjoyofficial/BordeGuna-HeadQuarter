# deploy/rehearsal: migration and deployment rehearsal on a throwaway copy

Ruling `docs/120-chatgpt-ruling-2026-10-15.txt` D.1 (migration rehearsal) and D.2 (deployment rehearsal). The record of what has and has not been run is `docs/128-release-readiness-2026-10-15.md`.

There is no dedicated staging environment (`deploy/staging/` is a definition that has never been started). This rehearsal is the "disposable isolated environment built from the current deployment method" the ruling allows. It is not a staging deployment and must never be reported as one.

## Files

- `rehearse.sh`: the whole rehearsal. Restores a production dump into an isolated compose project, runs the candidate's own `deploy/deploy.sh --verify-only --no-pull` and `deploy/deploy.sh --no-pull`, checks schema, constraints, seeder and dry runs, rolls back one migration at a time until a `down()` refuses, proves the pre-deploy dump restores to the BEFORE snapshot, re-applies, compares the posture, tears down. `--plan` prints the plan and touches nothing.
- `make-compose.sh`: rewrites the candidate's `deploy/docker-compose.yml` for isolation (project name, image tags, Traefik labels, the `/root/backups/bordeguna-hq` mount) and refuses to write a file in which any of them survives.
- `sql/snapshot.sql`, `sql/verify-constraints.sql`, `sql/show-quarantine.sql`: read only (each is a `BEGIN READ ONLY ... ROLLBACK`).
- `sql/seed-corruption.sql`: WRITES. Plants the docs/121 section 5 shape plus null-store duplicates, ambiguous ownership and foreign evidence into the rehearsal copy before it migrates. Written for the `b96a396` schema.
- `tests/rehearsal-guard-test.sh`: proves every refusal fires before any docker call and writes nothing, that `--plan` calls nothing, and that `make-compose.sh` turns the REAL compose file into one that names neither `hq.bordeguna.com` nor `/root/backups`. Runs anywhere with bash: `bash deploy/rehearsal/tests/rehearsal-guard-test.sh`.

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
