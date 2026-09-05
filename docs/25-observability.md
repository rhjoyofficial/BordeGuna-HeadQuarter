# 25 - Observability

## What is recorded for every AI action

`ai_runs`: agent, model, provider, task/skill, input ref (conversation, event, job), prompt version, tokens in/out, cached tokens, cost USD, latency ms, result status, error class, approval id, final outcome (delivered / refused / escalated).
`tool_calls`: run id, tool, args (redacted per schema), output summary, policy result, connector latency, HTTP status, idempotency key, retries.
`job_runs`, `workflow_steps`, `events`: as in docs 21, 22, 07.
Structured JSON logs (Monolog) with `run_id`/`request_id` correlation; shipped to a Loki-style store only if volume ever justifies it, otherwise queried in Postgres and files.

## Dashboards (in HQ, first cut)

| Dashboard | Panels |
|---|---|
| AI health | success rate by agent, failures by error class, p50/p95 latency by tier, tool error rate by connector, escalations per 100 conversations, automated actions per day, approvals pending/overdue |
| Cost | spend by provider/model/agent/brand per day, cost per conversation/order/customer/content item/campaign (doc 26), budget burn vs cap |
| Business impact | orders assisted by AI, AI-created orders and their delivered rate, replies without human, content published, campaign changes and their measured effect |
| Platform | queue depth and wait (Horizon), scheduler tick freshness, webhook inbox lag, connector circuit states, token expiries, disk/RAM of the host |

## Alerting

Telegram (the channel already in daily use) for: scheduler tick missed > 3 min, Horizon workers down, webhook signature failures spike, connector circuit open > 10 min, daily AI budget at 80%/100%, approval SLA breach, backup failed, disk > 80%, RAM available < 1 GB. Weekly digest of the AI health dashboard to the owner.

## External uptime

Uptime Kuma container (small) probing `hq`, `erp`, both shops, `econic.store`, n8n; or Hostinger's monitoring if sufficient. Health endpoints return dependency status (db, redis, storage, ERP reachable, shop reachable).

## Tracing model quality

Every AI reply and draft stores a pointer to the exact prompt version and retrieved context so a bad output can be reproduced. Human edits are diffed and counted (doc 29).
