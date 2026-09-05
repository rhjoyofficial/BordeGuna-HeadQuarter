# 16 - Marketing Architecture

## Platform-neutral core

Core concepts: marketing objective, audience, offer, creative, budget, experiment, KPI, campaign, ad set/ad group, ad, metric, attribution, profit. Platform objects are mirrors of an experiment, never the model.

```
MarketingExperiment
  id, brand, objective (purchase|lead|traffic|awareness), audience (spec), offer, creatives[] (asset ids),
  budget (daily, total, currency), schedule, platform(s), hypothesis, kpi (name, target),
  status draft|approved|live|paused|ended, result (metrics snapshot), decision (scale|hold|kill), notes
Campaign (platform object mirror): experiment_id, platform, external ids (campaign/adset/ad), status, last_synced
Guardrails: from doc 12 (budget deltas, max spend, CPA/ROAS thresholds, learning window, stock protection)
```

Adapters implement `MarketingPlatform`: `createCampaign(Experiment)`, `updateBudget`, `pause`, `resume`, `delete`, `syncMetrics(window)`, `capabilities()`.

| Adapter | API | Status | Notes |
|---|---|---|---|
| `MetaAdapter` | Marketing API v25 (campaign, adset, ad, insights) | build in Phase 5 | ad account `28164410319885213` (BDT) today; per Joy (Q12, 5 Sep 2026) ad and Page access moves under the boss's new official Meta account, so the adapter reads account ids and tokens from `platform_accounts`/`integration_credentials`, never from code; a system-user token with `ads_management` is required (the current CAPI token has events scope only) |
| `GoogleAdsAdapter` | Google Ads API | later | no Google Ads account exists; needs Merchant Center + product feed first for Shopping/PMax |
| `TikTokAdsAdapter` | Marketing API | later | no account; needs pixel + catalog |

## What already exists (verified)

Two Meta campaigns built by hand (beef pickle trial: BDT 212 spent, 137 clicks, 0 purchases, closed; hilsa: paused). Pixels + CAPI healthy on both shops, GTM containers, domain verification. Learned: existing-post ads carry organic engagement; the Ads Manager "existing post" URL edit bug; the Destination tab trap; 48-hour learning phase; only ~21 customers so lookalikes are impossible. These are the seed of `knowledge_documents` for the advertising agent.

## Automation loop (Phase 5)

```
hourly sync (insights) -> facts_ad_spend
  -> anomaly detection (CPA/ROAS/CTR vs 7-day baseline, spend pacing, zero delivery)
  -> diagnosis (agent reads creative, audience, landing page speed data, stock)
  -> recommendation with expected effect
  -> policy: within guardrails -> apply; else -> approval
  -> apply via adapter (idempotent, audited)
  -> monitor window -> evaluation -> decision recorded on the experiment
```

Rules that came from real spend: never touch a campaign in its first 48 h except stop-loss; read revenue as delivered revenue (COD cancels ~33%); a Purchase-optimised ad must point at the website, not Messenger; always check the placement and age breakdowns before killing a creative.

## Reporting

Per experiment and per brand: spend, impressions, clicks, CTR, CPC, landing views (the click-to-view leak found on 1 Sep is a first-class metric), add-to-cart, checkout, purchases, delivered purchases, CPA, delivered CPA, gross profit (needs ERP cost), and **contribution profit** = delivered revenue - product cost - shipping cost - payment fees - refunds and cancellation effects - allocated ad spend. ROAS is shown, never optimised for on its own; the objective is profitable growth. Doc 23 defines the joins.

## Guardrail example (as configured)

`update_campaign` raising daily budget by <= 20% and keeping total daily spend <= BDT 3,000 -> AUTO. Anything beyond -> APPROVAL. `pause_campaign` when CPA over 48 h > BDT 900 -> AUTO (safe direction). `delete_campaign` -> HUMAN_ONLY.
