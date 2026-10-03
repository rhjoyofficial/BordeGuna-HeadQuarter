# 88 Q - Stream Q record: the Phase 3 channel adapters, all inert (22 and 26 September 2026)

Branch `p3/channel-adapters`, worktree `hq-wt/f`, test database `hq_test_f`. Built on `origin/main` at
`37a2be6` under `docs/88` A point 2, with points 1, 8, 20 and 21 as the frame. The 22 September session
ended with the work uncommitted; it was read whole, judged, verified and recorded on 26 September.

## What was built

Point 2 lists: a Messenger adapter, an Instagram send adapter, WhatsApp Cloud API inbound, a WhatsApp reply
send adapter, `messaging.send_whatsapp_reply`, 24-hour window enforcement, template requirements where
applicable, consent enforcement, delivery receipts, the n8n copy-forward for P3-G3, and the staging stack
definition. Each is below, with the fact that keeps it inert.

**1. WhatsApp Cloud API inbound (`WhatsAppCloudNormaliser`).** A fourth pure normaliser beside the three
Meta ones (docs/78 B.1): no token, no HTTP, no database. It reduces Meta's documented `whatsapp_business_account`
document to `InboundMessage`s: text; image, audio, video, document and sticker with a caption and a media
reference (`wa-media:<id>`, never the short-lived download URL); a voice note marked as one; button and list
replies (the tapped title is what the customer said); template quick replies; a location. A reaction and a
system notice yield nothing; an unknown type still yields a message with no body, so a person sees that
something arrived. The platform account is the business phone number the message was sent to
(`metadata.phone_number_id`, platform `whatsapp_number`); the thread is that number and the customer's
WhatsApp id. `statuses()` reads Meta's `sent`, `delivered`, `read`, `played` and `failed` signals, with the
error code on a failure. The shared Meta webhook accepts the WhatsApp envelope (still 404 while
`channels.meta.inbound` is off) and resolves the business from the number, not from the WhatsApp Business
Account id, which HQ does not hold. The drain hands the same event to all four normalisers and then to the
delivery ledger.

**2. Delivery receipts (ADR-031): `DeliveryStatus`, `DeliveryWatermark`, `DeliveryLedger`, `ChannelSender`.**
`message_deliveries` (created empty on 13 September) now has its two writers. `ChannelSender` is the
idempotent seam between a reply tool and a provider adapter: Meta takes no idempotency key, so under a row
lock it writes the attempt as `sending` and commits before any request; a settled attempt (sending,
uncertain, accepted, or confirmed later) is never repeated, only a `failed` one allows another; an adapter
that throws leaves the attempt `uncertain`; and only a message HQ has recorded as outbound can be sent.
`DeliveryLedger` writes the provider's later word: a signal moves a delivery only forward, `verified_at` is
set the first time the provider says `delivered` or later (never on the send's 200), a late `failed` cannot
unverify, a signal for a provider id HQ never sent is ignored (the webhook is shared with the n8n bot and
people in Business Suite), and verification and failure are audit rows. Messenger's watermark ("everything
before this instant was delivered") promotes every delivery HQ sent on that Page-and-person thread at or
before it. `DeliveryReceipt` gained `uncertain`, a category and a retryable flag.

**3. The Meta send adapters (`MetaGraphClient`, `MetaSendAdapter`, `MetaMessengerChannel`,
`MetaInstagramChannel`, `WhatsAppCloudChannel`, `MetaSendError`, `OutboundTemplate`).** One Graph client:
before a byte leaves, HQ's own send rate per account (`config('hq.messaging.meta.send_per_minute')`,
refused locally), then the account's token from the vault's OPEN scope only (a connector never looks a
credential up; with no scope open the send is refused), then `appsecret_proof` when the app secret is
stored; the token travels in the `Authorization` header, never in the URL. Meta's answer becomes a receipt:
accepted with the message id; failed and classified by code and subcode (`MetaSendError`, never by message
text; unknown codes are `unknown`, not retryable); UNCERTAIN when HQ cannot know (a timeout after
connecting, a 504, a 2xx naming no message), which ADR-031 never retries automatically. Each adapter sends
exactly what it is handed and refuses whole a message it cannot carry (empty, over the channel's limit, a
template on a channel without templates, a message for another channel): never shortened, rewritten or
translated. Messenger: `POST /{page}/messages`, `messaging_type` RESPONSE only (a message outside the window
needs a tag HQ does not use; Meta refuses it, 10/2018278, `outside_window`). Instagram: the linked Page's
token, path `me/messages`, 1,000 bytes of UTF-8 (334 Bangla letters). WhatsApp: text or template in the
Cloud API shape, `messages[0].id`; its ceiling adds `send_template`.

**4. `messaging.send_whatsapp_reply` (`SendWhatsAppReplyTool`).** The WhatsApp reply as its own action
(ADR-026), not the Messenger tool with another transport: its own consent channel, its own capability, its
own window rule. Free text only inside the customer service window, 24 hours from the customer's LAST
message on the thread, read from the thread, never from an argument. Outside it only a template from HQ's
allow-list (`config('hq.messaging.whatsapp.templates')`, EMPTY: a template is approved by Meta first and
listed by a deploy, never by a setting), in the language it was listed in, with exactly its number of
values, and never a `marketing` one (a customer's own message grants Service consent, which marketing is
not). The gateway checks Service consent on the thread's person on the WhatsApp channel before the tool
runs; because the consent a message grants lasts only the window, a template outside it also needs a
standing opt-in, which is Meta's opt-in rule for messages the business starts. Descriptor: R3, customer-facing,
consent-gated, one per business, `hq.inbox.manage`, and `agentExecutable` FALSE.

**5. The n8n copy-forward for P3-G3 (`MetaForwardController`, `POST /api/webhooks/meta/forwarded`).** During
the channel shadow Meta's webhook keeps pointing at n8n, and n8n forwards a copy of each body with one HTTP
node, so no Meta setting changes (docs/72 D). HQ records the copy exactly as Meta's own delivery: the same
`events` row, the same dedupe key (the body digest), the same drain; the test proves Meta then delivering
the same body is the same event, not a second one. Authentication is HQ's own, because n8n is not Meta:
`X-HQ-Forward-Signature` over `<timestamp>.<body>` with the forwarding secret (vault `meta_forward_secret`,
account `n8n`), timestamp within five minutes so a captured copy cannot be replayed; and when the copy also
carries Meta's `X-Hub-Signature-256`, it must verify against the app secret or the copy is refused, because
a body claiming Meta's signature and failing it has been altered. The copy's provenance is kept on the
event. 404 while `channels.meta.forwarded` is off (seeded OFF by migration `2026_09_22_100000`, inserted
only when absent); 503 with no secret stored; every refusal counts towards the signature-failure alert.

**6. The staging stack definition (`deploy/staging/`).** docs/70 J4 and docs/72 F: a public webhook with
real customers must not be first exercised on production. A separate compose PROJECT
(`bordeguna-hq-staging`) with its own database, redis, storage and public volumes, its own domain from
`HQ_STAGING_DOMAIN` (never `hq.bordeguna.com`), its own secrets (the template says why production's
`APP_KEY` may never be copied: it would decrypt production's stored credentials), no ERP URL, no shop token,
no alert bot. Nothing starts it: `deploy.sh`, `rollback.sh` and `verify-only.sh` name `deploy/docker-compose.yml`
only, and a test holds that. Starting it is a person's decision after the server upgrade (docs/82 N3).

**Consent enforcement and provenance.** Not new code: the gateway's consent check on a customer-facing,
consent-declaring descriptor, and `ConversationService::ingest` labelling a webhook message
`Provenance::Customer` (untrusted). Both are held for WhatsApp by tests in this stream.

## What keeps it inert, each held by a test (`ChannelAdaptersInertTest`)

- `PlatformRegistry` ships empty: no Messenger, Instagram or WhatsApp provider is registered, so both reply
  tools fail for everyone, human included, at the capability check.
- No statement anywhere in `app/`, `bootstrap/`, `config/`, `routes/` or `database/` constructs a send
  adapter, the Graph client or the sender, and nothing registers a platform provider (a token-level scan;
  a docblock may name a class to say it is not wired, a statement may not).
- No agent's `allowed_tools` names `messaging.send_whatsapp_reply`, and the descriptor is not agent-executable.
- Both Meta inbound flags are OFF: the webhook and the copy-forward answer 404.
- The WhatsApp template list is empty; no `meta_page_token`, `meta_instagram_token`, `whatsapp_access_token`
  or `meta_forward_secret` credential exists.
- The staging stack is a definition no release script starts.

Two existing guards were widened ON PURPOSE, by name, the way `commerce.take_order` was in docs/83 C.3:
`SharedFoundationSafetyTest` now names `messaging.send_whatsapp_reply` as the third customer-facing tool
(consent-declaring, outbound channel, `hq.inbox.manage`), and `ToolNamespaceTest` lists its key. The
`AiSafetyPostureTest` line that allows exactly one agent-executable customer-facing tool is unchanged: the
WhatsApp tool is not agent-executable.

## Not built here, on purpose

- No registration of any adapter in `PlatformRegistry`, no production credential, no live call: these are
  gate decisions (P3-G4, docs/88 A points 1 and 8), recorded for Gate 13, never a side effect of a build.
- The reply tools still stop at "no outbound adapter is wired" even when a provider is registered: the
  single place P3-G4 puts the adapter call, the outbound record and the delivery row, on both tools alike.
- No Facebook comment reply adapter (a comment reply is a public post, not a message; docs/72 B).
- No template authoring or submission to Meta; no marketing send tool (a different purpose, a different
  consent).
- No reconciliation job that settles `uncertain` or `sending` attempts against Meta: ADR-031 says a person
  or reconciliation settles them, and the reconciliation needs a provider registered to ask.
- No n8n workflow change: the HTTP node that forwards the copy is configured when P3-G3 opens, with the
  secret stored on `/credentials` first.

## Things read from Meta's pages on 22 September, and things not re-read

Read on the page: the Messenger Send API request and answer shape; the Instagram send page (host, path,
token, the 1,000-byte limit); the WhatsApp send page (text, template, `messages[0].id`, the window rule);
the WhatsApp webhook and error-code pages (0, 3, 4, 10, 100, 190, 200 to 299, 80007, 130429, 131000, 131008,
131009, 131016, 131026, 131047, 131049, 131050, 131051, 131056, 132000 to 132016); the Messenger delivery
and read webhook fields; "Securing Graph API requests" (`appsecret_proof`).

Not re-read, taken from search results or common documentation, marked in the code: Messenger codes 551,
613 and 10/2018278; Messenger's 2,000-character text limit; WhatsApp's 4,096-character text limit; and
that `me/messages` with a Page token resolves to that Page for Instagram (Graph's general rule, to be proven
on a test account at P3-G4). None of them changes what is inert.

## Verification (local; GitHub Actions validation deferred until the 1 October reset)

- New tests, 60 in five files: `ChannelAdaptersInertTest` (6), `DeliveryLedgerAndSenderTest` (8),
  `MetaSendAdaptersTest` (31, with `Http::preventStrayRequests()` in every test and one test proving an
  unfaked request is stopped), `SendWhatsAppReplyToolTest` (6, every call through the real gateway),
  `WhatsAppInboundAndForwardTest` (9). Four fixtures under `tests/fixtures/meta/`.
- Mutations, each applied, the guarding test run, the code restored, 8 of 8 caught: the forward
  timestamp skew ignored; an over-limit reply sent instead of refused; the sender repeating a settled
  attempt; the ledger moving a delivery backwards; the 24-hour window not enforced; the WhatsApp tool made
  agent-executable; the token put in the URL; the copy's Meta signature left unchecked.
- Full Pest suite on `hq_test_f`: **1335 passed, 11,554 assertions, 0 failed**, 410 s (1275 on `main` at the
  branch base plus the 60 new). `vendor/bin/pint --test`: passed. `npx tsc --noEmit`: clean. `npx vite build`: built.
- One existing test changed: `MetaInstagramAndCommentTest` used a `whatsapp_business_account` envelope as its
  malformed example; that envelope is accepted now, so the example is another object.

## The eight columns (docs/88 A point 21)

| | implemented | merged | registered | credentialed | live-called | agent-held | agent-granted | activated |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Stream Q: Phase 3 channel adapters, WhatsApp inbound and reply, delivery receipts, the copy-forward, staging | yes | not yet (branch pushed for review) | no | no | no | no | no | no |

What an agent could observe or use changed in one way, recorded for Gate 13: a new tool key exists in the
registry, `messaging.send_whatsapp_reply`, not agent-executable and held by no agent, so it appears in no
agent's tool list and no prompt. No prompt, policy, runtime path or agent row changed.

## Open questions

1. Instagram's `me/messages` path with a Page token: prove it on a test account before P3-G4, or hold the
   Page id beside the Instagram account and use `/{page}/messages`.
2. Should `uncertain` and crash-left `sending` attempts get a reconciliation job that asks Meta (a read of
   the message by id) once a provider is registered, or stay a person's decision from the inbox?
   **Answered 3 October 2026 (docs/95 §5 "Delivery", stream W2b):** both. `hq:deliveries:reconcile` runs
   daily at 01:30 Dhaka, asks each registered provider by message id (`OutboundChannelAdapter::readDelivery`;
   Messenger and Instagram `GET /{message-id}?fields=id,created_time`; WhatsApp cannot be read back and
   answers unknown), settles the ledger forward-only and never resends. An attempt with no provider id, or
   one the provider cannot speak for, stays a person's decision. While `PlatformRegistry` is empty the pass
   writes one `deliveries.reconcile_skipped` audit row with the counts and changes nothing.
3. The local send ceiling of 60 a minute per account is a guess below Meta's published rates; set it from
   the WhatsApp number's messaging tier when one exists.
