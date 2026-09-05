# 27 - Deployment

## Target host: the current VPS first (ADR-012)

HQ deploys to the existing Hostinger KVM 2 `srv1808022` (2 vCPU, 7.8 GB RAM, Ubuntu 24.04, 2.7 GB in use today by ERP, n8n, two Postgres instances, content-render, Zeno, Hermes). HQ Phase 0-3 adds roughly 1.6-2.1 GB: app 512 MB, Horizon 512 MB, scheduler 128 MB, Postgres 17 512 MB-1 GB, Redis 128 MB, nginx 64 MB. That fits with headroom once swap exists. A second VPS or an upgrade is a **documented scaling option triggered by monitoring**, not a prerequisite; doc 33 keeps the commerce-tier plan for that day.

### Pre-deploy checklist (run and record before the first `docker compose up`)

1. Add a 4 GB swap file (`/swapfile`, `vm.swappiness=10`) and confirm with `swapon --show`.
2. Record current usage: `free -h`, `docker stats --no-stream`, `df -h`.
3. Verify Docker health: every container `Up`, none restarting, `docker system df`.
4. Verify firewall: ufw 22/80/443 only, fail2ban active.
5. Verify backups: ERP nightly dump fresh, automation backup fresh, Zeno backup fresh.
6. Verify backups: ERP nightly dump fresh and **one restore test performed** (doc 28); `/root/backups/bordeguna-hq` exists.
7. Verify reverse proxy: Traefik healthy, certificate resolver working on an existing host.
8. Disk: at least 40 GB free after image builds.
9. RAM headroom: at least 1.5 GB available after HQ containers start, else raise limits or move Zeno.
10. Monitoring live before HQ takes traffic: capacity alerts (RAM < 1 GB available, swap > 50 %, disk > 80 %), OOM-kill detection (`dmesg`/journal watch to Telegram), container restart alerts, health endpoint probes.

OOM awareness: every HQ service has a memory limit (below) so the kernel kills a bounded container, never MySQL or Postgres; Horizon `memory` option restarts workers at 256 MB.

## HQ stack (compose in this repo, `deploy/docker-compose.yml`)

| Service | Image | Role | Limits (initial) |
|---|---|---|---|
| `app` | `bordeguna-hq:latest` (PHP 8.3-FPM, built from `deploy/Dockerfile`) | Laravel app, Inertia SSR off | 1 CPU / 768 MB |
| `web` | nginx 1.27-alpine | static + FPM proxy, Traefik labels `Host(hq.bordeguna.com)` | 0.5 / 128 MB |
| `horizon` | same image, `php artisan horizon` | queues: `critical`, `default`, `ai`, `publishing`, `sync` | 1 / 768 MB |
| `scheduler` | same image, `php artisan schedule:work` | the tick (doc 21); separate from workers on purpose | 0.25 / 128 MB |
| `db` | postgres:17 + pgvector (`pgvector/pgvector:pg17`) | HQ database, private bridge, no published port | 1 / 1 GB |
| `redis` | redis:7-alpine, AOF everysec, maxmemory 256 MB allkeys-lru for cache DB, no eviction for queue DB | queues, cache, locks | 0.25 / 320 MB |
| `backup` | postgres:17-alpine | nightly `pg_dump -Fc` to `/root/backups/bordeguna-hq` on the host (local only, not disaster-proof, doc 28) | 0.25 / 256 MB |
| `voice-gateway` (Phase 7) | node:22-alpine | telephony bridge | later |
| `uptime-kuma` (optional) | louislam/uptime-kuma | probes | 0.25 / 128 MB |

Copy the ERP's proven decisions: private `internal` network, only `web` carries Traefik labels, `env_file: .env` with `600`, resource limits on everything, entrypoint runs `migrate --force` only when a flag is set, `config:cache` + `route:cache` + `event:cache` in the image, health check on `/up`.

## Local development

`docs/../apps/hq/README.md` (Phase 0) will carry the exact steps: PHP 8.3, Composer, Node 22, Postgres 17 with pgvector and Redis (WSL2 + Docker Desktop recommended, or Laragon services), `.env.example`, `composer setup`, `npm run dev`, `php artisan horizon`, test command, and how to run the connectors in `dry_run` mode so no real store or platform is touched from a laptop.

## Environments

| Env | Where | Data |
|---|---|---|
| local | Windows + Laragon (no Docker) or WSL2 Docker (recommended to install) | SQLite for unit tests is **not** acceptable here because of pgvector/JSONB; use a local Postgres 17 (Laragon can run it) |
| staging | same VPS, `hq-staging.` host, own db and redis, sandbox credentials only, connectors in `dry_run` | anonymised copy |
| production | VPS | real |

Feature flags (`feature_flags` table, cached) gate every connector write and every agent; a new capability ships dark and is enabled per business.

## Release procedure (mirrors the ERP runbook)

1. CI green on `main` (tests on Postgres 17 in the job, static analysis, `tsc`, build).
2. `ssh vps`, `cd /opt/bordeguna-hq`, take a pre-deploy `pg_dump` (script), `git fetch && git merge --ff-only origin/main`.
3. `docker compose build app` then `docker compose up -d` (image shared by horizon/scheduler), `php artisan migrate --force` inside `app`, `php artisan horizon:terminate` (graceful).
4. Smoke: `/up` 200, login in a real browser, one read tool call from the Command Center, Horizon dashboard alive, scheduler tick row within 2 min.
5. Record the release in `deploy/RELEASES.md` with commit, time, dump name.

Rollback: `git checkout <previous-sha> && docker compose build app && docker compose up -d`; if a migration must be undone, restore the pre-deploy dump (schema changes are additive by rule, so rollback is normally code-only).

## DNS and TLS

`hq.bordeguna.com` A record to the VPS; Traefik issues the certificate. bordeguna.com itself stays on its current host until doc 33 moves it.

## Repository layout

```
apps/hq/            Laravel app
deploy/             Dockerfile, backup.Dockerfile, docker-compose.yml, nginx.conf, php.ini, entrypoint.sh, backup.sh, restore.sh, deploy.sh, rollback.sh, hq-monitor.sh, README.md, CAPACITY.md, RESTORE-DRILL.md
docs/               this set + adr/
.github/workflows/  tests.yml (Postgres 17 + Redis services), lint.yml, build.yml
```
