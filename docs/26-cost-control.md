# 26 - Cost Control

## Model router

`ModelRouter::for(TaskType, Context): ModelChoice`. Tiers are configuration (`model_tiers` table) so a price change is a row edit, not a deploy.

| Tier | Tasks | Default model (Sep 2026, provider-agnostic slot) | Fallback |
|---|---|---|---|
| LOW | classification, intent, extraction, summarisation, FAQ with retrieved answer, NL-to-schedule parsing, QA rule checks | small OpenAI model (gpt-5-mini class) | Anthropic Haiku 4.5 |
| MEDIUM | customer conversation, content drafting, analytics narration, campaign diagnosis | mid OpenAI model or Claude Sonnet 5 | the other |
| HIGH | strategy, complex decision support, architecture, difficult escalations, weekly plans | Claude Opus 5 or top OpenAI reasoning model | the other |
| SPECIALISED | image, video, TTS, realtime voice, embeddings | OpenAI image / Realtime, HeyGen, TTS provider, `text-embedding-3-small` | provider-specific |

Exact model ids are read from the `claude-api` reference and the OpenAI model list at implementation time, never hard-coded in business code. The router logs tier, model and cost on every run; a per-agent daily cap and a per-business monthly cap trigger downgrade-to-LOW then refusal with notification.

## Techniques

- Prompt caching (Anthropic cache control, OpenAI cached prefixes) for the stable system + persona + policy prefix; the variable part goes last.
- Retrieval instead of dumping: token budgets per tier (doc 09); tools return compact typed facts, never whole records.
- Structured outputs cut retries; JSON schema enforcement on every action-producing call.
- Batching for non-urgent work (nightly summaries, embeddings) via provider batch APIs where available.
- Asynchronous everything except a live customer turn.
- Media generation only on demand with a daily cap per brand; product photos from the library, never regenerated.
- Skills convert repeated LLM work into deterministic steps or LOW-tier calls.

## Tracked on every run

Input tokens, output tokens, cached tokens, latency, provider, model, estimated cost; rolled up to workflow cost, conversation cost, customer cost, order cost, content item cost, campaign cost and brand cost.

## Unit economics tracked

AI cost per conversation, per AI-created order, per customer (monthly), per content item, per campaign (diagnosis + changes), per brand, plus platform costs (VPS, storage, telephony minutes). Shown next to the business value where it exists (delivered order value, engagement), so the owner sees whether a BDT 1,100 order cost BDT 3 or BDT 300 of AI.

## Budget defaults (to confirm)

Total AI spend cap USD 50/month at start, raised with evidence; media generation cap 20 images/day per brand; voice minutes cap once Phase 7 starts.
