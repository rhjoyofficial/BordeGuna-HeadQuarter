# 144. Meta readiness (Phases 3 and 5): decision record (31 October 2026)

## Question

What is left to make the Messenger, Instagram and WhatsApp connector and the Meta Ads connector ready for Joy to connect, without activating anything?

## What was verified (read, and run by the existing tests)

- **Inbound webhook** (`MetaWebhookController`): `X-Hub-Signature-256` is an HMAC-SHA256 of the raw body with the Meta app secret, compared in constant time;
  a missing or wrong signature is 401 and counted by the signature-failure alert; no stored secret is 503; the flag `channels.meta.inbound` OFF is 404; the
  verify-token handshake is separate. Covered by `MetaNormaliserAndWebhookTest` (existing, green). The n8n copy-forward has its own secret.
- **Outbound adapters** (`MetaMessengerChannel`, `MetaInstagramChannel`, `WhatsAppCloudChannel`, the delivery ledger): built, inert, tested (`MetaSendAdaptersTest`,
  `ChannelAdaptersInertTest`), and deliberately NOT registered as connector rows. `ChannelAdaptersInertTest` pins that the WhatsApp adapter is named only in its own
  folder; registering the three messaging adapters belongs to gate P3-G4 (docs/137), after Business Verification and the shadow comparison, not to this stream.
  Nothing is lost: the credential kinds (`meta_page_token`, `meta_instagram_token`, `whatsapp_access_token`, `meta_app_secret`, `meta_webhook_verify_token`)
  are all on the Credentials screen already.
- **Ads read side** (`MetaAdsPlatform`, `MetaAdsReader`, the four `ads.*` read tools): built and inert under their own four locks (docs/88 A point 4); unchanged.

## What was added

- **Token health.** A screen section on `/connectors` listing every stored Meta token (Page, Instagram, WhatsApp, ads read, ads draft) with state (valid, not valid,
  expires soon within 14 days, not checked), expiry, last checked and scopes, narrowed to the businesses the person may see (an HQ-wide token only by its kind
  and account reference to someone unrestricted). A "Check now" button calls the gateway tool `meta.check_token_health` (R2, read-only at Meta, permission
  `hq.tools.meta.health`, flag `connectors.meta_health`), which asks Meta's `GET /debug_token` with the app access token `<app id>|<app secret>` and records
  validity, expiry and scopes on the credential's own row. The token and the app secret come only from the vault scope and appear in no message or row. It needs
  `HQ_META_APP_ID` (not a secret) and the stored `meta_app_secret`; the screen says when either is missing.
- **The Meta Ads draft connector** (`144-meta-ads-drafts.md`).
- Webhook readiness lines on the same screen: app secret stored, verify token stored, webhook intake flag, app id configured.

## Pause All AI

No AI entry point is added by Meta work. The existing agent paths (support wake-up, shadow) already check `AiPause`; the draft tool and the token check call no model.

## Unproven until real credentials exist

`debug_token` field names are Meta's published ones; the first live check proves them. Whether a token is a system-user token (no expiry, `expires_at` 0)
is read from the answer. App Review for Advanced Access is a Meta-side task (docs/97).

## Questions for ChatGPT

1. Register the three messaging adapters as connector rows now (still OFF), or only at P3-G4? (Default: P3-G4.)
2. Alert on a token expiring in 14 days through the existing alert rules? (Default: shown on the screen; an alert rule is a follow-up using `CredentialExpiring`.)
