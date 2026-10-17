# 140. P3-W1: business-scoped conversation threads and human identity-conflict resolution (17 October 2026)

Branch `p3/w1-conversation-scope-and-conflicts` from `origin/integration/ui-and-scope` `9463cc0`, worktree `hq-wt/k`, test database `hq_test_k`. Authority: `docs/138-chatgpt-ruling-2026-10-17.txt` section 5 P3-W1, and `docs/131-chatgpt-ruling-2026-10-16.txt` section 1 (last paragraph) and section 2 rulings 2 and 3. Background: docs/122 (business-scoped identities, `customer_identity_conflicts`, quarantine), docs/126 (repair and its human-only tool), docs/135 (UI design system and honesty rules), docs/136 (business scope audit). Nothing here is released or merged; Joy alone releases.

## Plan (committed first)

1. Migration `2026_10_17_100000_scope_conversation_threads_to_business`: add the unique partial index (business_id, channel, external_thread_ref) over open threads, then drop the global (channel, external_thread_ref) one. Before anything changes, a check refuses with a clear message when existing open threads would violate the new key. `down()` puts the global key back only when no thread reference is open in two businesses, and otherwise refuses and names the count.
2. ConversationService keeps failing closed: the thread is created inside a savepoint; a unique violation is re-read in the SAME business (same-business replay or race returns that thread), and when nothing of this business holds it (the global key still deployed and another business holds the reference) it throws `ConversationThreadCollision` and attaches nothing. The echo path (`recordExternalOutbound`) is narrowed to the Page's own business so an echo can never land on another business's thread.
3. Migration `2026_10_17_110000_create_identity_conflict_resolutions`: `customer_identity_conflict_resolutions`, append-only (the database refuses UPDATE and DELETE), one row per resolution, the conflict row's `resolved_at`, `resolved_by` and `resolution` set in the same transaction.
4. `ConflictResolver` (the only writer of a resolution) and the tool `customers.identity_conflicts.resolve`: R3, write of HQ rows only, permanently human-only, permission `hq.customers.identity_conflicts.resolve`, scope from the business slug, idempotent. Three choices only: keep the owner, move to the claimant of the SAME business, or mark not resolvable. Evidence and a reason are required. A quarantined identity is released only when the rows support it (no other live copy of the key in the business, a store of the same business, and for `contradicted_by_evidence` at least one use in the identity's own business). Never a cross-business move or merge.
5. Screen `/customers/identity-conflicts` in the docs/135 look, Bangla, the action in a popup. Open conflicts per business, only businesses the person may read; resolved history below.
6. Pins: tool registration in `config/hq.php`, permission in `RolesAndPermissionsSeeder` (owner and operator through `*` only), GatewayCoverageStructureTest (effectful list, call site, service pin), PolicyEngineTest never-list, ToolNamespaceTest, structural searches (call site and consent reader allow-lists with reasons), BusinessScopeAuditStructureTest verdict, route pins in Customer360Test and PostureScreenTest, Bangla screen list.
7. Tests: two businesses with one thread reference make two threads; same-business replay deduplicates; a cross-business collision never attaches; concurrency on real PostgreSQL; the restricted person sees only their own business; agent refused; cross-business resolution impossible; idempotent; audit row; release refused without evidence.
8. Gate: full Pest parallel, Pint, `npx tsc --noEmit`, `npx vite build`, `bash deploy/tools/structural-searches.sh`. Push, do not merge.

## What was built, in short

Think of a thread reference as a locker number. Until now the whole group shared one row of lockers, so if LaLaDia and Bionic were ever handed the same number, the second one was simply turned away at the door. Now each business has its own row: the same number opens a different locker in each, and nobody can reach into the other row. The door still turns a stranger away whenever the old single row is the one installed.

### Part 1: business-scoped conversation threads

- Migration `2026_10_17_100000_scope_conversation_threads_to_business`: creates `conversations_business_channel_thread_unique` on (business_id, channel, external_thread_ref) over open threads (reference not null, status not closed), then drops `conversations_channel_thread_unique`. No moment without a key.
- Backfill check before any change: open threads holding one reference more than once in one business are counted and named (up to 20), and the migration refuses with "close or repair the duplicates by hand with evidence first; nothing was changed". Closed threads are history and never block.
- `down()`: puts the group-wide key back only when no open reference is held by two businesses (or twice anywhere); otherwise it refuses with the count and points to the pre-deploy backup. No thread is closed or merged to make a rollback fit.
- `ConversationService::openOrFind()` keeps failing closed. It looks in its own business; if nothing is open, it creates the thread inside a savepoint. A unique violation on the thread key is read again in the SAME business only: a same-business replay or a race returns that thread; otherwise `ConversationThreadCollision` is thrown ("held open by another business ... the event stays replayable"), and the inbound ingest transaction rolls back whole. With the new key deployed another business can no longer reach that path; the handling stays, as the ruling asks, until the migration is deployed and verified.
- `recordExternalOutbound()` (Page echoes during the shadow week) used to pick a thread by channel and reference across the group. It now reads the Page's business from the platform account first and looks only in that business; an echo from a Page HQ does not hold has nowhere to go and returns null.
- Not changed, recorded: `DeliveryLedger::applyWatermark` still matches Messenger threads by reference alone. A Messenger reference is `page:psid`, so it already carries the Page, and two businesses never share a Page row; a later wave may still narrow it by business for symmetry.

### Part 2: human-only identity-conflict resolution

- Migration `2026_10_17_110000_create_identity_conflict_resolutions`: table `customer_identity_conflict_resolutions`, one row per resolved conflict (UNIQUE conflict_id), append-only (database triggers refuse UPDATE and DELETE; the model refuses them too). It holds the choice, the owner before and after (a CHECK ties "owner changed" to `moved_to_claimant` and to nothing else), whether a held identity was released, the evidence the person stated plus what HQ checked, the reason, the restrictions carried, the person and the correlation id. `down()` drops it only while it is empty.
- `App\Identity\Conflicts\ConflictResolver::resolveConflict()` is the only writer. Under row locks on the conflict and the identity it allows three choices:
  - `kept_owner`: the identity stays with its holder. A held identity is released only when `ReleaseEvidence` finds no blocker.
  - `moved_to_claimant`: only for a refused re-point (`link_refused`), only to the claimant the conflict names, only when that claimant is a standing (not merged) customer of the SAME business, and only when the identity still has the owner the conflict recorded. Grants never follow the identity. Do-not-contact and withdrawal recorded under the old owner on this identity are re-recorded on the new owner through `ConsentLedger` (basis operator, actor the person, evidence pointing at the original event). The old owner's customer-wide restrictions are listed in the record for a person to review, never moved silently.
  - `not_resolvable`: the conflict closes, the identity is untouched; a held identity stays held as evidence.
- Refused, with nothing written: a conflict of another business (the same answer as "no such conflict"), an identity of another business, a claimant of another business, a claimant that was merged away, a resolved conflict asked to change its answer, a held identity the rows do not support releasing, missing evidence or reason.
- `ReleaseEvidence` (read only, shown on the screen and checked again under the locks): a held identity may come back live only when no other live identity carries the same key in the business (`live_copy`), its store is of the same business (`store_business`), and, for a row held as `contradicted_by_evidence`, at least one order, thread participant or consent record of its OWN business now uses it (`no_own_use`).
- Idempotent: the same choice on a resolved conflict returns the recorded row (`already_resolved`, `replayed: true`); another choice is refused. One resolution row, one audit row.
- On success the conflict row gets `resolved_at`, `resolved_by` and `resolution` (the choice), and the audit log gets `identity.conflict.resolved` with the person as actor; the gateway's own `tool_calls` row and R3 audit sit beside it.
- Tool `customers.identity_conflicts.resolve` (`ResolveIdentityConflictTool`): R3, write, `permanentlyHumanOnly: true`, `agentExecutable: false`, scope from `business:args.business_slug`, permission `hq.customers.identity_conflicts.resolve`, idempotent, channel internal, no monetary impact, not customer facing, personal data. Inputs: business slug, conflict id, resolution (enum of the three), evidence and reason (10 to 2000 characters each). The actor is the gateway-authenticated person; a console call with no person is denied on the permission, and the tool itself refuses a call with no human actor.
- Permission `hq.customers.identity_conflicts.resolve` seeded additively in `RolesAndPermissionsSeeder`; owner and operator hold it through `*`, no other role is given it.
- Screen `/customers/identity-conflicts` (`resources/js/pages/customers/identity-conflicts.tsx`), sidebar entry "Identity conflicts" under Customers. Reading needs `hq.customers.quarantine.review`; the Resolve button and the POST need the new permission. The table sits in the shared scrolling table box; held identities carry a warn chip; the key is masked to its last four characters; the action is a popup (Joy's rule) offering only the choices this conflict allows, with a warning listing why a held identity cannot be released, and required evidence and reason boxes. Every string has its Bangla entry; the screen joined `BanglaDictionaryTest`.
- Controller `CustomerIdentityConflictController`: lists open conflicts and the 30 latest resolutions of readable businesses only; a conflict of another business answers 404; the action is a gateway request as the person (`ToolRequest::forOperator`), never a direct write.

## Pins updated

- `config/hq.php`: tool registered with its reason.
- `GatewayCoverageStructureTest`: the controller as a reviewed gateway call site; `->resolveConflict(` reachable only from the tool, and `ConflictResolver` named only by the tool and itself (with a non-vacuous check); the effectful list now includes `customers.identity_conflicts.resolve` (docs/105 §1.2 row 12).
- `PolicyEngineTest` never-list: `consent.lift_restriction`, `customers.identity_conflicts.resolve`, `customers.quarantine.repair`, `outcomes.attribute`.
- `ToolNamespaceTest`: the new key in the live set.
- `deploy/tools/structural-searches.php`: the controller in the S2 call-site list, the resolver in the S2 effect services, and `ConflictResolver` (re-records restrictions, never a grant) and `ReleaseEvidence` (counts consent rows as release evidence, reads only) in the S3 consent-reader allow-list, each with its reason.
- `BusinessScopeAuditStructureTest`: `Identity/CustomerIdentityConflictController.php` is `scoped`.
- Route pins: `Customer360Test` (write routes under /customers) and `PostureScreenTest` (known write routes) gain `POST customers/identity-conflicts/{conflict}/resolve`; `AppShellTest` counts 26 sidebar entries.

## Tests

- `tests/Feature/Conversation/ConversationThreadScopeTest.php` (8):
  - one reference in two businesses is two threads with their own customers, participants and messages;
  - same-business replay deduplicates (same message once, a later message on the same thread);
  - under the old key a second business's message throws `ConversationThreadCollision` and leaves nothing (no thread, customer, participant or message), and works once the new key is built;
  - the migration refuses same-business duplicates and builds over a closed one; its down refuses once two businesses share a reference;
  - an echo lands only on its Page's business's thread;
  - on real PostgreSQL with two extra connections: a same-business second worker waits (lock timeout 55P03) then gets 23505 while another business inserts at once; a worker that loses the race between lookup and insert gets the winner's thread; a worker racing another business's insert on the same reference gets its own thread, never the other's.
- `tests/Feature/Identity/IdentityConflictResolutionTest.php` (8):
  - move from the screen popup: identity moves, conflict closed, resolution row with evidence and reason, stop-contact carried, grant not carried, customer-wide restriction listed for review, audit row, tool call, and the database refuses an UPDATE of the record;
  - idempotent replay, and a refused change of answer;
  - human-only: descriptor, no seeded agent holds it, an agent call denied "permanently human-only", a reviewer without the permission denied, brand manager forbidden on the route, a console call with no person denied, owner and operator only;
  - a restricted person sees and resolves only their own business (404 on the other, the gateway refuses "not scoped");
  - never across a business: wrong business named, claimant of another business, misfiled conflict;
  - a held identity is released only on evidence, blocked by a live copy, kept held when marked not resolvable;
  - a reviewer without the permission sees no action; a support agent is forbidden;
  - the resolutions table's down refuses once a row exists.

## Found by the full suite, and fixed

- The resolutions table references `customer_identity_conflicts`, so the docs/122 migration's `down()` (which drops that table) now needs the docs/140 resolutions migration rolled back first. `migrate:rollback` already runs them in that order. The two test files that call the docs/122 migration directly (`IdentityBusinessScopeMigrationTest`, `IdentityRepairTest`) now take the resolutions table down before it and put it back after it, the way a real rollback and re-apply would; 16 tests had failed with "cannot drop table customer_identity_conflicts because other objects depend on it" before that.

## Open points for Joy (none blocks this branch)

- A move closes only the conflict the person resolved. Another open conflict on the same identity (a second claimant) stays open and then shows the new owner; it needs its own decision.
- Reading the screen uses the existing review permission; resolving uses the new one. Narrowing resolving further (owner only) would be a seeder change, not code.
