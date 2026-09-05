# 29 - Testing and Evaluation

## Software tests

| Layer | Tool | Rule |
|---|---|---|
| Unit | Pest (PHP), Vitest (TS) | policies table-driven at boundary values; DTO validation; NL-to-schedule parser against a fixture set |
| Integration | Pest with Postgres 17 + Redis in CI | connectors against recorded HTTP fixtures (no live calls in CI); event inbox/outbox; scheduler tick exactly-once; workflow resume after crash |
| Contract | recorded upstream responses per connector version | a changed upstream shape fails CI, not production |
| Browser | Playwright | Command Center, Approval Center, Inbox takeover/resume, scheduler UI; run against the built app, never the dev server |
| Route registration | test that records every route `add()` | prevents the route-shadowing defect seen in LaLaDia |
| Smoke in production | after each release (doc 27) | a real login, a real read tool, Horizon alive, tick alive |

Standing rules inherited from the other codebases: a new test is watched failing before the fix; a green suite is not evidence the running system works, so every phase ends with the feature exercised against real systems; specs that create data clean up by id in `afterAll`.

## AI evaluation

| Set | Contents | Run |
|---|---|---|
| Golden conversations | 50+ real (anonymised) customer conversations per brand with expected intents, tool calls and acceptable replies | on every prompt or model change; agreement thresholds gate the deploy |
| Decision replay | stored `decision_records` (agent proposal vs human final) | weekly; reports agreement rate and edit distance per agent; drift alerts |
| Tool-call correctness | inputs -> expected tool + args | on every tool schema change |
| Safety | prompt-injection corpus, secret-leak probes, other-customer-data probes, policy-bypass attempts | on every prompt change and monthly |
| Cost regression | tokens per task type vs baseline | weekly |

Scoring uses deterministic checks first (did it call the right tool with the right args, did it refuse when policy says refuse), then a LOW-tier model as judge for tone/persona with spot checks by a human. Results are stored per prompt version in `evaluations`.

## Shadow mode

Every new automation runs in shadow first: it consumes real events and writes what it *would* do to a `shadow_actions` log without executing. A week of comparison against the current process (n8n, or a human) precedes activation. This is how the Messenger bot moves from n8n to HQ (doc 22).

## Staging

Sandbox credentials only (Meta test app and test Page, shop staging instance or `dry_run` connector mode, OpenAI/Anthropic with low caps). Production credentials never enter staging.
