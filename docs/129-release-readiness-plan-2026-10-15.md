# 129. Release readiness plan, W7 (15 October 2026)

Branch `platform/release-readiness-waves-1-4` from main `7fb5e71`, worktree `hq-wt/j`, test database `hq_test_j`. Authority: `docs/120-chatgpt-ruling-2026-10-15.txt` section D (points 1 to 6) and section C Stage 6; the workstream is W7 of `docs/120-wave-4-plan-2026-10-15.md`. Nothing here releases anything, touches production, or touches the app code of identities, outcomes, follow-ups, playbooks or consent (W2 owns the repair on `p3/identity-repair`). Joy alone releases.

## What this workstream delivers

1. `docs/127-release-notes-waves-1-4-2026-10-15.md`: consolidated release notes, main against production `b96a396`: every migration since `b96a396` in order with its `down()` verdict, data backfills, seeder changes (every new permission), new tools and pins, feature flags before and after, config keys, visible changes, known limitations, and a candidate manifest with placeholders filled at freeze.
2. `deploy/rehearsal/`: the migration and deployment rehearsal (ruling D.1 and D.2) against an isolated throwaway compose project built from the current deploy method (`deploy/deploy.sh`, `deploy/docker-compose.yml`, `deploy/backup.sh`, `deploy/restore.sh`, the real Dockerfile and entrypoint). Restores a given dump, records counts and schema before, runs the candidate's `deploy.sh --no-pull` (which migrates through the entrypoint), runs the permission seeder, the detector dry run, the cost dry run and (when the command exists) the repair dry run, verifies the W1 constraints, rolls back one migration at a time until a `down()` refuses, proves the pre-migration counts and the backup-restore path, re-applies, and has a seeded-corruption mode (the docs/121 section 5 shape) that shows what the W1 migration quarantines. Refuses to run against the production project name, the production database name, or a root inside the production checkout. Guard logic tested by a bash suite with a stubbed `docker`.
3. `deploy/checklists/pre-release-readonly.md` and `post-release-readonly.md`: exact commands and expected outputs for everything ruling D.3 and D.4 list, each command demonstrably read-only with the reason it cannot write.
4. Stage 6 structural searches: `deploy/tools/structural-searches.php` (framework-free, runs inside the production image with `php < file`), the wrapper `deploy/tools/structural-searches.sh` that runs the searches and the existing Pest pins and prints pass or fail, and a Pest test that runs the searches in CI with a negative control.
5. `docs/128-release-readiness-2026-10-15.md`: the readiness record in the D.6 report shape, each section filled from evidence on main today or marked PENDING with what produces it; the staging limitation stated.

## Facts that shape the plan

- Docker Desktop is NOT running on this machine (`docker info` fails), so the rehearsal cannot be executed here. It is built to run on the VPS in an isolated compose project (`bordeguna-hq-rehearsal`, its own volumes, no Traefik labels, no production backup mount, a fresh `APP_KEY` so restored credentials cannot be decrypted) and the record says plainly that it has NOT been executed and what command executes it.
- There is no dedicated staging environment (`deploy/staging/` is a definition only, never started). The rehearsal is the disposable isolated environment the ruling allows; it is not a staging deployment and will not be called one.
- The W1 migration's `down()` refuses once any conflict row exists; on production data (one store-scoped `shop_user` and one `shop_order` under the wrong business, docs/121 section 5) it WILL refuse. The rehearsal therefore treats "rollback" as: roll back the real `down()`s until the refusal, record it, and prove recovery through `deploy/restore.sh` from the pre-deploy dump that `deploy.sh` takes first.
- Production reads are refused to me; every production command in the checklists is for Joy to run.

## Order of work

1. This plan (committed first).
2. Release notes (docs/127) from the migrations, seeder, config and route diffs `b96a396..HEAD`.
3. Rehearsal tooling and its guard tests; `bash -n` on every script; the compose rewrite tested against the real `deploy/docker-compose.yml`.
4. Structural searches (PHP + wrapper + Pest), run on main today, allow-lists verified.
5. Checklists.
6. Readiness record (docs/128).
7. Gate: Pest for the new test, `bash -n`, Pint; no em dash anywhere; push the branch, no merge.
