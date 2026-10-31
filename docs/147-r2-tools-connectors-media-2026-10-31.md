# 147-r2. Applied ruling 31 Oct 2026: tool registration, connectors, voice, media, ingestion and the media budget

Builder R2 of the HQ completion programme. Branch `ruling/r2-tools-connectors-media` from `hq-complete/release-candidate` (b721c9a,
record docs/146). Applies the ChatGPT final ruling of 31 October 2026 (`H:\WORK\BorDeGuna\hq-complete-2026-10-31\CHATGPT-RULING-2026-10-31.txt`),
items 7 to 12 and the matching tests of sections B and C. Nothing here touches production, releases anything or switches anything on.

## 1. Item 7: always-registered descriptors, flag-gated availability

- Every flag-gated tool is now ALWAYS in the ToolRegistry, so its descriptor is in the canonical catalogue: M3's ten connector tools
  (`hq.flagged_tools`, docs/144), M2's `briefing.send_owner_telegram` (moved from `hq.tools` into `hq.flagged_tools` under
  `console.telegram_brief`) and M4's `avatar.generate` (was registered nowhere; now under `avatar.generation`).
- `App\Tools\FlaggedTools` maps a tool key to its flag; `ToolAvailability::check()` reads the flag LIVE on every call. With the flag off
  the answer carries `feature_disabled`, and the gateway refuses with the new typed outcome `OutcomeClass::FeatureDisabled`
  (`feature_disabled`, control-plane side), never "unknown tool". The gateway checks the flag again after scope resolution, for the
  resolved business (per-business rollout).
- `AgentToolset` already filters by availability, so a disabled tool is never offered to an agent, even one that holds it.
- Stale workers: the flag is read from `feature_flags` on every call (`Features` has no cache, docs/81 C.6), so a long-lived queue worker
  refuses the next call after a flag goes off. No cached flag exists, so no versioned invalidation is needed; a test pins that none is added.
  The approval guard reads the same availability, so an approval parked while a flag was on does not execute after it goes off.
- A flag ON is one more AND: permission, policy, consent, credentials and approval still decide; no agent gains a tool, no grant appears.
- `ConnectorTool`'s own flag refusal now says `feature_disabled` too. Unreadable flags fail closed.

Pins updated deliberately (each with a comment saying why): the R4 list in `AiSafetyPostureTest`, `ApprovalCenterTest`, `PolicyEngineTest`,
`ToolGatewayTest` (adds `media.draft_reel`, `erp.create_draft_bill`, `ads.create_draft`, `briefing.send_owner_telegram`, `avatar.generate`,
each also held to flag-gated, flag off and not agent-executable); `PolicyEngineTest` permanently-human-only list (adds `avatar.generate`);
`GatewayCoverageStructureTest` effectful list (adds the ten effectful flag-gated keys); `ToolNamespaceTest` key list; `ConnectorPostureTest`,
`CourierFlowTest`, `AdsDraftTest`, `AdsInertPostureTest` (`feature_disabled` instead of "Unknown tool"); `AvatarConsentTest` and
`OwnerTelegramBriefTest` (registered, refused while off, parked while on). New: `tests/Feature/Connectors/FlagGatedToolsTest.php`.

Left as they were, on purpose: the Phase 5 ads READ tools and the Phase 6 research/content/publishing tools stay declared and registered
nowhere (`PhaseSixTools`, gate P5-G1 and the Phase 6 gates). They are not flag-gated tools; registering them is a gate's step.

## 2. Item 8: voice

- Text to speech: Azure `bn-BD` is the preferred default (priority 10; voices Nabanita, default, and Pradeep; Bangla through Azure is
  refused in any other voice), ElevenLabs the alternative (priority 20). The OpenAI-compatible TTS adapter stays in the code, registered
  nowhere.
- Speech to text: NO production default. `App\Voice\SttBenchmarkGate` refuses every transcription (the push-to-talk tool and customer
  voice-note ingestion) until `hq.voice.stt.benchmark_passed_provider` names the bake-off winner, and then only that provider. It ships null.
- The bake-off plan is `docs/147-r2-voice-bakeoff-plan.md` (50 notes: Google Chirp 3 bn-BD, ElevenLabs Scribe, OpenAI, Gemini).
- Voice stays inert: flag `connectors.voice` off, no credential, no `SpeechProviders` registration.

## 3. Item 9: agents cannot read raw attachments

Already true by construction (docs/144-media-in-chat); now held by `tests/Feature/Media/AgentAttachmentDenialTest.php`: every registered
tool that opens an upload is human-only and held by no agent; an agent naming one is refused at the gateway and the upload is untouched;
the agent runtime, model layer and context builders contain no upload reader; a person in scope can inspect.

## 4. Item 10: invoice extraction safeguards

- Default stays the deterministic `RuleBasedBillExtractor`. `ExtractorGate` admits any other extractor only when the flag
  `ingestion.model_extraction` is on (no row: off) AND `hq.ingestion.model_extraction.eval_passed_extractor` names it (null ships).
- PDF pages are separated by a form feed; every verified citation carries `page`, `start`, `end` and the exact text, re-verifiable.
- `BillChecks` (deterministic, whole minor units): currency in BDT/USD, total positive, each line equals quantity times unit price,
  lines plus tax equal the total. Run at extraction, at review and again before the ERP draft. A failed check blocks both.
- Mandatory field-by-field review: marking a document reviewed needs an explicit confirmation of supplier, invoice number, invoice date,
  lines, amounts, tax, currency and payment details (`ingested_documents.review_confirmations`); the ERP draft tool checks them again.
- Extraction can never post or pay: reading mail reaches no ERP; the writer has only `createDraftBill`; the payload is a draft with no
  payment field; no ingestion code names the writer. The ERP's own approval stays authoritative.
- Decision: a bill with no readable item lines cannot pass the checks, so it cannot be drafted to the ERP (the most conservative reading).

## 5. Item 11: Meta send connectors wait for P3-G4

No ConnectorRegistry row for any messaging capability, no messaging provider in `PlatformRegistry`, no Meta credential seeded, no sandbox
row, and no new agent-callable send tool. The one agent-executable customer-facing tool is the pre-existing proposal-only
`messaging.send_reply` (docs/72), which cannot execute with no provider. Held by `tests/Feature/Connectors/ProductionIsolationTest.php`.

## 6. Item 12: the media budget

- Tables `media_budgets` (one row per business, `monthly_limit_bdt`; no row = BDT 0) and `media_budget_ledger` (estimate in provider currency
  and BDT, the rate used, actual in provider currency and BDT, state reserved/committed/released/reconciled), migration
  `2026_10_31_720000_create_media_budget_tables` (also adds `ingested_documents.review_confirmations`). Seeds nothing.
- `App\Media\Budget\MediaBudgets`: Asia/Dhaka month; `reserve()` locks the business's budget row FOR UPDATE, sums the month and refuses
  over the limit, in one transaction (proven with eight concurrent PHP processes: exactly 3 of 8 reservations of BDT 30 fit BDT 100);
  idempotent per reference; `commit`, `release`, `reconcile` (estimate kept beside actual; a reconciled line counts at its actual).
- Conversion: configured rate (`hq.media.budget.fx_to_bdt`, USD 130.00) plus a 5% margin, rounded UP to the paisa. Unknown price, unknown
  currency or empty rate: refused. Real provider prices ship null (unknown), so every real paid provider is refused until Joy enters its
  price in a reviewed release.
- Hooks, each reserving before the provider is called and releasing when it definitely did not run: reel/video (`media.draft_reel`), voice
  generation (`voice.synthesize`) and `avatar.generate` (R2 changed only the tool's registration and this hook; R3 owns avatar consent).
- Decision: `voice.synthesize` is now business-scoped (`business_slug` required), because a budget is per business and an HQ-wide read-aloud
  has no budget to pay from. The voice screen asks which business pays.
- Owner-controlled: setting a limit and reconciling a billed cost need permission `hq.media.budget.manage` AND the owner role AND the
  business in scope; audited (`media.budget.set`, `media.budget.reconciled`). No tool, job or agent reaches either (structural test).
- Screen: a Bangla "Media spending limit" section on the existing Connectors screen (limit, this month's use, ledger with estimate beside
  billed, owner forms).

## 7. Section C: production defaults

`ProductionIsolationTest`: after every migration and `DatabaseSeeder`, no credential, no flag on, no autonomy grant, no media budget, no
scheduled job, AI runtime off, no Meta send connector, no speech provider and no STT default; migrations and the production seeders contain no
HTTP client, socket or connector call (comments excluded), and nothing is sent while they run.

## 8. Open for ChatGPT

1. `voice.synthesize` business-scoped so a budget can pay for it (default chosen). Alternative: an HQ-wide media budget.
2. A bill with no readable item lines cannot reach the ERP (default chosen). Alternative: allow a total-only draft after review.
3. The FX margin (5%) and the static USD rate; a live rate source would be a separate connector.
