# 144. The HQ-completion connectors: overview, shared design and what Joy must provide (31 October 2026)

Agent M3 of the HQ completion programme (`H:\WORK\BorDeGuna\hq-complete-2026-10-31\BRIEF.md`). Branch `feat/complete-connectors`
from `integration/ui-and-scope`, merged with `hq-complete/base` (b4b644c). Nothing here touches production, the ERP repository or any
real account. The per-capability decision records follow this file:

| Record | Brief item |
|---|---|
| `144-courier-connectors.md` | 1. Courier tracking (Q6) |
| `144-voice-provider.md` | 2. Voice (Q2) |
| `144-media-in-chat.md`, `144-media-pipeline.md` | 3. Media in chat (Q3) |
| `144-email-ingestion.md` | 4. Email and PDF bill ingestion (Q4) |
| `144-erp-write-contract.md` | 5. ERP draft purchase bill (Q5), and the exact endpoint the ERP must offer |
| `144-meta-readiness.md`, `144-meta-ads-drafts.md` | 6. Meta readiness, Phases 3 and 5 |

## 1. The shape every connector has

1. **An interface** under the domain (`App\Courier\CourierConnector`, `App\Ingestion\Mailbox\MailboxConnector`, `App\Media\Pipeline\MediaPipeline`,
   `App\Erp\Write\ErpDraftBillWriter`, `App\Ads\Draft\AdsDraftPlatform`; voice uses the existing `SpeechToText` and `TextToSpeech`).
2. **A fake** that reaches nothing and needs no credential, and **real drivers** that read their secret only from the open vault scope.
3. **Contract tests** that every driver, the fake included, passes (a dataset over all implementations). A real driver is proven against HQ's
   own behaviour with faked HTTP in the shape it was written from; it is proven against the provider on the first call with real keys (each
   record lists what is unproven).
4. **A credential kind** in `CredentialKinds`, so Joy pastes the key on the Credentials screen.
5. **A feature flag, seeded OFF** (`connectors.*`, migration `2026_10_31_100100_seed_connector_flags_off`).
6. **Registry rows** in `config/hq-connectors.php` (`enabled_by` is that flag; every row also needs its credential). The fake rows are
   registered only under `HQ_CONNECTORS_SANDBOX=true`, which nothing sets (a test holds that none ships).
7. **Gateway tools** with a permission, an audit action, a business scope and a risk tier. R4 ones wait for approval.
8. **A screen** with an honest "not connected yet, needs X" state, in Bangla and English, light and dark, phone width.

## 2. Decisions that apply to all of them (defaults chosen; ChatGPT may overrule)

- **Flag-gated tool registration (`hq.flagged_tools`, `App\Tools\FlaggedTools`).** The connector tools are NOT in `config('hq.tools')`. A tool
  is in the registry only while its flag is ON, so with the shipped flags OFF the gateway answers "Unknown tool" for everyone, which keeps
  every standing pin true (exactly one R4 tool registered, the effectful tools pinned, tool keys unchanged). Switching a flag on adds the
  tools on the next web request (queue workers on their next restart); unreadable flags add nothing (fail closed). Each tool also checks its own
  flag, so a direct call is refused too. `ConnectorPostureTest` holds all of this.
- **One capability per courier** (`courier.pathao.track`, ...): the courier carrying a parcel is a fact about the parcel, not a vendor chosen by a
  caller, exactly as `messaging.whatsapp.send` and `messaging.messenger.send` are separate. `ConnectorRequest::preferProvider` is untouched
  and nothing sets it. The reserved name `courier.track` is retired. The tool argument is called `courier`, not `provider`, which
  `ConnectorPreferenceGuardTest` would rightly refuse.
- **No agent holds any of these tools**, none is agent-executable, and no scheduled job is filed for any of them (a person files one).
- **Business scope fails closed** on every table and screen: each row has a `business_id`; a restricted person gets 404 for another business's
  id; a row with no business is its creator's alone. Tested per connector.
- **Pause All AI** (docs/141): `AiPause::assertRunning()` is called by the two voice tools and the reel draft tool, the only connector tools
  that call an AI model or a generation provider. The rule-based bill extractor is not AI and is not paused; a model-assisted extractor, when
  one is added, must call it. Pausing writes nothing here and grants nothing.
- **Money is BDT** and decimal strings or whole minor units; the one USD figure is Joy's USD 5 a day campaign cap, converted at a configured rate
  (`hq.ads.draft.usd_bdt_rate`, default 130.00; an empty rate refuses BDT drafts).
- **Public repository**: no key, token, phone number or customer data in code, tests or docs (a scan test holds it for the new code).

## 3. What Joy must provide per connector (credentials and permissions)

Store each on **Credentials** (kind, account reference), then switch the flag on **Feature flags**. `Connectors` shows what is still missing.

| Connector | Credential kind (account reference) | What the value is | Other things only Joy can do |
|---|---|---|---|
| Courier: Pathao | `pathao_courier_secret` (business slug) | JSON `{"client_id","client_secret","username","password"}` of the merchant | Set the webhook URL in Pathao's panel; store `courier_webhook_secret` as `<business>.pathao`; read the pairing reply value from Pathao's page |
| Courier: CarryBee | `carrybee_courier_secret` (business slug) | JSON `{"client_id","client_secret","client_context"}` | Give the API base URL (set `hq.courier.carrybee.base_url`); first sandbox call proves the shapes |
| Courier: Steadfast | `steadfast_courier_secret` (business slug) | JSON `{"api_key","secret_key"}` | Webhook secret `<business>.steadfast` as the Bearer value Steadfast sends |
| Courier: RedX | `redx_courier_token` (business slug) | the API token | Webhook secret `<business>.redx`; the webhook URL carries `?token=<that secret>` |
| Voice | `speech_openai_compatible_key` (`hq`) | key of an OpenAI-compatible speech service (set `hq.voice.openai_compatible.base_url` for a non-OpenAI one) | The 50-note Bangla bake-off (docs/97 2.3) before choosing a provider for real use; a microphone-allowing browser. Alternatives already built: `google_speech_access_token`, `azure_speech_key`, `elevenlabs_api_key` |
| Photos, videos, files in chat | none | none | Optional: ClamAV (`hq.media.scanner=clamd`) |
| Reel / video drafts | `gemini_video_key` (`hq`) | Gemini API key with Veo access (Google billing, docs/97 2.2) | Rights record for any asset before publication (the content gate) |
| Supplier mailbox | `gmail_mailbox_token` or `graph_mailbox_token` (business slug) | a read-only OAuth access token (Gmail `gmail.readonly`, Graph `Mail.Read`) | A refresh flow does not exist yet: the token is refreshed by Joy until OAuth is built; for Graph with an application token set `hq.ingestion.graph.user` |
| ERP draft bill | `erp_write_token` (business slug) | an ERP token with a draft-bill write ability, different from the read token | The ERP must offer the endpoint in `144-erp-write-contract.md` (the ERP session builds it); a second person approves each request |
| Meta ads drafts | `meta_ads_draft_token` (business slug) | a Meta system-user token with `ads_management` for that business's ad account | Link the ad account to the business (`platform_accounts`); Page id for each draft; switch each draft on in Ads Manager himself |
| Meta token health | `meta_app_secret` (`meta`, already a kind) plus env `HQ_META_APP_ID` | the app secret; the app id is not a secret | Store each Page / Instagram / WhatsApp / ads token (existing kinds) so they can be checked |
| Meta messaging (Phase 3) | existing kinds: `meta_page_token`, `meta_instagram_token`, `whatsapp_access_token`, `meta_app_secret`, `meta_webhook_verify_token` | as before | Business Verification; the gates P3-G3 and P3-G4 (docs/137) |

## 4. Tests and checks

Counts are in `STATE.md` and the final report. New test files: `tests/Feature/Courier/{CourierContractTest,CourierFlowTest}`,
`Voice/VoiceConnectorTest`, `Media/MediaConnectorTest`, `Ingestion/IngestionTest`, `Ads/AdsDraftTest`, `Connectors/ConnectorPostureTest`.
Existing tests changed, each for a stated reason: `ConnectorRegistryTest` and `ConnectorStructureTest` (the registry is no longer empty; every row
is OFF and no sandbox row ships), `PhoneAndVoiceTest` (the microphone is allowed in exactly one place, inside a click handler; the voice screen is
no longer inert text), `PostureScreenTest` (the new POST routes, each a person's click through the gateway), `AppShellTest` (five nav entries).

## Applied ruling 31 Oct

The ChatGPT final ruling of 31 October 2026 changed four things here (full record: `147-r2-tools-connectors-media-2026-10-31.md`).

- **Tool registration (item 7).** The "registered only while the flag is ON" design in section 2 is replaced: every flag-gated tool is
  ALWAYS registered (its descriptor is in the catalogue), and its AVAILABILITY is its flag, read live on every call. A call while the flag
  is off is refused with the typed outcome `feature_disabled`, not "unknown tool"; agents are never offered a disabled tool; a long-lived
  queue worker reads the flag afresh each call. A flag ON grants no permission, agent tool, autonomy or approval. The owner Telegram brief
  (docs/143) and `avatar.generate` (docs/145-b) are flag-gated the same way.
- **Voice (item 8).** Text to speech: Azure `bn-BD` (Nabanita, Pradeep) preferred, ElevenLabs the alternative; the OpenAI-compatible TTS row
  is removed. Speech to text: no production default; every transcription is refused until a provider passes the 50-note Bangla bake-off
  (`147-r2-voice-bakeoff-plan.md`). The voice row of the table in section 3 is superseded accordingly.
- **Invoice extraction (item 10).** Deterministic by default; any OCR or model extractor needs its own flag AND a passed evaluation;
  citations carry page and offsets; arithmetic, totals, tax and currency are checked deterministically; a person confirms every field
  before a document is reviewed; nothing in extraction can post or pay.
- **Media budget (item 12).** Reel/video drafts, voice generation and avatar generation reserve their estimated cost against the business's
  monthly media budget (BDT, owner-set on the Connectors screen, default BDT 0 = none) before any provider is called. Real provider prices
  ship unknown, so a paid provider is refused until its price is entered. `voice.synthesize` now names the business that pays.
- Unchanged and confirmed: agents cannot read raw attachments (item 9); no Meta send connector, credential or agent send tool is seeded
  before P3-G4 (item 11).
