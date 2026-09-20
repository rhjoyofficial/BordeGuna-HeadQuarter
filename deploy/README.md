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
ssh vps && cd /opt/bordeguna-hq && ./deploy/deploy.sh
```
The script: refuses without a 600 `.env` -> verifies the backup mount -> takes a pre-deploy dump
(`/backup.sh once`) and verifies it -> ONLY THEN fast-forwards to `origin/main` -> builds `app` and
`backup` images (the commit is stamped into the image as a label and as `HQ_RELEASE_COMMIT`) -> `up -d`
(the `app` entrypoint runs `migrate --force`) -> `horizon:terminate` (graceful) -> waits for `/up` ->
**verifies the release**: `/api/health` overall `ok` AND naming the commit just released (retried for
about 90 s) -> appends to `deploy/RELEASES.md` with the verification result -> writes
`deploy/release-state/release.json`, and `last-verified.json` only when the verification passed ->
refreshes `main.json`. CI must be green on the commit first (never deploy ahead of CI).

A release whose health never comes good still exits 0 (the stack is up) but is logged and recorded as
**UNVERIFIED**, and the Platform dashboard says so. Fix what health reports, then
`./deploy/deploy.sh --no-pull` to release the same commit again and have it verified.

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

### ⛔ NOT YET APPLIED TO PRODUCTION: what the first deploy carrying docs/80 C.4 needs, by hand

Production runs a release older than this. Nothing below has been done on the VPS.

1. **Nothing to create beforehand.** `docker compose up` creates `deploy/release-state/` for the new
   read-only mount, and the scripts create it (mode 755, files 644) when they first write.
2. **Run the deploy TWICE, the second time with `--no-pull`.** The first run is executed by the OLD
   `deploy.sh` (bash holds the script it started with), so it builds the new image and mounts the
   directory but writes no release record, and the panel will honestly say "UNVERIFIED: this host has
   no record of releasing this commit". `./deploy/deploy.sh --no-pull` then runs the NEW script against
   the same commit: it takes its own verified dump, verifies the release through `/api/health` and
   writes `release.json` and `last-verified.json`.
3. **No new cron line.** The refresher runs from the existing `*/5` `hq-monitor.sh` line. Check that the
   production crontab still calls `/opt/bordeguna-hq/deploy/hq-monitor.sh` (so it picks up the new
   script from the checkout), and that both new scripts are executable:
   `ls -l deploy/release-state-refresh.sh deploy/release-state-lib.sh`.
4. **Check that root's cron can fetch.** `cd /opt/bordeguna-hq && env -i HOME=/root PATH=/usr/bin:/bin GIT_TERMINAL_PROMPT=0 git fetch origin main`
   must succeed without a prompt (it uses the same `github-hq` deploy key as a deploy). If it cannot,
   the panel will say "the host has never fetched main successfully" rather than anything wrong, and
   the deploy-time fetch still refreshes `main.json` at each release.
5. **Look.** `cat deploy/release-state/*.json`; `curl -s https://hq.bordeguna.com/api/health` names the
   commit under `checks.application.release`; `/observe/platform` shows the Release state panel;
   `tail /var/log/hq-monitor.log` shows no `release-state` error.

## Rollback

Code: `./deploy/rollback.sh <sha>` (rebuilds and restarts app/horizon/scheduler/web at that commit, no
migrations; the image is stamped with the commit, and the rollback is recorded in
`deploy/release-state/` like a release. ⚠ Rolling back to a commit older than docs/80 C.4 is recorded
as UNVERIFIED, because that release's `/api/health` does not name its commit). Database, only if a migration must be undone:

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
  master supervisors), scheduler (tick heartbeat < 180 s). 503 when any component fails.
- `deploy/hq-monitor.sh` (cron, 5 min): RAM, swap, disk, load, OOM kills, container restarts, unhealthy
  containers, HQ components, ERP reachability, local backup freshness. Alerts on state change only, daily heartbeat 08:00 UTC.
  It also refreshes `deploy/release-state/main.json` (never an alert; see "Release state" above).
- Horizon dashboard: `https://hq.bordeguna.com/horizon` (owner/operator only; permission `hq.horizon.view`).

## Change control

Backup before, verify the artifact (CI green, image built), verify health after, record the rollback path
(`deploy/RELEASES.md` carries the previous SHA). One unrelated production change per deployment.
