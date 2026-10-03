# 101 - Status report after the docs/97 ruling (4 October 2026)

Required by the ChatGPT ruling recorded in `docs/97` §6 (last bullet). Sent to the HQ thread for review and three rulings (§9).

## 1. Commits and releases
- `main` = **`cd25366`**, pushed. Production unchanged at **`531d59b`** (inert). No release made; no flag, agent, provider, tool or credential changed anywhere.
- Merged streams (each built in its own worktree and test DB, merged with `--no-ff`):
  - W1 `fix/support-language-contract` (`3237de0`, `420904f`): docs/92 language contract + the D4 ordering.
  - W2a `p3/live-wake-up-and-handover-ping` (`d107361`, `0b0db1e`, `deb15ae`, `f06bbe1`): inert live Support wake-up, hand-over ping, inbox context package, WhatsApp test clock fix.
  - W2b `platform/event-jobs-reconcile-seeds` (`98d2662`, `9efa8cd`, `29a305c`, `216a39a`, `23e136b`, `d9f400c`, `07e6534`): event-triggered jobs, delivery reconciliation, Meta id seed correction, credential kinds, the daily briefing as an approval-gated job.
  - W3 `platform/connector-registry` (`e26c055`, `a2ee9b0`): ConnectorRegistry (D2) + docs/98.
  - Merge fixes on main: `b6392ad` (bn.json conflict), `2aab64b` (shared Meta test helpers moved to Pest.php; they had made three tests order-dependent).
- Not merged: W4 spike `spike/durable-workflow-evaluation` (`5cb253a`), pending the ruling in §9.1.

## 2. Docs changed
docs/94, 95, 96, 97 (+ `97-research/A,B,C`), 92 (§A.6 note superseded by D4, §C.1-C.2 record), 98 (new), 100 (new: live wake-up record), this 101; runbooks `p3-g2-support-gate.md`, `inbox.md`, `ask-hq-and-scheduling-in-words.md`; `apps/hq/README.md`. Removed (kept in history): `RESUME-HERE-2026-09-21.md`, the 8 Sep master dossier.

## 3. Test results
- Local, merged `main`: **1,797 passed**, 21,162 assertions; Pint, `tsc --noEmit`, Vite build clean.
- **GitHub Actions run 37144869902 on `cd25366`: SUCCESS** (Pest+Pint+TypeScript+Vite on PostgreSQL 17/Redis 7; production image builds; deploy/backup safety invariant). The first green CI since 16 Sep, and the first time the deploy-safety suite has run on Linux since then.

## 4. D4 ordering proof
The Tool Gateway is split into `authorize()` (read-only: availability, arguments, scope/EEC, policy+permission, consent, credential pre-flight), `preExecutionChecks()` (`PreExecutionSafetyChecks`, opted into per tool descriptor; `messaging.send_reply` and `messaging.send_whatsapp_reply` declare `reply_script`) and `execute()` (authorization → idempotency/replay → credential pre-flight → pre-execution check → tool → record/audit). One private `authorizeSteps()` serves both paths so they cannot drift. A script refusal is its own class `content_policy_rejected`, never a permission denial, and the runtime's single repair then hand-over is unchanged. At A0 a reply is recorded as a proposal only when `authorize()` and the pre-execution check both pass; nothing executes.
Proof tests: `PreExecutionOrderingTest` (each authorization step refuses before the script check; the script check refuses before execution; a valid script never makes a refused call permitted; read-only authorize matches execute) and two runner-level D4 tests in `SupportLanguageContractTest`.
**Behaviour change to note:** at A0 the gateway is now consulted for every agent-proposed tool with an effect, not only replies; a permission/consent refusal now reaches the agent as a refusal instead of a recorded proposal. All existing Support and simulation tests pass; P3-G2 attempt 5 will show whether it matters on real threads.

## 5. ConnectorRegistry status (D2)
Built and merged; empty in this release (`config/hq-connectors.php` `connectors => []`). Typed outcomes (`Resolved`, `NoConnector`, `Unavailable`, `Ambiguous`, `UnknownCapability`); scope only narrows; primaries before fallbacks; lower priority number wins; a tie at the best ready priority refuses; credentials checked from metadata only, never a value. Readiness gains a connector view without changing the existing inventory. A structural test stops new code from adding a provider-resolution class or binding an adapter in a service provider outside the registry. 13 capability contracts named; 4 reserved without an interface (`media.image.generate`, `media.video.generate`, `voice.call`, `courier.track`). Legacy migration plan in docs/98 §5 (lowest to highest risk, each with a parity test). Not yet wired into the Tool Gateway: the first new adapter will be.

## 6. Workflow-base status
`durable-workflow/workflow` 2.3.3 evaluated in a spike on real Postgres with real worker processes (docs/99, branch `spike/durable-workflow-evaluation`, NOT merged): durability PASS (timers and signals survive worker kills; runs complete even after every queued job is deleted), retry PASS, idempotency PASS only with an HQ-supplied key per workflow step (activities are at-least-once), wait/resume PASS, Laravel/Postgres fit PARTIAL (HQ's strict lazy-loading broke every run until exempted for the package's models; recovery silently fails unless `DW_V2_CONNECTION` is set). **But it is not thin:** ~127,000 lines of PHP, 29 tables, 43 migrations, ~80 releases in September 2026, one human maintainer. Replaceability seam prototyped (`WorkflowEngine`, `WorkflowRunState`, one adapter). Ruling needed: §9.1.

## 7. n8n contract and MCP boundary
- n8n: contract recorded (signed request with run_id + idempotency key → n8n executes → callback → HQ records); not built yet, no new n8n integration started. Existing copy-forward receiver remains flag-OFF.
- MCP: zero MCP code; the boundary rule is now enforced structurally by the ConnectorRegistry test (no adapter or transport can be bound outside the registry). No MCP server allowlisted yet.

## 8. Architectural inconsistencies found
1. Seven legacy provider-resolution shapes, not five (docs/95 missed `ResearchService::PROVIDERS` and the `StorageDriver` binding).
2. `ErpConnector` is a final class with no interface, so `erp.read` points at a class; a second ERP adapter needs an interface first.
3. AI (`model_tiers.provider`) and research choose a provider by name, not by priority.
4. The docs/96 number was taken by a stream's record; renamed to docs/100. Three migrations shared one timestamp; renumbered `100000/110000/120000`.
5. WhatsApp tests failed after 23:00 Dhaka (wall-clock quiet hours) on main; fixed with a frozen clock.
6. The gateway's replay ceiling is 24 h; a durable workflow step retried later than that would run again (relevant to §9.1).
7. Production has never had a green CI run (`531d59b` was built while CI was quota-refused); `cd25366` is the first green candidate.

## 9. Rulings requested
- **9.1 Workflow base.** (a) Adopt `durable-workflow/workflow` with the six conditions of docs/99 (exact pin + spike re-run on every upgrade; lazy-loading exemption limited to package models; every external effect through the Tool Gateway with a `wf:{instance}:{step}` key and a reconsidered 24 h replay ceiling; explicit `DW_*` config and Horizon queue; activities under Horizon's 60 s or their own supervisor; `getVersion()`/`replay-verify` before deploying changed workflow classes), or (b) build an HQ-native, small state-machine workflow table on the existing durable scheduler + events outbox (HQ already has at-least-once scheduling, idempotent claims, approvals as checkpoints), behind the same `WorkflowEngine` seam. My recommendation: **(b) for now**, because HQ's first workflows (order follow-up, publish-then-verify, approval chains) are short and HQ already owns the hard parts; keep (a) as the documented upgrade path when long-running workflows with many timers appear.
- **9.2 ConnectorRegistry details.** Confirm: lower number wins, a tie refuses, fallbacks only when no primary is ready; an optional `preferProvider` that only breaks ties among ready connectors (for name-based selection in AI and research); extract an `ErpPlatform` interface before any second ERP adapter.
- **9.3 Support wake-up states.** After a proposal the thread moves to `ai_waiting` (per docs/72); any run with no reply proposal goes to a person as `agent_cannot_serve`. Confirm, or keep `ai_active` during the P3-G3 shadow.

## 10. Owner decisions (Joy)
Unchanged from docs/97 §5 J1-J8, plus: (a) the release line for the next frozen candidate (built from green `cd25366` once §9 rulings that touch code are applied), then P3-G2 attempt 5 and the shadow week; (b) run once and approve the daily briefing job (`hq:scheduler:ensure "Daily owner briefing" --tool=briefing.compose --cron="0 9 * * *" --created-from=ui --owner=<email> --apply`); (c) the LaLaDia ad account id `530866839523958` in the seed came from notes, to be checked against Meta.
