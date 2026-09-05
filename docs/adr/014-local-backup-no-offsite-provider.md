# ADR-014: Local on-VPS backup; Backblaze B2 removed; no offsite provider in the current deployment

**Status:** accepted (Joy, 5 Sep 2026)

## Context
The first Phase 0 design replicated HQ dumps with restic to a Backblaze B2 bucket and treated an
active ERP offsite backup as a deployment prerequisite. Joy decided B2 is not available for this
project. The ERP's own `offsite` restic service had never run (empty destination credentials).

## Decision
1. Backblaze B2 is **not part of the BordeGuna-HeadQuarter deployment**. No B2 credentials,
   buckets, repositories or restores are required anywhere, and no open question about B2 remains.
2. HQ backs up **locally on the VPS**: `pg_dump -Fc` nightly and before every release, into
   `/root/backups/bordeguna-hq` (a host directory separate from the database volume, on the same
   disk), verified with `pg_restore --list`, 14 daily / 8 weekly retention, restore drills recorded.
3. restic is **removed from the HQ backup path**: without an offsite destination it adds a binary
   and a password for no protection the plain dump does not give. `RESTIC_PASSWORD` is not part of
   HQ's configuration.
4. The limitation is stated wherever backups are described: **local VPS backup is not
   disaster-proof and is not an offsite backup.** It does not protect against loss of the VPS.
5. `OFFSITE_HOOK` in `deploy/backup.sh` is the extension point for a future offsite provider. That
   is a future enhancement, not scheduled and not a prerequisite for any phase.
6. The ERP's inactive restic definition is left untouched; changing it is a separate ERP task.

## Alternatives considered
- Another object-storage provider in place of B2: rejected for now; nothing requires it yet and
  the brief asks to keep the deployment simple and cost-conscious.
- A second VPS as a backup target: rejected as a prerequisite (ADR-012).

## Consequences
Deployment cannot be blocked by missing offsite credentials. Recovery from a lost VPS depends on a
Hostinger platform snapshot if one is enabled (unverified) and on rebuilding from git; data since
the last snapshot would be lost. That risk is accepted and documented in doc 28.
