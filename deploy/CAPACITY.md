# Capacity record: srv1808022 (Hostinger KVM 2)

Baselines are taken before and after every change that adds a container. Numbers are what the host reported, not estimates.

## Host

| Item | Value |
|---|---|
| Plan | Hostinger KVM 2 |
| CPU | 2 vCPU |
| RAM | 7.8 GB |
| Swap | 4 GB file `/swapfile`, `vm.swappiness=10` (added 5 Sep 2026 08:16 UTC, persisted in `/etc/fstab` and `/etc/sysctl.d/99-hq-swappiness.conf`) |
| Disk | 96 GB |
| OS | Ubuntu 24.04.4 LTS, kernel 6.8.0-134 |
| Docker | 29.6.1, Compose v5.3.1 |

## Baseline 5 Sep 2026 08:08 UTC (before HQ)

```
free -h:   Mem total 7.8Gi  used 2.7Gi  free 1.7Gi  buff/cache 3.8Gi  available 5.0Gi   Swap 0B
df -h /:   96G total, 20G used, 77G available (20%)
load:      0.06 0.07 0.02 (uptime 59 days)
OOM kills last 30 days: 0
```

docker stats (memory used / limit):

| Container | Memory | Limit |
|---|---|---|
| bordeguna-erp-db-1 (MySQL 8) | 738 MiB | 1 GiB |
| n8n-vb99-n8n-1 | 459 MiB | none |
| content-render-b7k2 | 106 MiB | 768 MiB |
| bordeguna-erp-app-1 | 60 MiB | 768 MiB |
| postgresql-4jjn (PG17) | 59 MiB | none |
| docker-app-1 (Zeno) | 55 MiB | none |
| docker-db-1 (Zeno PG16) | 47 MiB | none |
| bordeguna-erp-scheduler-1 | 39 MiB | 384 MiB |
| bordeguna-erp-worker-1 | 39 MiB | 512 MiB |
| traefik | 29 MiB | none |
| bordeguna-erp-web-1 | 6 MiB | 128 MiB |
| safor-travels-web-1 | 3 MiB | none |
| bordeguna-erp-backup-1 | 3 MiB | 256 MiB |

## HQ stack limits (deploy/docker-compose.yml)

| Service | CPU | Memory |
|---|---|---|
| app | 1.0 | 768 MB |
| web | 0.5 | 128 MB |
| horizon | 1.0 | 768 MB |
| scheduler | 0.25 | 192 MB |
| db (pgvector/pg17) | 1.0 | 1 GB (shared_buffers 192 MB) |
| redis | 0.25 | 320 MB (maxmemory 256 MB) |
| backup | 0.25 | 256 MB |
| Sum of limits | 4.25 vCPU (oversubscribed by design; limits are ceilings) | ~3.4 GB ceiling; expected steady state 0.9-1.3 GB |

Expected after HQ: available RAM ~3.7-4.1 GB, swap mostly idle. Alerts (deploy/hq-monitor.sh): available RAM < 1024 MB, swap > 50 %, disk > 80 %, load > 4.0, any OOM kill, any container restart, any HQ component not ok, ERP not answering, backups older than 30 h.

## Scale triggers (ADR-012)

Move to a second VPS (commerce tier, ADR-008 / doc 33) or upgrade the plan when any of these holds for a day: available RAM under 1 GB, swap use above 50 %, sustained load above 2.0 on 2 vCPU, or ERP p95 latency visibly degraded during HQ activity.

## Post-deploy baseline

_To be appended by the deploy run (free -h, docker stats, df -h) after the first `docker compose up`._
