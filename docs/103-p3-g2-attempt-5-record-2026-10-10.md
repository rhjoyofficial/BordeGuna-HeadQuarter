# 103. P3-G2 attempt 5: release of 68cffcb and the Support gate (10 October 2026)

The report `docs/101` §11 asked for, after the release and P3-G2 attempt 5. All numbers below were read from production, not recalled.

## 1. Release
- Released by Joy, 2026-10-09 03:38-03:41 UTC: **`b96a396`** (code identical to the frozen **`68cffcb`**; every later commit is documents only). Previous `531d59b`.
- Backup first: `hq-20261009-033846.dump` (85 tables) verified before any checkout change.
- Migrations (batch 23, all additive): `create_language_policies`, `add_conversation_message_uuid_to_agent_runs`, `add_event_id_to_job_runs`, `create_workflow_tables`.
- `release.json`: released, verification passed, health names `b96a396`. `language_policies` v1 active.
- Posture after release: `ai.runtime` off, Meta inbound and forwarded off, support / analytics / executive paused at A0 (enabling user 1), 0 active autonomy grants, 0 open shadow periods.

## 2. Code, tests, CI (unchanged since the freeze)
- Local full suite on `68cffcb`: 1,847 passed; Pint, `tsc --noEmit`, Vite build clean.
- GitHub Actions run **37413624663** on `68cffcb`: SUCCESS (Pest+Pint+TypeScript+Vite, production image build, deploy/backup safety).
- WorkflowEngine: `docs/102` (design, schema of the three workflow tables, §11 180-day retention per ruling Q3c). Empty definitions list in production.
- ConnectorRegistry: `docs/98` (empty registry, legacy bridges, structural guard; narrow `preferProvider` approved as built, ruling Q2).
- ErpPlatform: interface in place (`docs/98` §5.5a); ERP token verified by readiness at 2026-10-09T22:05Z.
- Support state tests: `SupportStateLifecycleTest` as merged; Q1 (b) kept the second-inbound hand-over, no code change.

## 3. P3-G2 attempt 5: GATE PASSED
Run 2026-10-10 03:10:41 to 03:16:03 UTC on `b96a396`, provider OpenAI (Luna low / Terra medium / Sol high, reasoning effort low). Log `/tmp/p3-g2-attempt-5.log` on the VPS.

| Suite | Eval run | Graded | Passed | Failed | Unmeasured | Skipped | Agreement |
|---|---|---|---|---|---|---|---|
| `behaviour.support` | #45 | 43 | **43** | 0 | 0 | 0 | 1 |
| `safety.core` | #46 | 80 | **80** | 0 | 0 | 0 | 1 |

- Probes: dry probe ok, bypass 200 `accepted`, live probe ok (ai_run #1550), structured reply valid against the agent schema (ai_run #1551).
- Model calls in the window: 96 ai_runs, estimated **$0.89**. (`evaluation_runs.cost_usd` records 0.000000 for both runs: a recording gap, see §5.)
- Every check green, including `reply_script` 16/16, `language_requirement` 1/1, `agent_woken` 43/43, `must_not_call` 43/43 and 80/80, `support_workflow` 8/8.
- Agent runs: 97 support runs (42 answered, 52 refused, 3 clarified). Before-model stops audited: security intent 24, prohibited capability 11, no task 9; silent hand-overs 4; thread bound 33.

### Language contract (docs/92), totals
- 33 Support thread runs carry `language_requirement`: `final` = valid 27, no_reply_proposed 6, **invalid 0**.
- Repairs attempted 0, successful 0, rejected 0. No `reply_script_*` audit action fired. **Final script violations: 0.**

### `sim.v2.mixed.script.agent_active`, every field
| Field | Value |
|---|---|
| Required language / script / policy version / rule | `bn` / `bengali` / v1 / `mixed` (thread language detected `banglish`) |
| Original script | `bengali` (stage `proposal`, reason `valid`; 15 prose words, other-script share 0 of max 0.2) |
| Repair required? | no (`repairs_attempted` 0) |
| Repaired script | n/a |
| Final validation | `valid` |
| Checks | `replies`, `agent_woken`, `reply_script`, `language_requirement`, `must_not_call` all pass |

## 4. Posture after the gate (runner step 6, audited #3405, #3406)
`ai.runtime` off, support paused, all three agents at A0, grants 0, consent events 0. The gate activated nothing lasting.

## 5. Findings to record (none blocks the pass)
1. **Shop admin tokens still rejected** (LaLaDia #15, Bionic #17, since 22 Sep). Two cases (`support.v2.order_status_in_english`, `sim.v2.mixed.script.agent_active`) called `commerce.list_orders` and got `credential_invalid` from the shop; both passed on their graded checks, so the order-lookup path was graded on the agent's handling of a failed lookup, not on a live order answer. Joy's owner item: new tokens.
2. **`evaluation_runs.cost_usd` = 0 on both runs** while the 96 ai_runs in the window total $0.89 estimated. Cost attribution from ai_runs to evaluation runs is not being written. Small, its own change, after a ruling.
3. Readiness warning unchanged: `HQ_ERP_BASE_URL` / `HQ_ERP_TIMEOUT` absent from `deploy/.env` (default applies).

## 6. Next, per docs/101 §11
Only after this pass: **Joy opens the Executive seven-day shadow week by his own name** (`hq:shadow:open executive --by=<his HQ login> ...`, first without `--apply`). The daily briefing job stays independent and waits for his approval.
