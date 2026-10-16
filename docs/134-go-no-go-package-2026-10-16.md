# 134. Go/no-go package for Joy, waves 1 to 4 (16 October 2026, night)

Written for Joy under `docs/131-chatgpt-ruling-2026-10-16.txt` section 3 Stage C. It is a summary in plain English. The detail lives in `docs/127` (release notes), `docs/128` (readiness record), `docs/132` (event preservation) and `docs/133` (time-zone backfill). Nothing in this package released anything, touched production or ran a repair. Every command below was copied from those documents, and each command and flag was checked against the code on main `5bb9337`.

## 1. Verdict: NOT READY

The release is NOT READY. The code is written, merged and green on this PC. What is missing is proof that only you, GitHub or the VPS can give. The exact blockers:

- CI cannot run. GitHub Actions has refused every run since run 38912755286 because of a billing or spending-limit problem. The ruling does not accept a PC result in place of CI on the exact commit.
- No candidate is frozen. The old candidate `a7bb85e` is withdrawn: it lacks the event bridge, the drain pause, the time-zone backfill and the rollback flag. The new candidate is frozen only when CI can run on it.
- The VPS Docker rehearsal has not run. It is the only test of the real `restore.sh` and of the event bridge across a restore (`rehearse.sh` section 10). It needs your go and a fresh production dump.
- Your containment decision is open. The incident is not contained.
- Post-release checks can only happen after you release.

## 2. What this release changes, in business terms

- Each business gets its own customer identities. A Bionic order can no longer land on a LaLaDia customer because the phone matched.
- A wrong link is refused and written down as evidence, instead of being silently moved.
- Rows that look wrong are set aside (quarantined), never deleted.
- A repair tool can move the one known wrong order back to a Bionic customer. It runs only after a person has reviewed every row and you have said yes.
- New locked screens: consent history, quarantine review, follow-ups, playbooks, decision corpus. Each needs a new permission. Fourteen permissions in all.
- Three actions only a person can ever take: lifting a do-not-contact (needs a second person), recording an outcome credit, and applying an identity repair. No agent can call them.
- Shop timestamps are now read correctly as UTC. A separate tool can correct old order times that were stored six hours late, but only where the shop's own message proves it, and only on your word.
- Evaluation cost and a decision's business are now recorded.
- Nothing is switched on. AI runtime stays off. Every agent stays paused at A0. No grants. Meta stays off. No shadow week opens.
- One-way door: once the identity migration runs on your data, it cannot be undone by a migration. The way back is a restore from the backup taken just before the release.

## 3. The incident

What happened:

- One Bionic order (`facts_orders` #16, store 2, placed 7 Sep) was attached to a LaLaDia customer (#1).
- The old code looked up a phone across all businesses, not inside one business.
- The same order's two shop identities (`shop_order`, `shop_user`) were filed under that LaLaDia customer.
- You counted this on 15 Oct (docs/121 section 5). Nothing else was touched: no conversations, messages, consent, memories or merges.

Containment status now:

- NOT applied. Your decision is pending.
- The shop webhook path is still live on production. A new Bionic order with a phone LaLaDia knows could repeat the mistake.
- ChatGPT recommends Option 2: stop the HQ scheduler. Webhooks keep arriving and are kept as pending. Nothing processes them until the fix is in and the repair is checked.
- Option 1 is to accept the small risk until the release.

What the release and the repair do:

- The release quarantines the two wrong shop identities and opens two conflict records. Expected on production: 2 quarantined, 2 open conflicts, 1 cross-business order (#16).
- The release does NOT move order #16. That is the repair's job.
- The repair dry run shows one proposed split: order #16 and the two shop identities move to a NEW Bionic customer. That customer carries only the order's own phone. No LaLaDia name or e-mail is copied.
- The repair applies only after every row is reviewed, none is disputed, and you authorize it. It keeps a lineage record and verifies itself before it calls the batch done.

## 4. Evidence so far

- Tests: 2,166 Pest tests passed, 0 failed, on main `3755d27` (the merge of the time-zone work). The later merge `5bb9337` changed only deploy scripts and docs, no application code.
- Earlier gates: 2,143 on `a7bb85e`, 2,157 on the event-preservation branch, 2,152 on the time-zone branch. Pint, TypeScript and Vite clean where front-end code changed.
- Structural searches: all four PASS (identity scope, tool gateway, consent readers, playbook readers). On production's own code the same searches fail on exactly the defects this release removes.
- Deploy safety suite: the new `--keep-scheduler-stopped` flag has 56 assertions with a stubbed Docker. Rehearsal guard suite: 48 passed.
- Local rehearsal on this PC (docs/128 sections 9 and 16): both scenarios PASSED (31 PASS each on `a7bb85e`). The migrations applied to a copy of production's schema. The identity migration quarantined what it should. Its rollback refused, as designed. The pre-release dump restored back exactly, row for row.
- Dry runs write nothing: the detector, repair, cost and decisions dry runs left every row of every table identical (checked by row hashes), and also completed in a session the database held read-only. The time-zone dry run has its own tests proving it writes nothing (docs/133).
- Event preservation (docs/132): a webhook is saved to the database before HQ answers the shop. Nothing but the drain moves a pending event. Replaying an event lands once. These are proven by tests.
- Not yet proven: anything on real production rows, the real `restore.sh`, the Docker image, and the event bridge across a real restore.

## 5. What Joy must do

1. Fix GitHub billing.
   - Why: CI on the exact frozen commit is a hard gate. Without it the verdict stays NOT READY, whatever the PC says.
   - After that, Claude freezes the candidate, runs the full gate and CI, and records the run id.

2. Decide containment.
   - Why: the live webhook path can repeat the incident until the release. The ruling forbids Claude from stopping the scheduler on its own.
   - Option 2, on the VPS, in `/opt/bordeguna-hq`:
     - `docker compose -f deploy/docker-compose.yml --env-file deploy/.env stop scheduler`
   - Right after, read only, in a read-only `psql` session as in the pre-release checklist (docs/132 section 5 A2 and A4):
     - `SELECT status, count(*) FROM events GROUP BY status`
     - `SELECT event_id, type, created_at FROM events WHERE status = 'processing'`
     - `SELECT max(id) FROM events`
     - Note the time. Any `processing` row was cut mid-run. Leave it as it is and list it for review.
   - What pauses: the event drain, the scheduler tick, alerts, the approvals sweep, retention, shadow ticks, delivery reconciliation and Horizon snapshots. Orders appear in HQ only after the restart. Alerting stops, which is a monitoring loss.
   - What keeps working: webhooks keep landing and are saved as pending.
   - Expected noise: `/api/health` reports the scheduler failing after 180 seconds. That is not a new incident.
   - While Option 2 is in force, every `deploy.sh` and `rollback.sh` run carries `--keep-scheduler-stopped`. Without it, both scripts start the scheduler again.
   - Option 1: change nothing, and accept the small risk until the release. Record which you chose.

3. Authorize the VPS Docker rehearsal and a fresh production dump.
   - Why: it is the only proof that the real `restore.sh` brings production back, and that events received after the dump survive a restore. It runs beside production in an isolated throwaway project that refuses production names and paths.
   - Fresh dump (writes one new dump file, as the nightly job does): `$C exec backup /backup.sh once`, where `C="docker compose -f deploy/docker-compose.yml --env-file deploy/.env"`.
   - Then, as root, from a clone that is not `/opt/bordeguna-hq`, once the candidate exists:
     - `git clone /opt/bordeguna-hq /root/hq-rehearsal-src && cd /root/hq-rehearsal-src && git checkout <candidate>`
     - `bash deploy/rehearsal/rehearse.sh --plan --dump /root/backups/bordeguna-hq/daily/<newest>.dump --candidate <candidate> --seed-corruption`
     - `bash deploy/rehearsal/rehearse.sh        --dump /root/backups/bordeguna-hq/daily/<newest>.dump --candidate <candidate> --seed-corruption`
   - Expected: `RESULT: rehearsal PASSED`, the identity migration's rollback REFUSED, the restore proof PASS, and section 10 (event bridge) PASS.

4. Later, only when every gate above is green: the release (docs/132 section 5 B).
   - Why: this exact order stops the old `deploy.sh` from restarting the scheduler and draining old events through the new code before the repair is checked.
   - All on the VPS, in `/opt/bordeguna-hq`, with `C="docker compose -f deploy/docker-compose.yml --env-file deploy/.env"`.
   - a. Run the read-only pre-release checklist: `deploy/checklists/pre-release-readonly.md`. It saves `/root/hq-posture-before-release.txt`.
   - b. Add the line `HQ_EVENTS_DRAIN_PAUSED=true` to `deploy/.env` (keep the file mode 600). Why: whatever script runs, the drain claims nothing.
   - c. `git fetch -q origin main && git rev-parse --short origin/main`. It must print the candidate.
   - d. `git merge --ff-only origin/main`. Why: production's old `deploy.sh` refuses the new flag, so the checkout moves by hand first and the candidate's own script runs.
   - e. `./deploy/deploy.sh --verify-only --no-pull`
   - f. `./deploy/deploy.sh --no-pull --keep-scheduler-stopped`. It takes and verifies a fresh pre-deploy backup before anything is built or started. Note the `verified dump:` line: that file is your way back.
   - g. `$C ps scheduler` must show no running scheduler. Why: `--scale scheduler=0` has only been tested with a stubbed Docker.
   - h. `$C exec -T app php artisan db:seed --class='Database\Seeders\RolesAndPermissionsSeeder' --force`. Adds the fourteen permissions. Additive; a second run changes nothing.
   - i. `$C exec -T app php artisan hq:tools:sync`. Creates switch rows for the three new tools.
   - Expected: the release shows UNVERIFIED, with the scheduler as the ONLY failing component of `/api/health`, and `release.json` naming the candidate. Any other failing component is a real failure.

5. After the release: Stage D, in order. READ-ONLY steps change nothing. JOY-AUTHORIZED steps write, and each needs your separate yes after you have read its dry run.
   - Step 1 to 3 (done by step 4 above): fresh backup, frozen candidate only, then verify identity, migrations, seeder, health and posture with the post-release checklist sections 1, 2, 5 and 6 (`deploy/checklists/post-release-readonly.md`). READ-ONLY.
   - Step 4: do not resume the drain or replay anything because the deploy succeeded.
   - Step 5, READ-ONLY: identity scope checks and the detector dry run (post-release checklist section 3), including:
     - `$C exec -T -e PGOPTIONS=-cdefault_transaction_read_only=on app php artisan hq:identity:cross-business --dry-run --no-ansi`
   - Step 6, JOY-AUTHORIZED (writes append-only flags):
     - `docker compose -f deploy/docker-compose.yml --env-file deploy/.env exec -T app php artisan hq:identity:cross-business --flag`
     - Then review each row (order #16, the `shop_order`, the `shop_user`) on `/customers/quarantine`.
   - Step 7, READ-ONLY: the repair dry run. Check every row is reviewed and none is disputed.
     - `docker compose -f deploy/docker-compose.yml --env-file deploy/.env exec -T app php artisan hq:identity:repair --recount`
   - Step 8, JOY-AUTHORIZED (moves rows, writes lineage): press "Apply this repair batch" on `/customers/quarantine`, or:
     - `docker compose -f deploy/docker-compose.yml --env-file deploy/.env exec -T app php artisan hq:identity:repair --apply --key=<key> --by=<Joy's email> --reason="<why>"`
     - Expected: `Batch <key>: verified`. Then check ownership, the detector, Customer 360, inbox, Support context and the recount.
   - Step 9, cost backfill. READ-ONLY dry run:
     - `$C exec -T -e PGOPTIONS=-cdefault_transaction_read_only=on app php artisan hq:evaluation:attribute-cost --no-ansi`
     - JOY-AUTHORIZED apply (writes complete runs only; the `--apply` option is checked in `EvaluationAttributeCost`):
     - `$C exec -T app php artisan hq:evaluation:attribute-cost --apply`
   - Step 9, time-zone backfill. READ-ONLY dry run:
     - `$C exec -T -e PGOPTIONS=-cdefault_transaction_read_only=on app php artisan hq:facts:tz-backfill --no-ansi`
     - JOY-AUTHORIZED apply, with a fresh backup first:
     - `$C exec -T app php artisan hq:facts:tz-backfill --apply --key=<batch key the dry run printed> --by=<Joy's HQ e-mail> --reason="<why>" --no-ansi`
     - Each backfill is a separate decision. Authorizing one does not authorize the other.
   - Step 10, JOY-AUTHORIZED, only after the repair is verified: resume the drain.
     - Remove the `HQ_EVENTS_DRAIN_PAUSED=true` line from `deploy/.env`.
     - `docker compose -f deploy/docker-compose.yml --env-file deploy/.env up -d --no-deps --force-recreate scheduler`
     - Then `./deploy/deploy.sh --no-pull` to have the release recorded as verified.
     - The backlog drains in order under the new code. A failure retries, then ends `dead` for review. Any `processing` row from the containment step is decided one by one.
   - Stage E (the Executive shadow week) is a separate later decision. It needs the repair verified, the post-release checks passed and your explicit go.

## 6. Rollback and restore: what is and is not reversible

Reversible:

- Code. `./deploy/rollback.sh --keep-scheduler-stopped b96a396` puts production's old code back without running any migration. The flag keeps the scheduler stopped, because the old code would drain pending events through the old, leaky lookup.
- Migrations 1 to 7, 9 and 10 have a real `down()`. On clean data all twelve roll back (proven locally).
- Migrations 11 (repair tables) and 12 (time-zone corrections) roll back right after the release, while they are empty.

Not reversible by migration:

- Migration 8 (business-scoped identities) refuses its own `down()` as soon as it runs on your data, because two conflict records will exist. Dropping them would lose evidence.
- Migration 11 refuses once a flag of a new kind or a repair lineage row exists. Migration 12 refuses once a time-zone correction exists.
- The permission rows the seeder adds are never removed by any rollback, by design.

The way back, therefore (post-release checklist section 9, docs/132 section 5 C2):

- First, while the new code still runs, export the events received since the backup (READ-ONLY for the database; writes one file): `docker compose -f deploy/docker-compose.yml --env-file deploy/.env exec -T app php artisan hq:events:export-since --since="<dump start UTC minus 10 minutes>" --out=/var/www/html/storage/app/event-bridge/restore-<stamp>.jsonl`. Keep the printed sha256. The file holds personal data: mode 600.
- Then the code: `./deploy/rollback.sh --keep-scheduler-stopped b96a396`. Do not run the start command it prints.
- Then, only if the database must go back (deploy/README.md "Rollback", with the scheduler left out):
  - `$C stop app horizon`
  - `$C exec backup /restore.sh /backups/daily/<pre-deploy dump>.dump hq_restore_check` (rehearse into scratch first)
  - `TARGET_DB=hq CONFIRM_PRODUCTION_RESTORE=yes $C exec backup /restore.sh /backups/daily/<dump>.dump hq` (the README's line; `hq` must be the production database named in `deploy/.env`)
  - `$C start app horizon`. `start`, not `up`, and never name the scheduler.
- The exported events wait. Production's old code has no import command. They come back with a fixed release: dry run `hq:events:import <file>`, then `--apply --by=<email> --reason="<why>"`.
- If the new code stays and only the data goes back (for example, to undo a bad repair), docs/132 section 5 C1 is the shorter path: export, restore, import, drain still paused.

Limits of the way back:

- Any event a shop sent while HQ's app was down for the restore depends on the shop retrying. That is unconfirmed (section 7).
- The real `restore.sh` and the bridge across it are proven only once the VPS rehearsal passes. Until then the restore is proven on this PC only.

## 7. Residual risks

- Two time-zone findings are NOT fixed (docs/133): `events.occurred_at` is stored without converting to UTC, so a shop sending `+06:00` there makes event times and `facts_orders.last_event_at` six hours late, with no evidence to repair old rows. `customers.first_seen_at` and `last_seen_at` take the raw order time, so a `+06:00` value lands six hours late (expected to affect ERP read-backs only). Each needs its own decision.
- Shop retry behaviour is an assumption. HQ's notes say LaLaDia retries a failed delivery a few times and treats a 202 as final. Neither deployed shop has been checked.
- A hard stop can leave event rows stuck at `processing`. Nothing reclaims them automatically. A person decides each one.
- `--scale scheduler=0` is untested on real Docker. The check `$C ps scheduler` after the release covers it.
- While the scheduler is stopped, the release shows UNVERIFIED. Expected; it is verified after step 10.
- While Option 2 is in force, alerts and sweeps are paused. You are without HQ alerting until step 10.
- Ambiguous identity data can remain after the repair. Anything whose business cannot be proven stays quarantined and listed, never guessed. No screen settles an open identity conflict yet (next wave).
- The `conversations` unique key is still global. A collision fails closed. It is fixed in the next wave, before more Meta traffic.
- There is no staging environment. The rehearsal is a throwaway copy, not a staging deployment.
- A detector with findings is not a clean-data certificate. The real production numbers are known only after the post-release checklist.
