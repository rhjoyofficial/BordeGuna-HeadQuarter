# 148. HQ second release candidate: record (31 October 2026)

Agent M6, final integrator of the HQ completion programme. Branch `hq-complete/release-candidate`, worktree `hq-wt/m5`, test database `hq_test_m5`, scratch databases `hq_scratch_m5` (migration proof), `hq_upgrade_m6` (upgrade proof from production) and `hq_walk_m6` (browser walk). Nothing here touched production. GitHub CI was not triggered, nothing was deployed and nothing was tagged: the coordinator does those.

Production is `828bf42` (wave 4 `c0db11e` + PHP 8.3.36 fix, released 31 Oct). This candidate descends from it and contains the UI branch, M1 to M4 and the three ruling builders R1 to R3, with the 31 Oct ChatGPT ruling applied (`hq-complete-2026-10-31/CHATGPT-RULING-2026-10-31.txt`).

**Candidate: code at `7311157`; this record is the next commit (its sha is in STATE.md, M6 block).**

## 1. Merges (all `--no-ff`, full Pest after each)

| Order | Branch | Head | Merge commit | Full Pest after the merge |
|---|---|---|---|---|
| 1 | `ruling/r1-approvals-console` (R1, docs/143 "Applied ruling 31 Oct") | `c91b137` | `38bbd0e` | 2,569 passed, 0 failed |
| 2 | `ruling/r3-content-skills-autonomy` (R3, docs/145 sections) | `aaec93e` | `4a9d9ce` | 2,589 passed, 0 failed |
| 3 | `ruling/r2-tools-connectors-media` (R2, docs/147) | `6f55416` | `9274920` | 2,623 passed, 1 failed, then fixed (below): 2,624 passed |
| 4 | `fix/bangla-work-screens` (found in the browser walk, section 6) | `59cc0d9` | `77d3a3b` | in the final run (section 3) |

`fix/php-8.3.36` (`828bf42`) was already an ancestor (`git merge-base --is-ancestor 828bf42 HEAD` true; `c0db11e` too), so no merge was needed. The runtime stage is `FROM php:8.3.36-fpm-alpine` and `deploy/php.ini` has `session.upload_progress.enabled = Off`; `git diff 828bf42 HEAD -- deploy/Dockerfile deploy/php.ini` is empty.

`composer install` ran after every merge (no new package appeared in R1 to R3).

## 2. Conflicts and resolutions

| File | Merge | Resolution |
|---|---|---|
| `resources/js/lib/i18n/bn.json` | R3, R2 | Both tail blocks kept (R1+R3: 2,178 unique keys; +R2: 2,213). A JSON pairs parse found no key twice, so no value had to be chosen. |
| `PostureScreenTest` allow-list | R3, R2 | Auto-merged: R1's two removals (batch POST, quick-approve) stay removed; R3 (+2 testimonial routes) and R2 (+2 media-budget routes) added. |
| `RolesAndPermissionsSeeder`, `web.php`, `routes/completion.php` | R2, R3 | Auto-merged; R2's `hq.media.budget.manage` at the end of the list; seeded result 82 permissions. |
| `AvatarController.php`, `GenerateAvatarTool.php` | R2 | Only R2 touched them. R3's customer-facing refusal lives in `AvatarConsents` (`CUSTOMER_FACING_USES`, refused first in `refusal()` and `record()`), which the tool reaches through `AvatarGenerations`; R2's registration under `avatar.generation` and the media-budget reservation are in the tool. Both kept, both tested. |
| Migration `2026_10_31_100000_create_owner_console_tables` | R1 | R1's in-place edit taken as is (it never ran outside tests). |
| R4 / tool pin lists | R2 | Taken from R2, each already commented with its reason in R2. |

**Test fixed (no assertion weakened).** `tests/Feature/Connectors/FlagGatedToolsTest.php` restored `$before[0]`'s tools into `Agent::firstOrFail()`, which on PostgreSQL may return another row after the bulk update above it; it failed once in the combined suite. Now `orderBy('id')->firstOrFail()`, with a comment saying why (`7af36bd`).

**Lint fixed.** `pages/playbooks/show.tsx` had an unused `t` (pre-existing from the UI branch); removed (`b1b2e9a`).

## 3. Gates on the final tree

| Gate | Result |
|---|---|
| Full Pest, PostgreSQL 17, parallel 4 | **2,624 passed, 0 failed** (57,676 assertions, 665 s) |
| Pint `--test` | pass |
| `tsc --noEmit` | clean |
| ESLint on every `.ts`/`.tsx` added or changed since production `828bf42` (115 files, including the Bangla fix) | clean (115 files) |
| Vite build | OK |

## 4. Migrations: proof on scratch and the upgrade path from production

**Fresh tree (`hq_scratch_m5`):** 78 migration files. `migrate:fresh` ran 78, `migrate:rollback --step=78` rolled back 78 with no error, `migrate` re-ran 78, `migrate:status` shows 0 pending. (`CACHE_STORE=array`: no Redis on this laptop; production has Redis.)

**Upgrade from production (`hq_upgrade_m6`):** a temporary worktree at `828bf42` migrated a fresh database (69 migrations ran) and seeded `RolesAndPermissionsSeeder`. Switched to the candidate: exactly these 9 were pending and all ran, in this order:

1. `2026_10_17_100000_create_ai_pause_state_table`
2. `2026_10_17_100000_scope_conversation_threads_to_business`
3. `2026_10_17_110000_create_identity_conflict_resolutions`
4. `2026_10_31_100000_create_connector_capability_tables`
5. `2026_10_31_100000_create_owner_console_tables`
6. `2026_10_31_100100_seed_connector_flags_off`
7. `2026_10_31_600000_create_phases_six_to_ten_completion_tables`
8. `2026_10_31_700000_apply_ruling_31_oct_content_skills_autonomy`
9. `2026_10_31_720000_create_media_budget_tables`

Then `migrate:rollback` (one batch) rolled back exactly those 9 and `migrate` ran them again. `migrate:status`: 78 ran. The three `2026_10_17_*` files sort before wave 4's later timestamps but were never run in production; Laravel runs every pending file, so the order is safe (each touches its own table). After the upgrade: `RolesAndPermissionsSeeder` twice, idempotent, **82 permissions**; **15 flag rows, 0 enabled**.

## 5. Inventory

**Feature flags with a row (all OFF after the upgrade):** `channels.meta.forwarded`, `channels.meta.inbound`, `connectors.ads_draft`, `connectors.courier`, `connectors.erp_draft_bill`, `connectors.mail_ingestion`, `connectors.media_pipeline`, `connectors.media_upload`, `connectors.meta_health`, `connectors.voice`, `console.batch_approval`, `console.do_mode`, `console.event_watches`, `console.telegram_brief`, `support.reply_options`.

**Switches with no row (read as off):** `ask_hq`, `content.drafting`, `content.ai_drafting`, `research.engine`, `avatar.generation`, `agents.delegation`, `ingestion.model_extraction`, `briefing.narration`. Code-level gates that a flag cannot open: Do mode `DoModeActivation::ACTIVATION_RULING = null`; STT `hq.voice.stt.benchmark_passed_provider = null`; model extraction `hq.ingestion.model_extraction.eval_passed_extractor = null`; real media provider prices `null` (refused).

**Tools (44 registered; key | tier | read/write | agent-executable | flag):**

| Tool | Tier | R/W | Agent | Flag |
|---|---|---|---|---|
| ads.create_draft | R4 | W | no | connectors.ads_draft |
| avatar.generate | R4 | W | no | avatar.generation |
| briefing.compose | R3 | W | no | - |
| briefing.send_owner_telegram | R4 | W | no | console.telegram_brief |
| commerce.cancel_sales_order | R4 | W | no | - |
| commerce.check_fact_integrity | R1 | R | yes | - |
| commerce.get_order_status | R2 | R | yes | - |
| commerce.list_orders | R2 | R | yes | - |
| commerce.quote_order | R2 | R | yes | - |
| commerce.reconcile_facts | R3 | W | no | - |
| commerce.take_order | R4 | W | no | - |
| consent.lift_restriction | R3 | W | no | - |
| conversation.read_thread | R1 | R | yes | - |
| courier.poll_parcels | R3 | W | no | connectors.courier |
| courier.track_parcel | R3 | W | no | connectors.courier |
| customers.identity_conflicts.resolve | R3 | W | no | - |
| customers.quarantine.repair | R3 | W | no | - |
| demo.request_approval | R4 | W | no | - |
| erp.check_order_eligibility | R2 | R | no | - |
| erp.create_draft_bill | R4 | W | no | connectors.erp_draft_bill |
| erp.lookup_product / erp.lookup_sku / erp.read_availability / erp.read_price / erp.run_report | R2 | R | yes | - |
| erp.read_snapshot | R1 | R | yes | - |
| erp.poll_low_stock / erp.poll_overview | R3 | W | no | - |
| facts.query_orders / facts.snapshot_freshness | R1 | R | yes | - |
| hq.list_approvals / hq.list_jobs / hq.recent_events | R1 | R | yes | - |
| mail.poll_inbox | R3 | W | no | connectors.mail_ingestion |
| media.check_draft | R3 | W | no | connectors.media_pipeline |
| media.draft_reel | R4 | W | no | connectors.media_pipeline |
| messaging.send_reply | R3 | W | yes (proposal only; no provider before P3-G4) | - |
| messaging.send_whatsapp_reply | R3 | W | no | - |
| meta.check_token_health | R2 | R | no | connectors.meta_health |
| noop, test.fail | R0 | R | no | - |
| outcomes.attribute | R3 | W | no | - |
| voice.synthesize / voice.transcribe | R3 | W | no | connectors.voice |

No Meta send connector row, no messaging provider, no credential, no media budget, no autonomy grant and no scheduled job is created by the migrations or the seeder (`ProductionIsolationTest`).

**Credential kinds (nothing stored; Joy pastes each on the Credentials screen when he connects it):** courier `pathao_courier_secret`, `carrybee_courier_secret`, `steadfast_courier_secret`, `redx_courier_token` (business slug) and `courier_webhook_secret` (`<business>.<courier>`); voice TTS `azure_speech_key` (preferred, bn-BD Nabanita/Pradeep), `elevenlabs_api_key` (alternative), STT none until the bake-off (`docs/147-r2-voice-bakeoff-plan.md`: Google Chirp 3 `google_speech_access_token`, ElevenLabs Scribe, OpenAI, Gemini); reel drafts `gemini_video_key` (`hq`); supplier mailbox `gmail_mailbox_token` or `graph_mailbox_token` (business slug); ERP draft bill `erp_write_token` (business slug); Meta ads drafts `meta_ads_draft_token`; Meta token health `meta_app_secret` + env `HQ_META_APP_ID`; owner Telegram brief: config `hq.owner_console` driver + the owner's verified private chat; avatar: provider key (HeyGen) + signed consent documents. Full table: `docs/144-connectors-overview.md` section 3 and docs/147.

## 6. Browser walk (first ever for these screens)

**Setup.** `php artisan serve --host=127.0.0.2 --port=8000` against `hq_walk_m6` only (never production), assets from `vite build`. Seeded by an uncommitted script with invented data in two businesses (LaLaDia and Bionic; every Bionic row carries the marker `BIONIC-ONLY`): two inbox threads with A/B/C reply options, three pending customer-message approvals and one demo approval, composed briefings, content plans, two testimonials, avatar consents, skills, an autonomy grant lapsed by Pause All AI then AI resumed, notify-only watches, orders with parcel timelines, supplier documents from the deterministic extractor, a media budget. Users: an unrestricted owner, a second owner, and a **restricted operator limited to LaLaDia**. Sessions were written server-side; no password was typed. Every user's language set to Bangla. In the scratch database the flags behind the screens were switched on so each screen renders (`support.reply_options`, `console.batch_approval`, `console.event_watches`, `console.telegram_brief`, `connectors.courier/voice/media_upload/mail_ingestion/erp_draft_bill`, `ask_hq`); `console.do_mode` was switched on only for the Do-mode check.

**Tool.** Playwright headless Chromium 151. For each screen: HTTP status, console errors and page errors, failed requests, horizontal scroll (`scrollWidth > clientWidth`), English text left on the Bangla screen (text nodes without Bangla, minus brand names, codes and data), `BIONIC-ONLY` anywhere on the page for the restricted user, approve buttons, and calls to `getUserMedia`. Owner: light and dark, 1366 and 390. Restricted: light, 1366 and 390. Screenshots: `H:\WORK\BorDeGuna\hq-complete-2026-10-31\screens\<screen>\<user>-<theme>-<width>.png`, results `screens\walk-results.json`.

| Screen | URL | Owner light 1366 | Owner light 390 | Owner dark 1366 | Owner dark 390 | Restricted 1366 | Restricted 390 | Other-business data shown to restricted |
|---|---|---|---|---|---|---|---|---|
| console | `/dashboard` | ok | ok | ok | ok | ok | ok | no |
| approvals | `/approvals` | ok | ok | ok | ok | ok | ok | no |
| approval-detail | `/approvals/{id}` | ok | ok | ok | ok | ok | ok | no |
| approvals-batch | `/approvals/batch` | ok | ok | ok | ok | ok | ok | no |
| inbox | `/inbox` | ok | ok | ok | ok | ok | ok | no |
| inbox-thread | `/inbox/{uuid}` | ok | ok | ok | ok | ok | ok | no |
| do-plans | `/do-plans` | ok (404) | ok (404) | ok (404) | ok (404) | ok (404) | ok (404) | no |
| watches | `/watches` | ok | ok | ok | ok | ok | ok | no |
| briefings | `/observe/briefings` | ok | ok | ok | ok | ok | ok | no |
| briefing-detail | `/observe/briefings/{uuid}` | ok | ok | ok | ok | ok | ok | no |
| content | `/content` | ok | ok | ok | ok | ok | ok | no |
| research-digests | `/research/digests` | ok | ok | ok | ok | ok | ok | no |
| avatar | `/avatar` | ok | ok | ok | ok | ok | ok | no |
| skills | `/skills` | ok | ok | ok | ok | ok | ok | no |
| autonomy | `/autonomy` | ok | ok | ok | ok | ok | ok | no |
| connectors | `/connectors` | ok | ok | ok | ok | ok | ok | no |
| parcels | `/parcels` | ok | ok | ok | ok | ok | ok | no |
| order | `/orders/{id}` | ok | ok | ok | ok | ok | ok | no |
| voice | `/voice` | ok | ok | ok | ok | ok | ok | no |
| ask-media | `/ask` | ok | ok | ok | ok | ok | ok | no |
| ingestion | `/ingestion` | ok | ok | ok | ok | ok | ok | no |
| ingestion-review | `/ingestion/{uuid}` | ok | ok | ok | ok | ok | ok | no |
| media-drafts | `/media-drafts` | ok | ok | ok | ok | ok | ok | no |
| ads-drafts | `/ads-drafts` | ok | ok | ok | ok | ok | ok | no |
| posture | `/observe/posture` | ok | ok | ok | ok | ok | ok | no |

150 page loads in all (25 screens: owner 4 variants, restricted 2). Console errors other than the expected 404 resource on `/do-plans`: 0. Failed requests: 0. Horizontal scroll: 0. `getUserMedia` calls: 0. Screenshot failures: 0. Remaining English text after the fixes is data, technical labels or server-written sentences (section 7).

**Specific checks.**
- Console: decision cards carry only "open the approval" links (0 approve buttons on the console); the Pause All AI control is on the console and the banner shows while paused (`screens\console-paused\`).
- `/approvals/batch`: read-only list, each message opens its own approval page with `?queue=messages`; the page says the next opens only after this one is decided; there is no approve-all control.
- Inbox thread: options A/B/C, each editable, with the recipient (customer, channel, business) shown and the sentence that the exact text is sent as that person.
- `/do-plans` and `/do-plans/<uuid>`: 404 for the owner, also with the `console.do_mode` flag ON (activation record null). No Do-plans entry in the sidebar.
- Briefing: "Ask to send to my Telegram" button present on the briefing page.
- Autonomy: the lapsed grant shows "lapsed (permanent)", when, why (Pause All AI) and that it never returns by itself.
- Connectors: the media spending limit panel per business (BDT 0 default, owner forms).
- Voice: no microphone request on load (`getUserMedia` calls: 0 on every load).
- Ask HQ: the attach button and the retention sentence present.
- Supplier document review: the deterministic checks and the eight confirmation ticks.

**Restricted user, direct URLs of the other business (curl with the restricted session):** approval, inbox thread, briefing, order and supplier document of Bionic all answered **404**; the same items of LaLaDia answered 200. On every listed screen the page text never contained `BIONIC-ONLY`.

## 7. Defects found in the walk, and what was done

| # | Defect | Fix |
|---|---|---|
| 1 | Approvals list and detail, inbox list and thread, and Ask HQ showed most of their words in English on the Bangla screen: bare JSX text never passed to `t()`, although the dictionary test listed these files as translated (it checks only literals handed to `t()`). | Branch `fix/bangla-work-screens` (`59cc0d9`, one builder): every UI word through `t()`, 160 dictionary entries, label maps for statuses and verdicts, data untouched. Merged `77d3a3b`. |
| 2 | `/connectors` family titles and needs had no dictionary entries. | 12 entries (`7311157`). |
| 3 | `FlagGatedToolsTest` order-dependent (section 2). | `7af36bd`. |
| 4 | Unused `t` in playbooks (ESLint). | `b1b2e9a`. |

Not defects of the candidate (seed artefacts, recorded so nobody chases them): a hand-made briefing with the test fixture's short bundle crashed the briefing page (`freshness.sources` missing); real composer output renders fine, so the walk uses the composer. A hand-hashed approval showed "Integrity failure" (the integrity check working); the seed now uses `CanonicalPayload::hash`.

**Still English on Bangla screens (left, logged for Joy):**
- Server-written sentences: briefing reasons and section notes, posture reasons, supplier-document check messages (`BillChecks`), content floor rule descriptions, autonomy grant source line. They are composed in PHP in English; translating them means a message-code layer, a separate piece of work.
- Relative times ("5 min ago") from `lib/format` `timeAgo`, English by an existing decision (`components/console/age.ts`).
- The console's monthly AI cost card shows USD ("$0.00 / $50.00"), pre-existing; Joy's rule is BDT for amounts, so this is his call.
- Data and technical labels (tool keys, credential kinds, status values from couriers and shops, agent names, the "Identifiers and policy" block).

## 8. Release notes for Joy (second candidate)

What this release adds: the chat-first UI, Pause All AI, the P3 identity/scope work, the owner console (sequence-only message review, reply options, owner Telegram brief, event watches; Do mode dark), connectors with fakes (courier tracking, voice, chat uploads, reel drafts, supplier mailbox, ERP draft bill, paused ad drafts, Meta token health), content calendar + testimonials, research digests, avatar consent, skills, autonomy ladder with one-way lapse, media spending limits. **Everything external stays OFF.**

1. Before: Wave 4 identity repair and its verification are separate authorisations and come first (ruling 18). This release must not be combined with them.
2. Migrations: the 9 files in section 4, `php artisan migrate --force`, BEFORE the scheduler runs new code.
3. Seeder: `php artisan db:seed --class=RolesAndPermissionsSeeder --force` (82 permissions; adds `hq.media.budget.manage`).
4. Stays OFF after release: all 15 flag rows; the 8 switches with no row; Do mode (code record null); STT (no provider); model extraction; AI runtime; Meta send connectors (none registered, P3-G4); media budgets (no row = BDT 0, so every paid media job is refused); no autonomy grant.
5. Scheduler: still stopped in production. When Joy resumes it (separate authorisation), the new line `hq:autonomy:lapse-sweep` runs every minute; it only lapses grants and there are none.
6. Verify after: `/up`, `/observe/posture`, 15 flags all off, 0 credentials of the new kinds, `ai_pause_state` readable, the sidebar renders in Bangla.
7. Rollback: code to `828bf42`; the 9 migrations roll back cleanly (proved), but rows written after release in the new tables are lost on rollback. Restore from the pre-release dump if any were written.

Still owed before release (coordinator / Joy): CI `workflow_dispatch` on the exact commit, tag, the Stage A/B release and real `restore.sh` rehearsal on this candidate, the public-repository history review sign-off (ruling E), Joy's explicit authorisation.

## 9. Open ChatGPT defaults (collected)

From docs/143 (R1):
1. A Do step's approval is refused to the person who started the plan even on a human plan (starting is never an approval).
2. An action watch is enabled only by a person other than its proposer; a notify-only watch may be enabled by its proposer.
3. `conversation.*` events are not watchable at all.

From docs/145 (R3):
4. A content draft's self-approval counts only while the writer is the sole eligible reviewer (active, 2FA, `hq.content.review`, business in scope).
5. A lapse inside a caller's rolled-back transaction rolls back; the next decision or the minute sweep lapses again (known limit).
6. Avatar consents end at most 36 months ahead; revocation is immediate and final and withdraws unfinished generations.

From docs/147 (R2):
7. `voice.synthesize` is business-scoped so a business budget pays (alternative: an HQ-wide media budget).
8. A supplier bill with no readable item lines cannot reach the ERP (alternative: a total-only draft after review).
9. FX: static USD 130.00 plus 5% margin, rounded up; a live rate source would be a separate connector.

From this walk:
10. Relative times stay English on Bangla screens (existing decision) and server-written reasons stay English until a message-code layer exists.
