# 146. HQ completion: release candidate record, merge part (31 October 2026)

Agent M5 (integrator). Branch `hq-complete/release-candidate` from `origin/hq-complete/base` (`b4b644c`), worktree `hq-wt/m5`, test database `hq_test_m5`, scratch database `hq_scratch_m5`. Nothing here touches production. GitHub CI was not triggered and nothing was deployed.

**Scope note.** ChatGPT ruled on 31 Oct (`hq-complete-2026-10-31/CHATGPT-RULING-2026-10-31.txt`) while this merge was running. The ruling changes M2, M3 and M4 code (one-click approve removed, batch approval sequence-only, connector tools always registered and flag-gated with a typed `feature_disabled`, media budget, testimonial exception, skill self-approval ban, one-way autonomy lapse and more). On the coordinator's instruction this record covers only the merges, the gates and the migration proof. The ruling is **not applied** on this branch, and the **browser walk is deferred** until it is, so every screen is walked once.

## 1. Merges (all `--no-ff`, full Pest after each)

| Order | Branch | Head | Merge commit | Full Pest after the merge |
|---|---|---|---|---|
| a | `feat/complete-owner-console` (M2, docs/143) | `16d521c` | `40dab8b` | 2,328 passed, 0 failed |
| b | `feat/complete-phases-6-10` (M4, docs/145*) | `d12025f` | `2adc71a` | 2,364 passed, 0 failed |
| c | `feat/complete-connectors` (M3, docs/144*) | `babec9d` | `11858ec` | 2,548 passed, 1 failed (see below), then 2,549 passed |

The one failure after merge c was environmental: M3 adds `smalot/pdfparser` to `composer.lock`, and this worktree's `composer install` had run before that merge. After `composer install`, `IngestionTest` passed (25/25) and the full suite was rerun green (section 3).

## 2. Conflicts and how each was resolved

| File | Merge | Resolution |
|---|---|---|
| `resources/js/components/app-sidebar.tsx` | b, c | Icon imports only: union, sorted (`Layers`, `ListChecks`, `Megaphone`, `Package`, `Plug`, `Puzzle`, `Radar`, `ScanFace`, ...). The nav items themselves merged cleanly. |
| `resources/js/lib/i18n/bn.json` | b, c | Both tail blocks kept. Checked for duplicate keys with a JSON pairs parse: one key, `"Approve this draft"`, came from both M2/M4 (`এই খসড়া অনুমোদন করুন`) and M3 (`এই ড্রাফট অনুমোদন করুন`); kept once with the base wording "খসড়া" (used 28 times in the dictionary against 18 for "ড্রাফট"). Final: 2,118 unique entries. `BanglaDictionaryTest` passes. |
| `tests/Feature/Foundation/AppShellTest.php` | b, c | Nav count recounted from the merged sidebar: 26 before + 3 (docs/143: Do plans, Batch review, Event watches) + 5 (docs/145: Content, Research digests, Avatar consent, Skills, Autonomy ladder) + 5 (docs/144: Parcels, Supplier documents, Reel drafts, Paused ad drafts, Connectors) = **39**, with 39 unique URLs. The test passes on that number. |
| `database/seeders/RolesAndPermissionsSeeder.php` | c | Permission list: union of the docs/145 set and the docs/144 set. M4's `brand_manager` additions (`hq.content.view`, `hq.content.plan`, `hq.research.view`) merged cleanly. Seeded result: 81 permissions. |
| `tests/Feature/Security/BusinessScopeAuditStructureTest.php` | c | Controller list: union of `Completion/*` (M4) and `Connectors/*` (M3). |

Auto-merged without conflict and checked by the suite: `routes/web.php` (both `require` lines), `PostureScreenTest` write-route allow-list, `GatewayCoverageStructureTest` + `deploy/tools/structural-searches.php`, `GlobalSwitchScopeTest`, `BanglaDictionaryTest`. A scan for duplicate global Pest helpers (`^function ` across `tests/`) after each merge found none.

**Tool registration (M2/M4 against M3's `hq.flagged_tools`).** Left as each branch built it: M3's connector tools are absent while their flag is off; M2's `briefing.send_owner_telegram` is registered in `hq.tools` and refuses inside `execute()` while `console.telegram_brief` is off (R4, approval, not agent-executable); M4's `avatar.generate` is registered nowhere. I had prepared moving the briefing tool into `hq.flagged_tools` for consistency, but the 31 Oct ruling goes the other way (always registered, flag-gated availability, typed `feature_disabled`), so that change was dropped before commit. Making all three follow the ruling is part of the ruling work. No test pin was changed for this.

## 3. Gates on the merged tree (HEAD of this branch)

| Gate | Result |
|---|---|
| Full Pest, PostgreSQL 17, parallel 4 | **2,549 passed, 0 failed** (50,458 assertions, 407 s) |
| Pint `--test` | pass |
| `tsc --noEmit` | clean |
| ESLint on the 28 new or changed `.ts`/`.tsx` files | clean |
| Vite build | OK |

2,549 = base 2,275 + M2 53 + M4 36 + M3 185.

## 4. Migrations for the release, and the ordering proof

New on this branch against `hq-complete/base` (run in this order):

1. `2026_10_31_100000_create_connector_capability_tables` (M3)
2. `2026_10_31_100000_create_owner_console_tables` (M2)
3. `2026_10_31_100100_seed_connector_flags_off` (M3)
4. `2026_10_31_600000_create_phases_six_to_ten_completion_tables` (M4)

M2 and M3 share the timestamp `2026_10_31_100000`; Laravel orders them by file name, and the two touch different tables. Proof on `hq_scratch_m5`: `migrate:fresh` ran all 76 migrations; `migrate:rollback --step=76` rolled back every one with no error; `migrate` re-ran all 76; `migrate:status` shows all 76 ran. (Run with `CACHE_STORE=array`: the permission-table migration flushes the cache and there is no Redis on this laptop; production has Redis.)

## 5. Seeder lines for the release

```
php artisan db:seed --class=RolesAndPermissionsSeeder --force
```

Run twice on the scratch database: idempotent, 81 permissions. No other seeder is needed; every new flag is seeded by migration.

## 6. Feature flags (all seeded OFF, verified on the scratch database: 16 rows, 0 enabled)

- docs/143 (M2): `console.do_mode`, `console.batch_approval`, `console.one_click_approve`, `console.telegram_brief`, `support.reply_options`, `console.event_watches`.
- docs/144 (M3): `connectors.courier`, `connectors.voice`, `connectors.media_upload`, `connectors.media_pipeline`, `connectors.mail_ingestion`, `connectors.erp_draft_bill`, `connectors.ads_draft`, `connectors.meta_health`.
- Already present: `channels.meta.inbound`, `channels.meta.forwarded`.
- docs/145 (M4) switches with **no row at all** (so they read as off): `content.ai_drafting`, `research.engine`, `avatar.generation`, `agents.delegation`.

## 7. Credential kinds (nothing stored; Joy pastes each on the Credentials screen)

| Capability | Kind (account reference) |
|---|---|
| Courier | `pathao_courier_secret`, `carrybee_courier_secret`, `steadfast_courier_secret`, `redx_courier_token` (business slug); `courier_webhook_secret` (`<business>.<courier>`) |
| Voice | `speech_openai_compatible_key` (`hq`); alternatives `google_speech_access_token`, `azure_speech_key`, `elevenlabs_api_key` |
| Reel drafts | `gemini_video_key` (`hq`) |
| Supplier mailbox | `gmail_mailbox_token` or `graph_mailbox_token` (business slug) |
| ERP draft bill | `erp_write_token` (business slug) |
| Meta ads drafts | `meta_ads_draft_token` (business slug) |
| Meta token health | `meta_app_secret` (`meta`) + env `HQ_META_APP_ID` |
| Owner Telegram brief | config `hq.owner_console` driver + the owner's confirmed private chat (docs/143 D) |
| Avatar | HeyGen key (ElevenLabs for voice), plus the signed consent documents (docs/145-b) |

Full table with what each value is: `docs/144-connectors-overview.md` section 3.

## 8. Decisions that were open (now ruled)

The open questions were in docs/143 (items B, C, E and the three conflicts with docs/138 rulings 1, 8, 9), docs/144 section 2 and each docs/144-* record, and docs/145-a to 145-d. ChatGPT answered them on 31 Oct (`CHATGPT-RULING-2026-10-31.txt`). Applying that ruling is the next step and is not on this branch yet.

## 9. Still owed before release

1. Apply the 31 Oct ruling (builders), merge onto this branch, rerun the gates.
2. Browser walk of every new or changed screen (light/dark, 1366/390, Bangla, a restricted single-business user), after step 1.
3. CI via workflow_dispatch, then Joy's release (four migrations above, then the seeder line).
