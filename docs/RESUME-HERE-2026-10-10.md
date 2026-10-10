# RESUME HERE (10 October 2026, afternoon): wave 1 merged, wave 2 running, shadow week approved

Supersedes `RESUME-HERE-2026-10-06.md` (kept for history). Written at Joy's request ("sync memory so nothing gets lost").

## 1. State in one paragraph
Production = **`b96a396`** (released by Joy 9 Oct 03:41 UTC; code = frozen `68cffcb`), inert: `ai.runtime` off, 3 agents paused at A0, 0 grants, 0 open shadow periods. **P3-G2 attempt 5 PASSED 10 Oct** (docs/103: support 43/43, safety 80/80, 0 unmeasured, 0 script violations, ~$0.89). ChatGPT ruled the same day (docs/101 §13, verbatim `docs/103-chatgpt-ruling-2026-10-10.txt`): shadow week approved with conditions; S1 first; S3 as ONE canonical outcome model; S2/S5/S6/S7 parallel; S4 after S3 + S7. **Wave 1 (S1, cost attribution, S3, S7, S2) is MERGED to main `a8a6410`, pushed**, combined tree 1,941 Pest green, Pint, tsc, Vite clean; CI run **38029016996** on `a8a6410` (check `gh run view 38029016996`). **Wave 2 is RUNNING** (see §3). Nothing after `b96a396` is released.

## 2. What main `a8a6410` carries beyond production (release notes for Joy's next release)
- 4 additive migrations: `2026_10_10_100000_*` (evaluation cost columns + `evaluation_results.correlation_id`), `2026_10_10_100000_create_outcome_attribution_tables` (3 append-only tables), `2026_10_10_700000_add_brand_to_consent_state`. (Check `php artisan migrate:status` for the exact list; `deploy.sh --verify-only` lists pending ones.)
- Permissions seeder needed: `php artisan db:seed --class=RolesAndPermissionsSeeder --force` (consent view/restrict/grant). ⛔ never full `db:seed`.
- Behaviour: `hq:facts:reconcile` now runs through the Tool Gateway (tool_calls + audit per store, non-zero exit on refusal); scheduler forces effectful tools through the gateway regardless of `scheduler.gateway` (prod flag is ON, so unchanged there).
- Records: docs/105 (S1), 106 (cost), 107 (S3 contract, D1-D19, Q1-Q11 awaiting ChatGPT), 108 (S7), 109 (S2), 110 (wave 1 report for ChatGPT; CI line is a placeholder until the run finishes).

## 3. Wave 2, running when this was written (branches from `a8a6410`, NOT merged)
| Worktree | Branch | Stream | Record | Test DB |
|---|---|---|---|---|
| `hq-wt\j` | `platform/s5-decision-corpus-reader` | S5 owner report of recurring human edits (reporting only) | docs/111 | `hq_test_j` |
| `hq-wt\k` | `platform/s6-playbook-store` | S6 versioned per-brand guidance, draft→review→activate, loader only in context assembly | docs/112 | `hq_test_k` |
| `hq-wt\e` | `p4/s4-followup-opportunity-contracts` | S4 INERT part: contracts, entities, proposal flow, S7 consent pre-check, WorkflowEngine human-wait; outcome-dependent part WAITS for the S3 ruling | docs/113 | `hq_test_e` |
If the session was lost: `git -C H:\WORK\BorDeGuna\hq-wt\<x> status` and `git log --oneline main..HEAD` in each; the agents commit plan first and WIP checkpoints, and push their branch when done. Resume the same agent if possible; otherwise read docs/111-113 and continue from the last checkpoint.

## 4. Exact next steps, in order
1. CI 38029016996 result → fill the placeholder in docs/110 → commit → **post docs/110 to the ChatGPT HQ thread** (⚠ compose ONLY in `form [contenteditable="true"]`; an open canvas is also contenteditable and was overwritten once on 10 Oct, restored by its undo arrow) → record the ruling as docs/101 §14 → apply rulings (especially S3 D1-D19/Q1-Q11 and the 14 lettered questions in docs/110 §8).
2. Wave 2 branches: review the security-relevant diff yourself, merge `--no-ff` into main one at a time, full suite on the COMBINED tree (⭐ green branches ≠ green merge: wave 1 had exactly one cross-stream failure, S2's route pin vs S7's route), Pint, tsc, Vite, push, CI, report to ChatGPT.
3. S4's outcome-dependent part only after the S3 contract is ruled.
4. Joy's release of the wave when he decides (`! ssh vps 'cd /opt/bordeguna-hq && ./deploy/deploy.sh'`, then the seeder `--class`), each step verified read-only afterwards.

## 5. Joy's items (none block engineering)
- **Open the Executive shadow week (ChatGPT approved it, conditions met):** dry run already done 10 Oct, every precondition held. Apply line:
  `! ssh vps 'cd /opt/bordeguna-hq && docker compose -f deploy/docker-compose.yml --env-file deploy/.env exec -T app php artisan hq:shadow:open executive --by=rakibulhasanjoy009@gmail.com --reason="Joy opens the Executive shadow week after P3-G2 attempt 5 passed" --apply'`
  (`--apply` turns `ai.runtime` on, sets Executive active at A0, writes the 7-day period; Support/Analytics stay paused.) Then verify read-only: open period + end date, 3 audit rows, posture.
- Daily briefing job (`hq:scheduler:ensure "Daily owner briefing" --tool=briefing.compose --cron="0 9 * * *" --created-from=ui --owner=rakibulhasanjoy009@gmail.com --apply`, dry first), then approve once in HQ.
- New LaLaDia + Bionic admin tokens (rejected since 22 Sep; two attempt-5 cases hit `credential_invalid`) · Hostinger shop→HQ block · Meta Business Verification (slowest clock) · WhatsApp SIM · second order approver · ERP write token · KVM 4 · docs/95 §7 rest.

## 6. Rules in force
docs/96 standing instruction; docs/97 §6, docs/101 §11-§13 rulings; no Backblaze B2; no prompt tuning to pass a case; never alter historical gate evidence; stage by path; one worktree + one test DB per agent; full suite on the merged tree is the gate; never call local "CI green"; never activate agents, grants or shadow periods for Joy; never mention End Brackets or personal work in HQ material; attribution line per the reminder in force (`Claude Fable 5.1` since midday 10 Oct).
