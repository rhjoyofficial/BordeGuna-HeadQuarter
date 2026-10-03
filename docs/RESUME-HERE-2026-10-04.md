# RESUME HERE (4 October 2026): where HQ stopped, and exactly what comes next

Written at Joy's request ("after completing the module work and other agents work, stop working, sync memory and make a resume point"). All agent work is finished and merged; nothing is running.

## 1. State in one paragraph
`main` = **`627f937`** (pushed). Production unchanged at **`531d59b`**, inert (AI runtime off, all agents paused at A0, no grants, Meta inbound off, no providers). Local full suite on `627f937`: **1,846 passed**, Pint, `tsc --noEmit`, Vite build clean. CI run **37147877132** for `627f937` was started at the stop (previous CI run 37144869902 on `cd25366` = SUCCESS, the first green since 16 Sep). **First action on resume: check that run** (`gh run view 37147877132 --repo rhjoyofficial/BordeGuna-HeadQuarter`).

## 2. What was done 3-4 Oct (all merged to main)
| Item | Where | Branch / commit |
|---|---|---|
| Vision → system gap audit of Joy's vision document | `docs/95` | `56a460f` |
| Standing instruction: build-vs-integrate + ChatGPT as the architecture decision layer | `docs/96` (+ memory `feedback_hq_build_vs_integrate_and_chatgpt_layer`) | `56a460f` |
| External Capability & Integration Strategy, APPROVED by ChatGPT (D1-D4) | `docs/97` §6, appendices `docs/97-research/` | `56a460f` |
| W1 language contract (versioned script policy, ScriptPolicyValidator, one repair) + D4 (script check after gateway authorization, before execution; `content_policy_rejected`) | `docs/92` §C | `3237de0`, `420904f` |
| W2a inert live Support wake-up, hand-over ping, inbox context package | `docs/100` | `d107361`…`f06bbe1` |
| W2b event-triggered jobs, delivery reconciliation, Meta seed ids, CredentialKinds, approval-gated daily briefing | runbooks, README | `98d2662`…`07e6534` |
| W3 ConnectorRegistry (empty; legacy bridges; structural guard) | `docs/98` | `e26c055`, `a2ee9b0` |
| Status report + ChatGPT ruling 9.1-9.3 | `docs/101` §11 | `2777d31`, `4149baa` |
| W6 preferProvider (narrow), `ErpPlatform` interface, Support lifecycle tests + `ai_resumed` fix, effectful-proposal governance test | `docs/98` §2.4, §5.5a | `3c31852`…`8b0dab1`, merge `0aa84cf` |
| W5 HQ-native WorkflowEngine (746 logic lines; scheduler + outbox; every effect through the gateway; empty definitions list) | `docs/102` | `ea3da95`, merge `627f937` |
| Not merged (kept): durable-workflow spike, verdict "works but too heavy" | `docs/99` on branch `spike/durable-workflow-evaluation` (`5cb253a`, worktree `hq-wt\i` history) | — |

## 3. The exact next steps, in the order ChatGPT set (docs/101 §11)
1. **Confirm CI 37147877132 green** (fix anything it finds; never call a local run "CI green").
2. **Take the open questions to the ChatGPT HQ thread** (https://chatgpt.com/c/6a9baf11-c2c8-83ee-a43b-6bc7a76498c3), in one message, before freezing:
   - **Q1 (needs a ruling, affects P3-G2 and the shadow week):** ruling 9.3 says `ai_waiting` + a new customer message → `ai_active`, but the code deliberately escalates that to a person (`second_inbound_while_waiting`, docs/72 §B, `EscalationTriggers::reasonFor`, tested in `ConversationCoreTest` and the simulation scenario). Today the agent answers once per thread. If the ruling stands: drop the `AiWaiting` clause in `EscalationTriggers::reasonFor`, flip the two expectations and the pinned test in `SupportStateLifecycleTest`.
   - **Q2:** confirm preferProvider's reach as built: any READY connector in the serving tier (not only a tie at best priority), never across primary/fallback, never to a not-ready connector (needed for `model_tiers.provider` choosing OpenAI over a better-priority Anthropic row).
   - **Q3 (WorkflowEngine, docs/102 §6):** a rejected approval = step `declined` and the run continues (vs. ending the run); an approved-but-unconsumed approval waits for a person (vs. `reconcile` flags it); add a pruning rule for the three workflow tables before the first real workflow; minute-tick latency accepted; agent-started runs (EEC re-check per step) out of scope.
   - **Q4 (latent, for the record):** if a Support reply ever executes for real (A2+), `SupportWakeUp::settle()` would read "no proposal" and hand over; impossible today (ADR-024 caps customer-reading runs at A1), must be handled before that cap is lifted.
   - **Q5 (record):** only `messaging.send_reply` reaches the gateway among real tools today; the other 8 effectful tools stop at the runtime's own checks.
3. Apply any code-affecting ruling (especially Q1), full suite, CI.
4. **Freeze the candidate from green main** (`deploy/deploy.sh --verify-only` preflight).
5. **Joy's release line** (he types it; the auto-mode classifier blocks Claude from deploying).
6. **P3-G2 attempt 5** on that candidate (runbook `docs/runbooks/p3-g2-support-gate.md`, new attempt-5 fields; gate launch is Joy's to type with `!`). Report per docs/101 §11 (commits, tests, CI, WorkflowEngine design/schema, ConnectorRegistry changes, ErpPlatform status, Support state tests, attempt-5 result).
7. Only after attempt 5 passes: **Joy opens the Executive shadow week** (`hq:shadow:open executive --apply --by=<him>`).

## 4. Joy's owner items (unchanged; docs/97 §5 J1-J8 and docs/95 §7)
Meta Business Verification (slowest clock, gates WhatsApp / Messenger at scale / Lead Ads / ads writes / publishing) · WhatsApp SIM in the company's name · company dual-currency card on each provider (≤ $300 per top-up) · new LaLaDia + Bionic admin tokens (rejected since 22 Sep) · Hostinger shop→HQ network block · product photos + brand kit · test sets (50 voice notes, 50 Banglish messages, 30 documents) · legal read (PDPA 2026, BTRC) · which SMS aggregator the ERP uses · VPS RAM vs KVM 4 · run once and approve the daily briefing job (`hq:scheduler:ensure "Daily owner briefing" --tool=briefing.compose --cron="0 9 * * *" --created-from=ui --owner=<email> --apply`) · confirm the LaLaDia ad account id `530866839523958` in the seed against Meta.

## 5. Worktrees and branches (all merged except the spike)
`hq-wt\e` fix/support-language-contract · `hq-wt\f` p3/live-wake-up-and-handover-ping · `hq-wt\g` platform/prefer-provider-erp-platform · `hq-wt\h` platform/event-jobs-reconcile-seeds · `hq-wt\i` platform/workflow-engine (spike branch `spike/durable-workflow-evaluation` kept, not merged). Test DBs `hq_test_e..i`. Safe to reuse any of them for the next stream (cut a fresh branch from main).

## 6. Rules in force (do not reopen)
docs/96 standing instruction; docs/97 §6 and docs/101 §11 rulings; no Backblaze B2; no prompt tuning to pass a case; never alter historical gate evidence; stage by path; one worktree + one test DB per agent; green branches ≠ green merge (full suite on the merged tree); never call local "CI green"; never mention End Brackets or Joy's personal work in HQ material.
