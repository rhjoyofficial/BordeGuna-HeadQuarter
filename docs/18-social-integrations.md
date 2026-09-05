# 18 - Social Integrations

## Capability metadata (the point of this document)

Every platform account is a row in `platform_accounts(brand_id, platform, account_ref, capabilities jsonb, scopes[], approval_status, quotas jsonb, restrictions jsonb, credential_id)`. Adapters consult it before promising anything. No adapter assumes an operation is supported, and **the UI and the AI are shown only the operations that account currently supports** (capability row, not platform in general). Publishing follows ADR-011: each publication has a `logical_key`; a retry after an uncertain provider response reconciles by looking the post up before ever creating a second one.

| Platform | Publish text | Image | Video/Reel | Story | Schedule natively | Read comments | Reply | DMs | Insights | Prerequisites (verified state) |
|---|---|---|---|---|---|---|---|---|---|---|
| Facebook Page | yes | yes | yes | limited | yes (`scheduled_publish_time`) | yes | yes | yes (Messenger) | yes | Page token per Page (Bionic token exists in n8n; LaLaDia, Econic, Azmion, BDG Pages need tokens); App Review for messaging/engagement scopes; **app in Dev Mode** |
| Instagram (professional) | caption only with media | yes | reels yes | yes | no (HQ schedules) | yes | yes | yes (IG messaging) | yes | IG account linked to the Page; `instagram_content_publish`, `instagram_manage_comments`, `instagram_manage_messages`; App Review; **UNKNOWN which brands have linked IG accounts** |
| WhatsApp Business | n/a (messaging) | | | | | | | yes | limited | WABA per brand, templates approved, display number; **not set up for Bor De Guna** |
| TikTok | via Content Posting API | photo posts | yes | no | no | limited | limited | no | limited | developer app + audit; **no account exists** |
| YouTube | n/a | n/a | yes (Data API v3) | n/a | yes (publishAt) | yes | yes | no | yes | Google Cloud project, OAuth, quota 10k units/day (~6 uploads); **channel UNKNOWN** |
| LinkedIn Page | yes | yes | yes | no | no | yes | yes | no | yes | Community Management API access (application required); pages exist (End Brackets, Bôr dé Güna, Bionic Garden) |
| X | yes | yes | yes | no | no | yes | yes | paid tier | limited | paid API tier; **account UNKNOWN**; low priority |

## Adapter contract

`PublishingPlatform`: `capabilities()`, `publish(ContentVariant, at?)`, `delete(postId)`, `fetchMetrics(postId)`, `fetchComments(since)`, `replyToComment`, `refreshCredentials()`. Every call idempotent by `(content_item_id, platform, account)`; a repeat publish returns the existing post id.

## Credentials and tokens

Long-lived Page tokens derived from a system user under the **boss's new official Meta profile/portfolio** (Joy, Q12/Q13, 5 Sep 2026: all LaLaDia and Bionic Meta access moves to, or is created under, that account, not Joy's personal End Brackets portfolio). Stored in `integration_credentials`, refreshed by a scheduled job, alerted 14 days before expiry. Tokens never appear in prompts or logs.

## Meta App Review scope list (one app for all Bor De Guna Pages)

`pages_show_list`, `pages_read_engagement`, `pages_manage_posts`, `pages_manage_engagement`, `pages_messaging`, `pages_read_user_content`, `instagram_basic`, `instagram_content_publish`, `instagram_manage_comments`, `instagram_manage_messages`, `business_management`, `ads_management` (for doc 16), `ads_read`. Each needs a screencast and a privacy policy URL per brand domain. This review is the single longest external dependency in Phases 3-6 and should be started in Phase 1.

## Comment handling

`facebook.comment_received` -> classify (question, order intent, complaint, spam, praise) -> reply within persona for questions with live facts, private-reply for order intent, escalate complaints, hide spam (approval-gated at first). Every reply is audited and shown in the conversation inbox.
