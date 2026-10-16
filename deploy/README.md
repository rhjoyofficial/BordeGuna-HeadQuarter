# Deploying BordeGuna HQ to srv1808022

Runbook for the `bordeguna-hq` compose stack on the existing Hostinger KVM 2 (ADR-012). The ERP,
n8n, Zeno and Safor stacks on the same host are never touched by any step here.

## Layout on the VPS

```
/opt/bordeguna-hq/            git clone of this repository (deploy key github-hq)
/opt/bordeguna-hq/deploy/.env production environment, mode 600, never in git (template: deploy/.env.example)
/root/backups/bordeguna-hq/   nightly pg_dump archives (+ LAST_RUN, LAST_STATUS, LAST_OK). LOCAL ONLY, not disaster-proof (docs/28)
/opt/bordeguna-hq/deploy/release-state/  release.json, last-verified.json, main.json: written by the host, mounted READ-ONLY into app/horizon/scheduler (docs/80 C.4). Not in git.
/var/lib/hq-monitor/          monitor state (alert edges)
/root/.hq-monitor.env         TELEGRAM_BOT_TOKEN / TELEGRAM_CHAT_ID for the host monitor, mode 600
```

Containers: `bordeguna-hq-{app,web,horizon,scheduler,db,redis,backup}-1`. Only `web` is reachable, through
Traefik, as `https://hq.bordeguna.com`. Traefik labels live on `web`; Traefik itself is never edited.

## Pre-deploy checklist (record results in CAPACITY.md)

1. `swapon --show` shows 4 GB; `sysctl vm.swappiness` = 10.
2. `free -h`, `docker stats --no-stream`, `df -h /` recorded.
3. `docker ps` all Up, none restarting; `ufw status` = 22/80/443 only; `systemctl is-active fail2ban` = active.
4. Traefik healthy and issuing certificates (`docker logs traefik-rsf3-traefik-1 --tail 50`).
5. ERP, automation and Zeno backups fresh (`ls -t /root/backups/*/daily | head`).
6. ERP restore drill done and recorded (RESTORE-DRILL.md); the ERP's own nightly local dumps are fresh.
7. DNS: `hq.bordeguna.com` resolves to 200.97.162.40 (`nslookup hq.bordeguna.com 8.8.8.8`).
8. Disk >= 40 GB free; RAM available >= 2 GB before starting HQ.
9. Monitor cron installed: `*/5 * * * * /opt/bordeguna-hq/deploy/hq-monitor.sh >> /var/log/hq-monitor.log 2>&1`.

## First deployment

```bash
ssh vps
git clone github-hq:rhjoyofficial/BordeGuna-HeadQuarter.git /opt/bordeguna-hq
cd /opt/bordeguna-hq
cp deploy/.env.example deploy/.env && chmod 600 deploy/.env
# fill deploy/.env: APP_KEY (docker run --rm php:8.3-cli php -r "echo 'base64:'.base64_encode(random_bytes(32));"),
#   DB_PASSWORD, REDIS_PASSWORD (openssl rand -base64 32), Telegram alert values. Secrets live in this file only.
mkdir -p /root/backups/bordeguna-hq
./deploy/deploy.sh --no-pull
docker compose -f deploy/docker-compose.yml --env-file deploy/.env exec app php artisan db:seed --force
docker compose -f deploy/docker-compose.yml --env-file deploy/.env exec app php artisan hq:user:create owner@bordeguna.com --role=owner --generate-password
```

Then in a real browser: `https://hq.bordeguna.com` -> login -> mandatory 2FA enrolment -> dashboard.
Verify: DNS resolves, certificate valid, `http://` redirects to `https://`, `/up` 200, `/api/health` all `ok`.

## Release (every later deploy)

```bash
ssh vps && cd /opt/bordeguna-hq && ./deploy/deploy.sh --verify-only && ./deploy/deploy.sh
```
The script: refuses an argument it does not know (until docs/81 C.3 a typing mistake such as
`--nopull` meant "pull") -> refuses without a 600 `.env` -> takes the host lock (docs/81 C.9: one
deploy or rollback at a time; a second one refuses at once, names the one running, and moves
nothing) -> refuses a `deploy/.env` that sets `RUN_MIGRATIONS` to anything but 0 -> verifies the backup mount -> takes a
pre-deploy dump (`/backup.sh once`) and verifies it -> ONLY THEN fast-forwards to `origin/main` ->
builds `app` and `backup` images (the commit is stamped into the image as a label and as
`HQ_RELEASE_COMMIT`) -> `up -d` (the `app` entrypoint runs `migrate --force`) -> `horizon:terminate`
(graceful) -> waits for `/up` -> **verifies the release**: `/api/health` overall `ok` AND naming the
commit just released (retried for about 90 s) -> appends to `deploy/RELEASES.md` with the
verification result -> writes `deploy/release-state/release.json`, and `last-verified.json` only when
the verification passed -> refreshes `main.json`. CI must be green on the commit first (never deploy
ahead of CI); until the 1 October reset the merge gate is the full local verification instead
(`docs/81` A point 14), and the release record says "local verification", never "CI green".

A release whose health never comes good still exits 0 (the stack is up) but is logged and recorded as
**UNVERIFIED**, and the Platform dashboard says so. Fix what health reports, then
`./deploy/deploy.sh --no-pull` to release the same commit again and have it verified.

**One release at a time (docs/81 C.9).** `deploy.sh` and `rollback.sh` hold `flock` on
`/tmp/bordeguna-hq-deploy.lock` (override: `HQ_DEPLOY_LOCK`) for as long as they run, the gate runner's
pattern. "another deploy or rollback is already running on this host (pid=... kind=... started=...)"
means exactly that: wait for it, then run yours. Nothing needs removing by hand, ever: the kernel
drops the lock when the process ends, however it ends, and a killed release only leaves its one-line
record, which the preflight WARNs about and the next release overwrites. ⚠ A `deploy.sh` older than
this (production's copy until the first release that carries it) takes no lock and cannot see one.

### Preflight: `./deploy/deploy.sh --verify-only [--no-pull]` (docs/81 C.3)

It CHANGES NOTHING and RELEASES NOTHING: no fetch, no checkout, no `mkdir`, no dump, no build, no
`up`, no migration, no write to `RELEASES.md`, `release-state/` or the backup root. It walks the real
preflight and prints `ok`, `WARN` or `FAIL` for each check with its reason, then exits 1 if anything
FAILED. It is handed over to `deploy/verify-only.sh` before `deploy.sh` has done anything.

It checks: the checkout (branch or detached, no tracked file modified); the host lock, by reading its
record and never opening it (FAIL while a deploy or rollback runs, WARN for the record of one that was
killed, FAIL if the lock could not be opened or `flock` is missing); `deploy/.env` (present, mode
600, every key the compose file interpolates or the application needs, by NAME only, never a value;
FAIL on a `RUN_MIGRATIONS` other than 0, WARN on a 0);
docker, compose and `compose config -q`; what is running (the image label) and whether the database
is up; `/up`, `/api/health` and that health names the running commit; the backup boundary WITHOUT
a dump (host directory writable, the container's `/backups` the same inode as the host directory,
free space against `VERIFY_MIN_FREE_MB` (default 5120), `LAST_STATUS`, the `LAST_OK` dump present
and not older than `VERIFY_MAX_DUMP_AGE_HOURS` (default 26)); origin reachable by `git ls-remote`
(which moves no ref) and the target a fast-forward of the checkout; what the release brings (the
commits, the migration files, any `up()` that drops, renames or changes a column or table, any
migration file modified rather than added, `.env.example` keys this host lacks, the target's compose
file validated without being checked out, the scripts still executable); the release-state
directory and its mount.

A clean result means "nothing visible from here stands in the way". It is not a proof that the deploy
will succeed: the dump, the build and the migrations are where a release most often fails, and only
`./deploy/deploy.sh` tries them. It never fetches: when origin's main has moved past what this
checkout has fetched it says so and describes the last fetched main.

`HQ_ROOT=/opt/bordeguna-hq bash <copy of verify-only.sh>` runs a copy against the checkout from
anywhere else; that is how it is run before the first deploy that carries it (next section).

## Release state: production against main (docs/80 C.4)

`/observe/platform` shows what production RUNS, the last VERIFIED release, where main stands, and what
is merged but not released (commit subjects and the migration files main has and this release does
not). Nothing merged to main is described as live. The container never runs git and never calls GitHub;
it reads one baked-in value and three files the host writes:

| Fact | Written by | When |
| --- | --- | --- |
| the running commit (`HQ_RELEASE_COMMIT` in the image; also `checks.application.release` in `/api/health`) | `docker build --build-arg GIT_COMMIT` in `deploy.sh` and `rollback.sh` | every build |
| `release.json`: the last release ATTEMPT, released or failed, with its verification | `deploy.sh`, `rollback.sh` | only after the checkout has moved, which is only after a verified dump. A refused deploy writes nothing |
| `last-verified.json` | `deploy.sh`, `rollback.sh` | only when the post-release verification passed. A failed release never touches it |
| `main.json`: main's head, commits ahead and behind, up to 50 subjects, migrations added | `deploy/release-state-refresh.sh`, called by `hq-monitor.sh` (every 5 min) and at the end of a deploy | `git fetch origin main` at most every 30 minutes, 60 s timeout; a failure is logged, never paged |

The panel treats every way of not knowing as "main state unknown" with the reason and the age (no
file, unreadable file, last successful fetch older than 90 minutes, a comparison made against some
other release). A missing file never reads as "up to date".

⚠ `deploy/release-state/` is bind-mounted as a DIRECTORY. If it is deleted and recreated on the host,
the running containers keep the dead inode (the same trap as the backup directory, docs/58) and the
panel will say the records are missing. Recreate the containers: `$C up -d --force-recreate --no-deps app horizon scheduler`.

### ⛔ NOT YET APPLIED TO PRODUCTION: the release after `87d8658` (Stream M, `docs/83`), exactly what Joy types

Production runs `87d8658` (released and verified 21 September, 06:32 UTC; next section). Its
`deploy.sh` already has the preflight, the refusal of unknown arguments and the release records, but
NOT the host lock or the `RUN_MIGRATIONS` refusal of `docs/81` C.9: this release is run by that copy,
so the lock protects the releases AFTER it. The candidate is named in `docs/83` C.1 once the combined
tree has passed its full local verification; call it `<candidate>` here. It is the build Gate 11
attempt 2 measures, so nothing else is released between it and the gate.

```bash
ssh vps
cd /opt/bordeguna-hq

# 1. Where main is. Moves only the origin/main ref.
git fetch -q origin main
git rev-parse --short origin/main     # must print <candidate>; anything else: STOP

# 2. Preflight. Changes nothing. Must end "RESULT: no failure found"; read any WARN.
./deploy/deploy.sh --verify-only

# 3. The release (backup first, then the fast-forward, build, up, verification).
./deploy/deploy.sh

# 4. Look.
cat deploy/release-state/release.json   # status released, verification passed, health_release <candidate>
```

**Rollback, if the release is wrong:** `./deploy/rollback.sh 87d8658` (code only, no migration runs).

### ✅ APPLIED 21 SEPTEMBER 2026 (06:19 and 06:32 UTC, by Joy): the release of `87d8658`, as it was written

Kept as the record of what was typed. Production then ran release `3314c54` (15 September), which
predated `docs/80` C.4 (release state) and `docs/81` C.3 (the rollback invariant, `--verify-only`, the
refusal of unknown arguments). The candidate was `87d8658` (`docs/81` D.2); `<candidate>` below.

**What that deploy does.** The FIRST run is executed by production's OLD `deploy.sh` (the `3314c54`
copy: bash keeps reading the file it started with even after the fast-forward replaces it on disk).
It takes and verifies its dump, fast-forwards to `origin/main`, builds, starts the stack (the `app`
entrypoint runs the candidate's new migrations, all additive), restarts Horizon, waits for `/up` and
appends to `RELEASES.md`. It writes no release record, because the old script has no such step, so
the Platform panel honestly says "UNVERIFIED: this host has no record of releasing this commit". The
SECOND run, `--no-pull`, is the NEW script against the same commit: it refuses unknown arguments,
takes its own verified dump, rebuilds from cache, verifies the release through `/api/health` (which
now names the commit) and writes `release.json` and `last-verified.json`. `docker compose up` creates
`deploy/release-state/` for the new read-only mount; nothing needs creating beforehand.

**The lines, in order, each checked before the next:**

```bash
ssh vps
cd /opt/bordeguna-hq

# 1. See where main is. Moves only the origin/main ref: the checkout and the stack stay as they are.
git fetch -q origin main
git rev-parse --short origin/main     # must print <candidate>; if it prints anything else, STOP

# 2. Preflight, from a copy of the candidate's own verify-only.sh (production's checkout has none yet).
git show origin/main:deploy/verify-only.sh > /tmp/hq-verify-only.sh
HQ_ROOT=/opt/bordeguna-hq bash /tmp/hq-verify-only.sh; rm -f /tmp/hq-verify-only.sh
#    Must end "RESULT: no failure found". WARNs to expect this once: the release-state directory does
#    not exist yet or is not mounted; /api/health names no commit (3314c54 predates it). Read any other
#    WARN before going on. Any FAIL: stop and read it.

# 3. The release, run by the OLD script.
./deploy/deploy.sh

# 4. The same commit again, run by the NEW script, preflighted first.
./deploy/deploy.sh --verify-only --no-pull
./deploy/deploy.sh --no-pull

# 5. Look.
cat deploy/release-state/release.json deploy/release-state/last-verified.json
curl -s https://hq.bordeguna.com/api/health    # checks.application.release names <candidate>
tail -3 deploy/RELEASES.md
```

Then in the browser: `/observe/platform` shows the Release state panel with `<candidate>` as running
and verified. **Rollback, if the release is wrong:** `./deploy/rollback.sh 3314c54`. It is code only
(section "Rollback" below): no migration runs, the candidate's additive tables stay, and it is
recorded as UNVERIFIED because `3314c54`'s health names no commit. That is expected and honest.

**Checks that were already on this list for `docs/80` C.4, still true:**

1. **No new cron line.** The refresher runs from the existing `*/5` `hq-monitor.sh` line. Check that
   the production crontab still calls `/opt/bordeguna-hq/deploy/hq-monitor.sh` (so it picks up the
   new script from the checkout), and that the new scripts are executable:
   `ls -l deploy/release-state-refresh.sh deploy/release-state-lib.sh deploy/verify-only.sh deploy/backup-first-lib.sh`.
2. **Check that root's cron can fetch.** `cd /opt/bordeguna-hq && env -i HOME=/root PATH=/usr/bin:/bin GIT_TERMINAL_PROMPT=0 git fetch origin main`
   must succeed without a prompt (it uses the same `github-hq` deploy key as a deploy). If it cannot,
   the panel will say "the host has never fetched main successfully" rather than anything wrong, and
   the deploy-time fetch still refreshes `main.json` at each release.
3. **Look at the monitor.** `tail /var/log/hq-monitor.log` shows no `release-state` error.

**Added by `docs/81` C.8 (the scheduler's zombie leak, found 21 September):**

4. **The scheduler gains a reaper (`init: true`), so `up` recreates it.** Until then its PID 1 was
   `schedule:work`, which never reaped the orphans of `runInBackground`: on 21 September it held
   9,482 zombies against a `pids.max` of 9,483, could not fork, and had run nothing since about
   18 September 11:40 UTC. It was restarted on 21 September at 05:06 UTC and leaks about 2.1 processes a
   minute (3,000 a day; the ceiling is reached in about 72 hours), so **if this deploy has not happened
   by 23 September, restart it once more**
   (`$C restart scheduler`; it switches nothing on, and the daily jobs skip missed slots). After the
   deploy, `docker stats --no-stream --format '{{.PIDs}} {{.Name}}' | grep hq-scheduler` should stay flat,
   in single or low double digits, over a day, and NOT climb by about 2 a minute as it did before; and `docker top bordeguna-hq-scheduler-1` shows `docker-init`
   as the first process.
5. **The monitor now reads HQ's 503.** From the first `*/5` run after the deploy, `/var/log/hq-monitor.log`
   names failing components (`hq_scheduler`, `hq_queue`, with the detail) instead of
   `hq_reachable ... unreachable`. (The 18 September `hq_reachable` alert already recovered at 05:10 UTC
   on 21 September: once the restarted scheduler made health 200, the old busybox fallback could read it.)

### ✅ APPLIED 21 SEPTEMBER 2026 EXCEPT THE KEY: OpenAI as the model provider (docs/81 C.7, ADR-049)

Steps 1 and 3 were done on 21 September (the switch audited as #679 under Joy's name; `hq:ai:provider`
reads `serving: openai`). Step 2 was done with a key from an OpenAI account that has no credit, so
step 4 answered `provider_blocked_by_credit`: step 2 is repeated with a key from the Bor de Guna
organisation (`docs/83` C.4, item 1), then step 4. Step 5 follows the release above.

The release that carries branch `ai/openai-provider` switches nothing on: the OpenAI driver is
registered, but no OpenAI row exists until the seeder runs, and a seeded OpenAI row is created OFF.
Anthropic keeps serving until a person moves it. Every line below runs on the VPS, in
`/opt/bordeguna-hq`, with `C="docker compose -f deploy/docker-compose.yml --env-file deploy/.env"`.

1. **Seed the rows, switched off.** `$C exec -T app php artisan db:seed --class='Database\Seeders\ModelTierSeeder' --force`,
   then `$C exec -T app php artisan hq:ai:provider`: it must say `serving: anthropic` and list four
   `openai` rows as `off` (Luna low, Terra medium, Sol medium at priority 90, Sol high).
2. **Joy stores the key** on https://hq.bordeguna.com/credentials (hard-reload first) as provider
   `openai_api_key`, account ref `hq`, type `api_key`, and checks that the new row is listed
   (`docs/runbooks/openai-billing.md`).
3. **Dry run, then the switch, under Joy's name.** `$C exec -T app php artisan hq:ai:provider openai`
   lists every row it would switch; then
   `$C exec -T app php artisan hq:ai:provider openai --apply --by=<Joy's HQ email> --reason="Owner decision 21 Sep: OpenAI serves while the Anthropic account is blocked"`.
   It refuses while a shadow period is open, without the key, or without a row at each chat tier. It
   touches no switch, agent or grant; `ai.runtime` stays off.
4. **Verify the key**, one call of 16 output tokens: `$C exec -T app php artisan hq:credentials:health --verify-model`.
   The `hq` row must name `openai_api_key` and read `verified`. `provider_blocked_by_credit` means the
   account needs credit or a higher limit (the key is fine); `credential_invalid` means store it again.
5. **The gate.** `deploy/gate/run.sh` now checks the serving provider's key at its bypass step, prints
   the serving provider and OpenAI's reasoning effort in its dry probe, and after the runtime is on runs
   `hq:ai:probe --live --schema --task=operator_question`, stopping before any case if the provider does
   not handle the agent schema. Gate 11 attempt 2 is then the first gate that measures an OpenAI model
   (`gpt-5.6-terra` for the Executive's turns).
6. **Going back to Anthropic** later is step 3 with `anthropic`, after its account has credit, and needs
   its own complete gate before any shadow week runs on it.

## Rollback

```bash
ssh vps && cd /opt/bordeguna-hq && ./deploy/rollback.sh <sha>
ssh vps && cd /opt/bordeguna-hq && ./deploy/rollback.sh --keep-scheduler-stopped <sha>   # scheduler left stopped
```

**The scheduler (docs/132 section 9).** By default step 3 below starts `app horizon scheduler web`, so a
scheduler stopped for containment (Option 2) comes back and drains every pending event through the code
rolled back to. `--keep-scheduler-stopped` (or `HQ_KEEP_SCHEDULER_STOPPED=1`) stops it and starts only
`app horizon web`; the last line says so and prints the one command that starts it. `deploy.sh` takes the
same flag (its `up` gets `--scale scheduler=0`). Without the flag, a scheduler found stopped gets a loud
warning and 10 seconds to abort, before the dump; nothing else changes.

**The invariant (docs/81 C.3): a rollback changes code and nothing else. It never runs a migration,
up or down, and it writes nothing to the database itself. It happens only behind a verified dump,
exactly like a deploy.** Until docs/81 C.3 the script said "no migrations" and ran them: it set
`RUN_MIGRATIONS=0` on `docker compose build`, where it reaches nothing, while `docker-compose.yml`
hardcodes `"1"` on `app` and the entrypoint migrates whenever it reads 1. It also moved the checkout
with no dump.

In order:

1. **Refusals, each a read, so a refused rollback moves nothing and takes nothing (not even a dump):**
   no target or more than one argument; `deploy/.env` missing or not mode 600; a target the checkout
   does not know; the target is what is already running (read from the image label, not the
   checkout); the target is neither an ancestor of the running release nor a release
   `deploy/RELEASES.md` names (releasing an arbitrary commit is a deploy:
   `git checkout <sha> && ./deploy/deploy.sh --no-pull`); a tracked file is modified on the checkout
   (the files are named); the database container is not running; the target's OWN compose file (read
   out of git, never checked out) merged with the rollback override does not resolve
   `RUN_MIGRATIONS` to `0` through `docker compose config`.
2. **Backup first:** the backup boundary and a verified dump, through the same functions as
   `deploy.sh` (`deploy/backup-first-lib.sh`, one implementation for both).
3. Only then the checkout moves, the image is built stamped with the commit, and `app horizon
   scheduler web` start with a compose OVERRIDE written to a temporary file outside the checkout
   (`services.app.environment.RUN_MIGRATIONS: "0"`, passed as a second `-f`). It is an override and
   not a variable in `docker-compose.yml` because the checkout now holds the TARGET's copy of that
   file, and every release before docs/81 hardcodes `"1"`.
4. The started `app` container's environment is read back (`docker inspect`). Anything but
   `RUN_MIGRATIONS=0` fails the rollback loudly, naming the dump taken first. The `app` log says
   "migrations not run" (from releases carrying docs/81 C.3; older entrypoints are silent).
5. `/up`, then the same verification as a deploy (health `ok` and naming the commit). Rolling back to
   a commit older than docs/80 C.4 is recorded as UNVERIFIED, because that release's `/api/health`
   does not name its commit.
6. `RELEASES.md` gets `ROLLBACK to <sha> | from <running> | code only, no migrations run, dump <path>`,
   and `deploy/release-state/release.json` a record of kind `rollback` with the predecessor read from
   the running image.

**The schema is NOT rolled back.** Migrations in this project are additive, so the earlier code runs
against the newer, additive schema. Undoing a migration, or returning data to an earlier state, is a
separate and deliberate act, below.

⚠ After a rollback, do not type a bare `docker compose up -d`: compose sees a changed `app`
definition and recreates it with `RUN_MIGRATIONS=1`. A restart or a reboot is safe (a container keeps
the environment it was created with). The way forward from a rollback is
`git checkout main && ./deploy/deploy.sh --no-pull`, which is a deploy and migrates by design.

Database, only if a migration must be undone (from the dump the rollback or the deploy took first):

```bash
C="docker compose -f deploy/docker-compose.yml --env-file deploy/.env"
$C stop app horizon scheduler
$C exec backup /restore.sh /backups/daily/<pre-deploy dump>.dump hq_restore_check   # rehearse into scratch first
TARGET_DB=hq CONFIRM_PRODUCTION_RESTORE=yes $C exec backup /restore.sh /backups/daily/<dump>.dump hq
$C start app horizon scheduler
```

## Backup and restore

- Nightly at 01:00 UTC the `backup` container runs `pg_dump -Fc`, verifies with `pg_restore --list`, keeps
  14 daily + 8 weekly in `/root/backups/bordeguna-hq` on the host. **Local only: not an offsite backup, does not
  survive loss of the VPS** (docs/28). `OFFSITE_HOOK` is the extension point for a future provider.
- Manual: `$C exec backup /backup.sh once`.
- Restore drill (monthly, and after every schema change): `$C exec backup /restore.sh` restores the newest
  dump into `hq_restore_drill` and prints table and row counts; record the output in RESTORE-DRILL.md.

## Health and monitoring

- `/up`: liveness (Traefik/docker health). `/api/health`: application, database, redis, queue (Horizon
  master supervisors), scheduler (tick heartbeat < 180 s). 503 when any component fails, and the body
  of that 503 says which component: every reader here keeps it (`ignore_errors` in PHP, `curl` without
  `-f`), never busybox `wget -q`, which prints nothing on a non-2xx answer (docs/81 C.8).
- `deploy/hq-monitor.sh` (cron, 5 min): RAM, swap, disk, load, OOM kills, container restarts, unhealthy
  containers, HQ components, ERP reachability, local backup freshness. Alerts on state change only, daily heartbeat 08:00 UTC.
  It also refreshes `deploy/release-state/main.json` (never an alert; see "Release state" above).
- Horizon dashboard: `https://hq.bordeguna.com/horizon` (owner/operator only; permission `hq.horizon.view`).

## Change control

Backup before, verify the artifact (CI green, image built), verify health after, record the rollback path
(`deploy/RELEASES.md` carries the previous SHA). One unrelated production change per deployment.
