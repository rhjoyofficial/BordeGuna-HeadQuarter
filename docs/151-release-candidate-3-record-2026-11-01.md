# 151. Release candidate 3 record (1 November 2026)

**Production is `c93bb2e`** (tag `hq-rc-2026-11-01`), released and verified 1 Nov 2026 ~13:12 UTC. It replaces RC2 `ea79564` (docs/148). Everything external stays OFF: no flag was changed by this release.

## 1. What is in it

RC2 plus the ChatGPT ruling of 1 Nov 2026 (7 KEEP, 3 CHANGE, Approver role approved):

- `0aa22c7` AI cost text written by the server reads BDT first, USD in brackets.
- `8233ec9` quarantine review form opens in front of the page, with confirmation.
- `5443ea6` least-privilege Approver role for second approvers (docs/149). Never user/role admin, credentials, global flags, autonomy grants, connectors or policy.
- `94ecba3` owner-set USD to BDT rate as dated, audited data. Replaces the USD 130 + 5% code constant. Reserve rate BDT 136.50, valid to 30 Nov 2026. A stale or missing rate makes paid media fail closed. New permission `hq.fx.manage`, Exchange rate screen.
- `0ea1640` durable, fail-closed autonomy lapse (docs/150). Replaces the docs/145-d default.
- `d9f2459` Bangla relative times and reason-code messages (bn-BD, Asia/Dhaka). No runtime model translation.

## 2. Gates

- Full Pest 2,705 passed, 0 failed. Pint, tsc, ESLint, Vite build clean.
- GitHub CI run 41278988560 green on `c93bb2e`. The tag `hq-rc-2026-11-01` is locked by the `hq-rc-*` ruleset.

## 3. Restore rehearsal (`rc3-drill.sh`)

- A throwaway container `hq-drill-rc3` (`pgvector/pgvector:pg17`, `127.0.0.1:55433`, SSH tunnel). It used the 1 Nov nightly production dump. Production was only read through the dump file. The container was removed afterwards.
- Result: 78 migrations before, 79 after. 83 permissions, the same on a second seeder run. `fx_rates` 1 row (136.50). Approver role with 3 permissions. Rollback of the new migration and migrate again both clean.
- Fixes found by the drill: the drill container now uses `POSTGRES_USER=hq`, the same shape as production. The local run uses `CACHE_STORE=array`.
- **PASS.** Also recorded in `deploy/RESTORE-DRILL.md`.

## 4. Release (`rc3-release.sh`, run by Joy)

1. Checks: production was `ea79564`, tracked files clean, the tag resolves to `c93bb2e`.
2. Checkout `c93bb2e`, then `deploy.sh --no-pull --keep-scheduler-stopped`. That took a verified backup first, then build, up and migrate. Pre-release dump: `/backups/daily/hq-20261101-130922.dump`.
3. Seeder `--class=RolesAndPermissionsSeeder` only, then `hq:tools:sync`. No pending migrations.
4. Scheduler started, then `deploy.sh --no-pull` recorded the release.
5. Read-only afterwards: `/up` 200, events processed 125, dead 30 (unchanged, see section 6), 0 log errors.

**Rollback:** `deploy/restore.sh` with `/backups/daily/hq-20261101-130922.dump`, then checkout `ea79564`.

## 5. Stays OFF after this release

All flag rows, Do mode (activation record null), STT (no benchmark-passed provider), model extraction, AI runtime, Meta send connectors, autonomy grants (none). Media budgets have no row, so they are BDT 0 and every paid media job is refused.

## 6. Known after release, not caused by it

- Shops `credential_invalid` (laladia.com, bionic.garden). The 30 dead events (`UnknownOrderException`) stay isolated. Per the ruling, they are replayed only after new shop tokens and the Hostinger outbound block are fixed and verified, as a controlled idempotent replay with a business-scope check. A key showing up is not a reason to replay.
- The shadow week has not started. Its preconditions are in the 1 Nov ruling: a dedicated OpenAI project, a hard spend limit, an HQ-side budget guard, and a canary.
