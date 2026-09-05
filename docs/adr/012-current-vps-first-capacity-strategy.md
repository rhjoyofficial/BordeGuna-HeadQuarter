# ADR-012: Deploy on the current VPS first; scale on measured evidence

**Status:** accepted (Joy, 5 Sep 2026); supersedes ADR-008 for now

## Context
The audit measured the only production host (Hostinger KVM 2, 2 vCPU, 7.8 GB, no swap, 2.7 GB used) and recommended a second VPS before hosting HQ and the shops together. Joy decided not to make a purchase a prerequisite.

## Decision
HQ Phase 0 deploys on `srv1808022`. Before the first deploy: swap (4 GB), resource limits on every container, health checks, capacity monitoring and alerts (RAM, swap, disk, OOM kills, restarts), backups verified and a restore drill proven. Backups are local to the VPS (ADR-014). A second VPS or an upgrade happens only when monitoring shows sustained pressure (for example available RAM under 1 GB for a day, swap use above 50 %, or CPU steal/latency affecting the ERP). Doc 33 and ADR-008 stay as the plan for that moment.

## Alternatives
- Buy a second KVM 2 now (~USD 8-15/month): cleaner isolation, but unmeasured need; deferred.
- Upgrade to KVM 4 now: same argument.

## Consequences
The ERP, n8n, Zeno and HQ share a kernel and a disk for a while; memory limits and swap make the failure mode a bounded container restart rather than a host OOM. The shop migration (doc 33) is decoupled from Phase 0.
