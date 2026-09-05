# 17 - Content System (Content Factory)

## Pipeline

```
idea -> research -> strategy -> script -> hook -> copy -> image -> video -> voice -> avatar
 -> composition -> QA -> approval -> platform adaptation -> scheduling -> publishing -> analytics -> learning
```
Each stage is a `workflow_step` on a `content_items` row; stages can be skipped per content type (a text post has no video stage). Every stage output is an `assets` row or a JSON field on the item, so a human can pick up at any stage.

## Content item model

`content_items(id, brand_id, type post|reel|story|video|carousel|article, idea, research jsonb, strategy, script, hook, copy jsonb {primary, headline, cta, hashtags}, asset_ids[], status idea|drafting|qa|awaiting_approval|approved|scheduled|published|failed, approval_id, platform_variants jsonb, source skill|human|calendar, campaign_ref)`.

## Generation

| Stage | Provider (via ModelRouter / media tools) | Notes |
|---|---|---|
| research, strategy, script, copy | text model, medium tier; high tier for strategy | brand persona (doc 19) injected; claims policy enforced by a QA check |
| image | OpenAI image generation (already used in n8n), or the existing content-render compositor for product-on-template images | product photos come from the asset library, never generated |
| video | HeyGen avatar (key exists), stock composition; later other providers | consent gate (doc 20) |
| voice | TTS provider or cloned voice (doc 20) | |
| QA | rule checks (banned words, claims, phone/price correctness against live tools) + a low-tier model review | fails closed to human review |

## Platform adaptation

One idea -> variants per platform with the platform's constraints from `18-social-integrations.md` capability metadata (aspect ratio, length, hashtag rules, link policy). Bangla copy uses the digit and typography rules already learned (Latin digits inside Bangla runs render ambiguously in some fonts).

## Scheduling and publishing

Lifecycle: draft -> generate -> QA -> approve -> schedule -> publish -> measure -> learn.

"Tomorrow at 5 PM post this image on LaLaDia Facebook" runs these twelve steps: (1) resolve the brand (LaLaDia), (2) resolve the platform account (the La La Dia Page row and its credential), (3) resolve the asset (the attached image becomes an `assets` row), (4) preserve caption and hashtags exactly as given, (5) determine the timezone (Asia/Dhaka unless stated), (6) validate the account's capability row allows image posts, (7) create the `scheduled_jobs` row and the `publications` row with its `logical_key`, (8) request approval if the tool's action class or `content.auto_publish` requires it, (9) execute at the scheduled time through `publish_content`, (10) verify publication (fetch the post back by id; reconcile by logical key if the provider response was uncertain), (11) record the result on `publications` and the run, (12) notify the owner with the permalink or the failure reason. Metrics are collected at +1 h, +24 h, +7 d.

## Calendar

Per brand, per week, with slots by type. The `weekly_content_plan` skill fills gaps as drafts; humans approve. The n8n `content_calendar` table (empty) is superseded by HQ's model.

## Learning

Per publication: reach, engagement, clicks, orders attributed (doc 23). Weekly job summarises what worked per brand into `knowledge_documents` the content agent retrieves.

## What exists to reuse

n8n content workflows (lookup, generate, compose, publish to Facebook, HeyGen video) prove each provider integration works; HQ re-implements them as tools with the same provider calls, then the n8n copies are retired.
