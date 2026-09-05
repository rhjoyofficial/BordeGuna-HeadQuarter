# 05 - Data Architecture

## Stores

| Store | Engine | Holds | Why |
|---|---|---|---|
| HQ primary | PostgreSQL 17 (own container, `hq_db`) | everything HQ owns: businesses/brands mirror, agents, skills, tools, policies, approvals, scheduled jobs, workflow runs, events, conversations, customer identities, memory, assets metadata, ai_runs, audit | JSONB + pgvector in one engine; the ERP's MySQL stays separate so a runaway HQ query cannot touch payroll |
| Vector search | `pgvector` extension in `hq_db` | embeddings for SOPs, brand knowledge, product descriptions, conversation summaries | one engine to back up; volume is small for years |
| Cache / queue / streams | Redis 7 (own container, AOF on) | Horizon queues, locks, rate limits, event fan-out | standard Laravel infrastructure |
| Object storage | S3-compatible bucket (B2 or Hostinger Object Storage) | media, generated images/video, exports, encrypted backups | off the VPS disk by construction |
| Systems of record | ERP MySQL 8, shop MariaDB/MySQL, Woo MySQL | unchanged | HQ reads through APIs only |
| Analytics warehouse | none for now | | volume (dozens of orders per month) does not justify one; HQ `facts_*` tables cover reporting until it does |

## Core HQ schema (first cut)

```
businesses(id, erp_business_id, name, slug, timezone, is_active)
brands(id, business_id, code LLD|BNC|..., name, persona_id, guidelines jsonb, platform_accounts jsonb)
users, roles, permissions           (spatie, mirrors ERP role names where they overlap)

agents(id, key, name, role, system_prompt_ref, model_tier, tool_keys[], memory_scope, kpis jsonb, is_active)
skills(id, key, name, purpose, inputs_schema, outputs_schema, required_tools[], permissions[], policy_keys[], owner, version, status draft|testing|approved|active|retired, tests_ref, metrics jsonb)
tools(id, key, class, inputs_schema, permission, policy_class, action_class AUTO|APPROVAL_REQUIRED|HUMAN_ONLY, is_active)
policies(id, key, scope, rules jsonb, version)

scheduled_jobs(id, business_id, name, kind cron|once|interval|event, expression, run_at, timezone, payload jsonb, tool_key, approval_state, retry_policy jsonb, state active|paused|cancelled|done, next_run_at, last_run_at)
job_runs(id, scheduled_job_id, started_at, finished_at, status, attempt, output jsonb, error)
workflow_runs(id, key, state, context jsonb, current_step, started_at, finished_at)
workflow_steps(id, run_id, name, status, input jsonb, output jsonb, attempt)

events(id, type, source, business_id, payload jsonb, occurred_at, dedupe_key unique, processed_at)   -- inbox/outbox
event_subscriptions(id, event_type, handler, is_active)

customers(id, business_id, primary_phone_normalized, name, email, ltv, aov, first_seen, last_seen, consent jsonb, attributes jsonb)
customer_identities(id, customer_id, channel fb|ig|wa|web|phone|email|shop|erp|woo, external_id, confidence, verified_at)
customer_memory(id, customer_id, kind structured|episodic|semantic, content jsonb, embedding vector, source, created_at)

conversations(id, business_id, channel, customer_id, external_thread_id, state, assigned_user_id, escalation_reason, opened_at, closed_at)
messages(id, conversation_id, direction, actor human|ai|customer, body, attachments jsonb, external_id, sent_at)

approvals(id, subject_type, subject_id, action_class, requested_by agent|user, payload jsonb, reason, status, decided_by, decided_at, sla_due_at)
ai_runs(id, agent_id, model, provider, task, input_ref, output_ref, tokens_in, tokens_out, cost_usd, latency_ms, status, error)
tool_calls(id, ai_run_id, tool_key, input jsonb, output jsonb, policy_result, approval_id, duration_ms, status)
audit_log(id, actor_type, actor_id, action, target_type, target_id, before jsonb, after jsonb, reason, approval_id, ai_run_id, created_at)

assets(id, brand_id, kind, storage_key, mime, width, height, duration, checksum, rights jsonb, tags[], links jsonb)
media_identities(id, person_name, avatar_ref, voice_ref, consent_doc_ref, permitted_uses[], brands_allowed[], expires_at, revoked_at)

marketing_experiments(id, brand_id, objective, audience jsonb, offer, creative_asset_ids[], budget, platform, hypothesis, kpi, result jsonb, decision, external_ids jsonb)
content_items(id, brand_id, idea, strategy, script, copy jsonb, asset_ids[], status, approval_id, platform_variants jsonb)
publications(id, content_item_id, platform, account_ref, scheduled_job_id, external_post_id, published_at, metrics jsonb)

integration_credentials(id, provider, account_ref, encrypted_payload, scopes[], expires_at, refreshed_at, status)
facts_orders(business_id, order_ref, source, placed_at, gross, delivered_at, cancelled_at, cogs, shipping_cost, campaign_ref)   -- read model
facts_ad_spend(platform, account, campaign_id, date, spend, impressions, clicks, conversions)
```

## Rules

1. **Read models, never masters.** `facts_*` and the customer profile are rebuildable from the systems of record. A rebuild command must exist from day one.
2. **Every business-scoped table carries `business_id`** and uses a global scope that fails closed, exactly as the ERP does.
3. **Idempotency everywhere it touches money or messages**: `events.dedupe_key`, `tool_calls` carry an idempotency key passed to the connector, scheduled job runs are keyed by `(job_id, scheduled_for)`.
4. **Append-only where it matters**: `audit_log`, `ai_runs`, `tool_calls`, `messages`, `approvals` decisions are never updated after the fact.
5. **The vector store is never the source of transactional truth.** It holds text and pointers; the pointer is resolved against the master before it is shown to a customer.
6. **Retention**: raw `ai_runs` payloads 90 days, then summarised; `messages` kept; embeddings rebuildable.

## Migrations

Laravel migrations, one per change, reversible where the data allows; destructive changes require a verified backup first (existing standing rule). A scratch Postgres container validates every migration before production.
