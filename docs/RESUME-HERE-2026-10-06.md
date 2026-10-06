# RESUME HERE (6 October 2026): the candidate is frozen; what Joy types next

Supersedes `RESUME-HERE-2026-10-04.md` (kept for history).

## 1. State in one paragraph
ChatGPT ruled on Q1-Q5 on 6 Oct (`docs/101` §12): Q1 (b) the support agent keeps handing a thread to a person on a second inbound while `ai_waiting`, through P3-G2 attempt 5 and the shadow week (Joy's recommendation; no code change); Q2 `preferProvider` approved as built; Q3a/b/d/e confirmed; Q3c 180-day retention of finished, reconciled workflow runs (built, `docs/102` §11); Q4 `SupportWakeUp::settle()` must be fixed before the A1 cap on customer-reading runs is lifted; Q5 acknowledged; D2 mixed Bangla/English is answered in Bengali script. **Frozen candidate: `68cffcb`** (merge of `platform/workflow-retention`; local full suite 1,847 passed, Pint, `tsc --noEmit`, Vite build clean; **GitHub Actions run 37413624663 = SUCCESS**, all three jobs). Production is still **`531d59b`**, inert. ERP production is `30657838` = ERP master (it contains the HQ draft-order write `76f6168`).

## 2. The freeze record
- Frozen code: **`68cffcb`**. Every later commit on `main` is documents only (`git diff --stat 68cffcb origin/main -- apps deploy` is empty); `deploy.sh` fast-forwards to `origin/main`, so the release carries `68cffcb`'s code exactly.
- Preflight, read-only, on the VPS (`./deploy/deploy.sh --verify-only`, 2026-10-06 04:35 UTC, target `origin/main` = `68cffcb` at the time): **23 ok, 1 warning, 0 failures.** Fast-forward of `531d59b` (39 commits); four additive pending migrations (`2026_10_03_100000_create_language_policies`, `2026_10_03_110000_add_conversation_message_uuid_to_agent_runs`, `2026_10_03_120000_add_event_id_to_job_runs`, `2026_10_04_100000_create_workflow_tables`), none drops, renames or changes a column; newest verified dump `hq-20261006-010355.dump`, 3 h old; 72 GB free; host lock free; health names `531d59b`. The one warning is the long-known `HQ_ERP_BASE_URL` / `HQ_ERP_TIMEOUT` absent from `deploy/.env` (config default applies; docs/83, docs/86).
- No tag: the repo has never used release tags; the release record (`deploy/release-state/`, `deploy/RELEASES.md`) is the record.

## 3. What Joy types next, in order (each one only after the previous one is confirmed)
1. **Release** (Claude is not allowed to deploy). Backup-first and locked by the script itself:
   ```
   ! ssh vps 'cd /opt/bordeguna-hq && ./deploy/deploy.sh'
   ```
   Then Claude reads, read-only: `release.json` says released + verification passed, `/api/health` names the new commit, the four migrations ran, `language_policies` v1 active, posture unchanged (runtime off, agents paused at A0, no grants).
2. **P3-G2 attempt 5** on that release (runbook `docs/runbooks/p3-g2-support-gate.md`, "Attempt 5 and later" fields). The support agent's enabling user must still be Joy (set for attempts 1-4; the runner's `on` step refuses if not). Launch:
   ```
   ! ssh vps 'cd /opt/bordeguna-hq && GATE=p3-g2-attempt-5 GATE_AGENT=support GATE_NAME="P3-G2 attempt 5 (docs/92, docs/101 section 12), OpenAI" nohup ./deploy/gate/run.sh > /dev/null 2>&1 &'
   ```
   Claude then waits on `/tmp/p3-g2-attempt-5.log` (`== gate end`), grades it, reports per `docs/101` §11 (commits, tests, CI, WorkflowEngine design and schema, ConnectorRegistry, ErpPlatform, Support state tests, attempt-5 result with every language field), and sends that report to the ChatGPT thread. A failure is recorded and diagnosed, never rerun in the same breath. Q1 means a second customer message still goes to a person: that is the expected result, not a failure.
3. **Only if attempt 5 passes completely: open the Executive's seven-day shadow week, by Joy's own name** (HQ refuses any other way). First without `--apply` to read the checks, then:
   ```
   ! ssh vps 'cd /opt/bordeguna-hq && docker compose -f deploy/docker-compose.yml --env-file deploy/.env exec -T app php artisan hq:shadow:open executive --by=<your HQ login email> --reason="Joy opens the Executive shadow week after P3-G2 attempt 5 passed" --apply'
   ```
4. **The daily owner briefing job** (independent of 2 and 3; it waits for Joy's approval in HQ before it runs). Without `--apply` first to see what it would write:
   ```
   ! ssh vps 'cd /opt/bordeguna-hq && docker compose -f deploy/docker-compose.yml --env-file deploy/.env exec -T app php artisan hq:scheduler:ensure "Daily owner briefing" --tool=briefing.compose --cron="0 9 * * *" --created-from=ui --owner=<your HQ login email> --apply'
   ```
   Then approve it once on the Approvals screen.

## 4. Owner items (unchanged)
`docs/95` §7 (summarised in `docs/68` §6 under "From the vision audit"): new shop admin tokens, the shop to HQ network block, Meta Business Verification (slowest clock), Meta developer app + App Review, WhatsApp SIM in the company's name, second order approver, a priority order, company dual-currency card (at most $300 per top-up), KVM 4, ERP write token, LaLaDia ad account id `530866839523958` check, test sets, legal read (PDPA 2026, BTRC), which SMS aggregator the ERP uses.

## 5. Rules in force
As `RESUME-HERE-2026-10-04.md` §6, plus `docs/101` §12: no Support re-entry (`ai_waiting -> ai_active`) and no A2+ execution in this release; `SupportWakeUp::settle()` fix is a precondition for lifting the A1 cap; the other eight effectful tools must reach the gateway before consequential activation. Anthropic stays off. Never activate agents, grants or shadow periods for Joy.

## 6. Worktrees
`hq-wt\k` is on `platform/workflow-retention` (merged, pushed; test DB `hq_test_k`). `hq-wt\e..j` unchanged and safe to reuse with a fresh branch from main. Never `git worktree remove`.
