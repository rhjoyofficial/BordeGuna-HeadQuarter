# 142. HQ complete: base branch record, 31 October 2026

Branch `hq-complete/base`, worktree `hq-wt/m1`, test database `hq_test_m1`. Built by agent M1 of the HQ completion programme from `integration/ui-and-scope` `1e83bd6`. Nothing here touches production; Joy alone releases. No secret, token, real number or customer data is in any file of this branch.

## What merged, in order

1. `p3/w1-conversation-scope-and-conflicts` (docs/140): business-scoped conversation thread key, human-only identity-conflict resolution (tool, screen, pins). `--no-ff`.
2. `p3/w3-inert-customer-ai-path`: the branch held no commits of its own (it pointed at the same commit as W1, `f78efbc`), so the merge is empty. W3 itself was not started; it was built here (below).
3. `p3/w4-pause-all-ai` (docs/141): the durable fail-closed pause state, switch, checks at every AI entry point, routes, commands, `PauseAllAiTest`. `--no-ff`.
4. `fix/php-8.3.36` (`828bf42`): `deploy/Dockerfile` now `FROM php:8.3.36-fpm-alpine`, and `deploy/php.ini` carries `session.upload_progress.enabled = Off`. Verified in the merged tree.

## What was finished on the base

- **W4 UI** (docs/141 plan step 5, which the WIP branch lacked): `components/ai-pause.tsx` with the banner in the shell and the control on the console live panel and the posture screen; server-decided `can_pause` / `can_resume`; reason in the confirm dialog; separate resume action; Bangla strings; the two routes pinned in the posture allow-list. Record appended to docs/141.
- **W3 end-to-end tests** (`tests/Feature/Conversation/InertCustomerAiPathTest.php`, 5 tests): inbound to scoped thread to proposal to human take-over with nothing sent (HTTP asserted unsent, no delivery, no outbound row, no reply tool call); replay changes neither work nor identity ownership; a second inbound while a person holds the thread makes no run and no model call; one thread reference in two businesses is two threads and two customers; inbox reads and every inbox action are 404 outside the person's business scope.

### W3 definition of done against docs/138 P3-W3, bullet by bullet

| Required test | Where it is proved |
|---|---|
| inbound replay does not duplicate work or change identity ownership | InertCustomerAiPathTest (replay), ConversationThreadScopeTest, SupportWakeUpTest (one run per message) |
| cross-business identity/thread collision fails closed | ConversationThreadScopeTest (old key refuses, new key separates), CrossBusinessQuarantineTest |
| unresolved or quarantined identities never inherit another identity's grant | IdentityConflictResolutionTest (permissions do not follow a moved identity), IdentityBusinessScopeTest, ConsentEnforcementTest |
| mixed Bangla/English yields a validated Bengali-script reply proposal | InertCustomerAiPathTest (mixed input, proposal), SupportLanguageContractTest A to J |
| no send during Ask or shadow tests | InertCustomerAiPathTest (`assertNothingLeftHq` after every step), SupportWakeUpTest, AskHqTest |
| human handover correct, including a second inbound while waiting | InertCustomerAiPathTest (second inbound), SupportWakeUpTest, HandoverNotificationTest |
| support context and inbox reads business-scoped | InertCustomerAiPathTest (404 on read and every action), InboxScreenTest, InboxContextPackageTest |
| provider failure and uncertain delivery not mislabelled as delivered | SupportSettleExecutedReplyTest (uncertain hands over), DeliveryLedgerAndSenderTest, DeliveryReconciliationTest |
| every future send still subject to Gateway, consent, idempotency, audit, verification | OutboundPolicyAndReplyToolTest, SendWhatsAppReplyToolTest, ConsentEnforcementTest, DeliveryLedgerAndSenderTest |

## Collisions resolved

- `bn.json`: W1's identity-conflict strings and the integration branch's global-switch strings both kept (one comma added; no duplicate key, checked by script). Pause strings appended.
- `RolesAndPermissionsSeeder`: `hq.customers.identity_conflicts.resolve` (W1) and `hq.ai.pause`, `hq.ai.resume` (W4) all kept.
- `PostureScreenTest` write-route allow-list: `POST ai/pause`, `POST ai/resume` added on purpose with the reason.
- Posture screen description: it claimed "nothing on this page changes anything"; reworded (and its Bangla key replaced) now that the pause control sits on it.
- Global Pest helper `modelCall()` was declared in both `PauseAllAiTest` and `EvaluationCostAttributionTest`; the full suite loads every test file in one process and died with a redeclare fatal. Renamed the W4 one to `pauseModelCall()`. A scan finds no other duplicate global helper.
- Pint: import order in five W4 files fixed (no logic change).
- Route pins, tool pins, screen registry and migrations did not collide this time: W1 migrations (`2026_10_17_100000`, `..._110000`) and W4's (`2026_10_17_100000_create_ai_pause_state_table`) share a date prefix but different names; migration order is by full filename and all ran in the suite.

## Test numbers

Full Pest suite on the merged tree, parallel 8 processes, PostgreSQL: **2,275 passed, 0 failed** (42,263 assertions, 200 s). Before the merge: integration 2,219; W1 2,235 on its own branch. Added: W4 `PauseAllAiTest` (on its branch) and 5 new W3 tests. Pint passes (whole repo). `tsc --noEmit` clean. Vite build succeeds.

## Open points

- `p3/w3-inert-customer-ai-path`, `p3/w4-pause-all-ai` branch tips are now behind `hq-complete/base`; use the base.
- Pause All AI has no endpoint for non-browser operators beyond the console commands `hq:ai:pause` / `hq:ai:resume`; by design.
- Posture and console UI were checked by tsc, Vite build and the route/prop tests, not in a browser. A browser pass on the banner and dialog is still owed before release.
- W3 is synthetic only: no real Meta traffic. Provider readiness remains P3-W2 (his side: business verification, app review, tokens).
