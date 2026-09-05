# 24 - Security

## Principles

Secrets never in prompts, logs, code or chat. Least privilege per human, per agent, per token. Every write audited. Fail closed.

## Credential management

`integration_credentials(provider, account_ref, encrypted_payload, scopes, expires_at, refreshed_at, status, rotated_by)` encrypted with Laravel's `APP_KEY` (AES-256-GCM) in Postgres, following the pattern already used by the shops' `courier_settings`/`sms_settings`/`integration_settings` and the ERP's `ai_credentials`. Only the connector layer decrypts; the UI shows "present/absent" and expiry, never values. OAuth flows (Meta, Google, TikTok, LinkedIn, YouTube) are implemented once in `OAuthManager` with refresh jobs and 14-day expiry alerts. Revocation from the UI deletes the row and calls the provider's revoke endpoint where one exists.

Providers to hold: Meta (system user + Page tokens + app secret), Google (service account / OAuth), TikTok, YouTube, LinkedIn, X, OpenAI, Anthropic, HeyGen/voice providers, telephony, SSLCommerz (read), ERP token, shop assistant keys and admin tokens, Woo keys, Telegram bot tokens, Backblaze/S3 keys, SMTP.

Environment: the compose `.env` on the VPS holds only bootstrap secrets (`APP_KEY`, database and Redis passwords, S3 keys); it is `600`, owned by root, never committed. A secrets manager (Infisical or Vault) is optional later; the encrypted-table pattern is sufficient for one operator today.

## Authentication and authorisation

Humans: session auth, mandatory 2FA (TOTP) for every account, short sessions, device list. Agents: an `agents` identity with an enabling human; effective permission = intersection. API consumers: Sanctum tokens with abilities scoped to business and tool group, rotated on a schedule. RBAC via spatie, roles mirrored from the ERP where names overlap.

## Network

Traefik is the only exposed service (80/443) plus SSH 22 (key-only; move to a non-root deploy user with sudo and set `PermitRootLogin prohibit-password` at minimum). HQ containers on a private bridge; Postgres and Redis unpublished. Webhook endpoints verify provider signatures (Meta `X-Hub-Signature-256`, shop HMAC, Woo signature) and reject replays via `events.dedupe_key`. Rate limits on every public endpoint.

## Prompt-injection and tool abuse

Customer text is data, never instructions: it is placed in a delimited user turn, tool outputs are typed, and no tool accepts free-text SQL, URLs to fetch, or shell. The permission map decides what the model can even see (the ERP's "the model is never told a table exists" rule). Outbound `send_message` bodies pass a filter for secrets and other customers' data.

## Audit

Doc 28. Additionally: admin actions on credentials, policies, agents and skills produce a Telegram alert in real time.

## Known risks to fix early (from the audit)

1. Plaintext CarryBee credentials in OneDrive: move into shop admin settings, delete the file, ask for reissue.
2. Anthropic key named `temp`, no expiry, on the boss's org: create HQ's own keys per workspace with spend limits; document ownership.
3. No swap on the VPS: add 2-4 GB swap file before adding HQ services.
4. ERP offsite backup defined, not running: deploy the `offsite` service (needs `RESTIC_PASSWORD` stored off-server first).
5. Meta portfolio ownership split (Bor De Guna ad account inside Joy's personal portfolio): consolidate before scaling spend.
6. Messenger app in Dev Mode: App Review with privacy policy pages per brand.
7. Shared SSLCommerz merchant and Pathao account across two brands: attribution by prefix is the only safeguard; document it in finance.
