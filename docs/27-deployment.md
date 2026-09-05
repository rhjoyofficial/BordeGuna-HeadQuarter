# 27 - Deployment

## Target host, and the capacity question first

The only production host today is Hostinger KVM 2 `srv1808022`: 2 vCPU, 7.8 GB RAM, no swap, 2.7 GB in use by ERP (5 containers + MySQL), n8n, two Postgres instances, content-render, Zeno, Hermes. HQ Phase 0-3 adds roughly: app 512 MB, Horizon workers 512 MB, scheduler 128 MB, Postgres 17 512 MB-1 GB, Redis 128 MB, nginx 64 MB. That fits **only** with swap added and Zeno/Safor moved or capped. It does **not** fit together with the Nikolahost shops (doc 33). Decision in doc 31: upgrade to KVM 4 (4 vCPU / 16 GB) for one box, or add a second KVM 2 as the commerce tier. Recommendation: **second VPS for commerce**, so a payroll run, an ad-spend loop and a Black-Friday shop load are never on one kernel.

## HQ stack (compose in this repo, `deploy/docker-compose.yml`)

| Service | Image | Role | Limits (initial) |
|---|---|---|---|
| `app` | `bordeguna-hq:latest` (PHP 8.3-FPM, built from `deploy/Dockerfile`) | Laravel app, Inertia SSR off | 1 CPU / 768 MB |
| `web` | nginx 1.27-alpine | static + FPM proxy, Traefik labels `Host(hq.bordeguna.com)` | 0.5 / 128 MB |
| `horizon` | same image, `php artisan horizon` | queues: `critical`, `default`, `ai`, `publishing`, `sync` | 1 / 768 MB |
| `scheduler` | same image, `php artisan schedule:work` | the tick (doc 21); separate from workers on purpose | 0.25 / 128 MB |
| `db` | postgres:17 + pgvector (`pgvector/pgvector:pg17`) | HQ database, private bridge, no published port | 1 / 1 GB |
| `redis` | redis:7-alpine, AOF everysec, maxmemory 256 MB allkeys-lru for cache DB, no eviction for queue DB | queues, cache, locks | 0.25 / 320 MB |
| `backup` | postgres:17 client + restic | nightly `pg_dump` to volume + restic to B2 | 0.25 / 128 MB |
| `voice-gateway` (Phase 7) | node:22-alpine | telephony bridge | later |
| `uptime-kuma` (optional) | louislam/uptime-kuma | probes | 0.25 / 128 MB |

Copy the ERP's proven decisions: private `internal` network, only `web` carries Traefik labels, `env_file: .env` with `600`, resource limits on everything, entrypoint runs `migrate --force` only when a flag is set, `config:cache` + `route:cache` + `event:cache` in the image, health check on `/up`.

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
deploy/             Dockerfile, docker-compose.yml, nginx.conf, php.ini, backup.sh, offsite.sh, restore-drill.sh, README.md
docs/               this set + adr/
.github/workflows/  tests.yml (Postgres 17 + Redis services), lint.yml, build.yml
```
