# RESUME HERE (11 October 2026): waves 1-3 merged and green on main; work PAUSED at a safe stage

Supersedes `RESUME-HERE-2026-10-10.md` (kept for history). Paused at Joy's request after all running work was finished, merged and fully tested. Nothing is running. No agent is active. Full report: **docs/118**.

## 1. State in one paragraph
Production = **`b96a396`** (released by Joy 9 Oct; code = `68cffcb`), fully inert: `ai.runtime` off, executive/analytics/support paused at A0, 0 grants, 0 open shadow periods. P3-G2 attempt 5 PASSED 10 Oct (docs/103). **main = `6726f51`, pushed**: waves 1, 2 and 3 merged (S1, cost attribution, S3, S7, S2 in wave 1; S5, S6, S4-inert in wave 2; S7 follow-up, S3 follow-up, cost pre-throw + scheduler bypass retirement, S2 quarantine in wave 3). Combined tree: **2,042 Pest passed, 0 failed, 30,817 assertions**; Pint, `tsc --noEmit`, Vite build clean. **CI run 38079877403 on `6726f51`: SUCCESS, all three jobs.** (The run before it, 38078297032 on `5812d42`, failed one test on a whole-second rounding race in the decision-corpus window; fixed and proven in `6726f51`, docs/118 §8.) Nothing after `b96a396` is released.

## 2. Rulings in force
docs/101 §11 (4 Oct), §12 (6 Oct, Q1-Q5), §13 (10 Oct morning: shadow week approved, S1-S7 order), §14 (10 Oct afternoon, verbatim `docs/110-chatgpt-ruling-2026-10-10.txt`: wave 1 architecture approved, NOT a release; follow-ups now built). docs/96 standing instruction (build vs integrate; ChatGPT decides architecture).

## 3. Exact next steps, in order (when work resumes)
1. CI is green on `6726f51`. First item: the whole-second rounding audit of the other `*_at` readers listed in docs/118 §8 (none known wrong).
2. **Write the wave 2+3 report for ChatGPT** from docs/111-117 + docs/118 §4 and §6, and post it in the HQ thread (https://chatgpt.com/c/6a9baf11-c2c8-83ee-a43b-6bc7a76498c3). ⚠ Compose ONLY in `form [contenteditable="true"]`; an open canvas is contenteditable too and was overwritten once (restored with its undo arrow). Copy a long reply via its canvas block's Copy button clicked by POSITION after scrolling it on screen (a by-ref click did not fill the clipboard), then `Get-Clipboard -Raw`. Lead with **the identity leak** (docs/118 §4.3, docs/117 §11 Q2): `IdentityResolver::byIdentity()` has no business filter.
3. Record the ruling as docs/101 §15; build what it orders in worktrees (at most THREE agents at once: five hit the usage limit three times on 10-11 Oct; agents commit a plan first and WIP checkpoints; resume the SAME agent id after a limit).
4. Merge `--no-ff` one at a time; full suite on the COMBINED tree is the gate (every wave so far had cross-stream collisions: bn.json, BanglaDictionaryTest, PostureScreenTest allow-list, Customer360Test route pin, routes/web.php groups, RolesAndPermissionsSeeder, GatewayCoverageStructureTest / PolicyEngineTest / ToolNamespaceTest tool pins). Reusable resolver: `deploy/tools/resolve_merge_lists.py` (unions list conflicts, dedupes bn.json keys and flags keys translated differently; read hunks first, route groups and test pins need a hand merge).
5. S4's outcome-dependent part only after the S3 review-pending decisions are ruled.

## 4. Release notes for Joy's next release (main `6726f51` vs production `b96a396`)
- 7 additive migrations (list in docs/118 §5). `php artisan db:seed --class=RolesAndPermissionsSeeder --force` (⛔ never full `db:seed`).
- After release: `php artisan hq:identity:cross-business` dry run, read it; `--flag` only on Joy's word.
- `scheduler.gateway` row becomes inert; delete line in docs/116 (Joy's call; keep one release as a rollback cushion or delete).
- Cost backfill: dry run first, Joy authorizes `--apply`.
- Release line (Joy types it): `! ssh vps 'cd /opt/bordeguna-hq && ./deploy/deploy.sh'`, then the seeder `--class`, then read-only verification (release.json, health names the commit, migrations ran, posture unchanged).

## 5. Joy's items
- **Executive shadow week, approved by ChatGPT twice; dry run held every precondition on 10 Oct.** Apply line:
  `! ssh vps 'cd /opt/bordeguna-hq && docker compose -f deploy/docker-compose.yml --env-file deploy/.env exec -T app php artisan hq:shadow:open executive --by=rakibulhasanjoy009@gmail.com --reason="Joy opens the Executive shadow week after P3-G2 attempt 5 passed" --apply'`
  (turns `ai.runtime` on, Executive active at A0, writes the 7-day period; Support/Analytics stay paused; ~$3-8 over the week, $50 cap.) Then read-only verification: open period + end date, 3 audit rows, posture.
- Daily briefing job (dry run first, then approve once in HQ): `hq:scheduler:ensure "Daily owner briefing" --tool=briefing.compose --cron="0 9 * * *" --created-from=ui --owner=rakibulhasanjoy009@gmail.com --apply`.
- New LaLaDia + Bionic admin tokens · Hostinger shop→HQ block · Meta Business Verification · WhatsApp SIM · second order approver · ERP write token · KVM 4.

## 6. Worktrees (all clean, all branches merged and pushed)
`hq-wt\e` p4/s4-followup-opportunity-contracts · `f` platform/cost-prethrow-and-scheduler-bypass-retirement · `g` platform/s3-followups-hqscope-attribute-tool · `h` platform/s7-identity-scope-and-two-person-lift · `i` p3/s2-quarantine-cross-business-merges · `j` platform/s5-decision-corpus-reader · `k` platform/s6-playbook-store. Test DBs `hq_test_e..k`. Reuse any by cutting a fresh branch from main. Never `git worktree remove` without removing a `node_modules` junction first.

## 7. Rules in force
As RESUME-HERE-2026-10-10 §6, plus: at most three agents at once; agents commit plan first and WIP checkpoints; commit attribution per the reminder in force (`Claude Opus 5.5` since 11 Oct).
