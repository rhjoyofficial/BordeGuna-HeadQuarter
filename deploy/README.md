# Deploying BordeGuna HQ to srv1808022

Runbook for the `bordeguna-hq` compose stack on the existing Hostinger KVM 2 (ADR-012). The ERP,
n8n, Zeno and Safor stacks on the same host are never touched by any step here.

## Layout on the VPS

```
/opt/bordeguna-hq/            git clone of this repository (deploy key github-hq)
/opt/bordeguna-hq/deploy/.env production environment, mode 600, never in git (template: deploy/.env.example)
/root/backups/bordeguna-hq/   nightly pg_dump archives (+ LAST_RUN, LAST_STATUS, LAST_OK). LOCAL ONLY, not disaster-proof (docs/28)
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
The script: refuses without a 600 `.env` -> fast-forwards to `origin/main` -> takes a pre-deploy dump
(`/backup.sh once`) -> builds `app` and `backup` images -> `up -d` (the `app` entrypoint runs
`migrate --force`) -> `horizon:terminate` (graceful) -> waits for `/up` -> prints `/api/health` -> appends
to `deploy/RELEASES.md`. CI must be green on the commit first (never deploy ahead of CI).

## Rollback

Code: `./deploy/rollback.sh <sha>` (rebuilds and restarts app/horizon/scheduler/web at that commit, no
migrations). Database, only if a migration must be undone:

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
- Horizon dashboard: `https://hq.bordeguna.com/horizon` (owner/operator only; permission `hq.horizon.view`).

## Change control

Backup before, verify the artifact (CI green, image built), verify health after, record the rollback path
(`deploy/RELEASES.md` carries the previous SHA). One unrelated production change per deployment.
