# 126. Identity repair and detector extension, W2 (15 to 16 October 2026)

Branch `p3/identity-repair` from main `7fb5e71`, worktree `hq-wt/h`, test database `hq_test_h`. Authority: `docs/120-chatgpt-ruling-2026-10-15.txt` section A3 (all ten points and its last two paragraphs), section C Stage 2, items 36, 37 and 39. Background: docs/117 (detector, flags, review, repair design §6), docs/121 §5 (the production case), docs/122 (W1 schema, quarantine, conflicts, open questions Q1 to Q3), docs/123. Nothing here is released or merged; Joy alone releases, and no production repair runs without his explicit authorization after he has read the dry run.

## Plan (committed first)

1. Migration: `customer_identity_repairs` (one row per repair batch, keyed), `customer_merge_reversals` (append-only lineage, the original `customer_merges` row untouched), and the quarantine flag `kind` CHECK widened for the new detector kinds.
2. Detector extension (item 39): messages, conversation participants, quarantined identities and open identity conflicts become findings with their reason; read-only by default, `--flag` append-only as before; suppression extended to the new row kinds before any flag exists. W1 Q3: the follow-ups screens withhold a phone the same way Customer 360 does.
3. Repair: planner (read-only), applier (locked, fingerprinted, per-split transactions, restartable), verifier (ownership and business-scoped reads before a batch is marked complete), recount (item 37). Command `hq:identity:repair` is dry-run only and prints the plan, the keys, the row counts, the lineage, the ambiguous cases and the human lists.
4. Tool `customers.quarantine.repair` (human-only, permission `hq.customers.quarantine.repair`, R3 write of HQ rows only) reached from the quarantine screen after an explicit `needs_repair` review; the only writer of a repair. Pins extended the way docs/115 and docs/117 did.
5. Tests: the production case as a fixture; dry run writes nothing; apply splits; second apply proposes nothing; lineage and audit; concurrent change refused; ambiguous row quarantined and reported; consent untouched; reads business-scoped afterwards; tool human-only, refused for agents, permission required.
6. Gate (full Pest parallel, Pint, tsc, Vite, Bangla dictionary, no em dash) and this record.

## What was built, in short

Think of the leak as letters filed in the wrong cabinet. The repair does not burn the letters or rewrite the old filing slip. It reads which company's letterhead each letter carries, finds (or opens) that company's folder for the same person, moves the letter there, and staples a new slip to the old one saying what moved, when, why and by whom. A letter whose letterhead cannot be read is not moved; it goes into a "held" tray with a note.

- `RepairPlanner` (SELECT only) turns the detector's findings into one batch per target business, one split per (source customer, target business). It names the target customer from evidence only, lists what it will not touch, and gives each batch a key built from the rows AND their current state.
- `RepairApplier` is the only writer. It takes an advisory lock, refuses a key the live data no longer produces, refuses any split whose rows are not all reviewed, re-checks each split under row locks against its fingerprint, applies each split in its own transaction, records a `customer_merge_reversals` row, verifies, recounts, and only then marks the batch `verified`.
- `RepairVerifier` and `RepairRecount` prove ownership, the business-scoped reads and the stored figures before a batch is complete.
- `HeldMemoryAssigner` lets a person give a business to a memory the repair withheld.
- Tool `customers.quarantine.repair` (R3, permanently human-only, permission `hq.customers.quarantine.repair`) is the only caller of the applier. The quarantine screen and `hq:identity:repair --apply --by=` both reach it through the Tool Gateway.
- Screen `/customers/quarantine` now shows the proposed batches, every row's review state, the rows that cannot be repaired, the held memories (with the assign form) and the repair history. Strings in `bn.json`.

## Review of the inherited code (what was wrong, and fixed)

- The tool's input schema used the keyword `pattern`, which the gateway's schema validator refuses: the tool could never have executed. The check moved into the tool.
- The batch key hashed only which rows were involved, not their state, so a change between the dry run Joy read and the apply would have been applied silently. The key now includes each split's fingerprint; a change after the dry run is refused with "the data changed since the dry run".
- A new customer copied the LaLaDia customer's NAME into Bionic when the phone matched. That is the leak in the other direction. The new customer now carries only the order's own phone.
- The review gate counted flags, not rows, and let unflagged rows through (`needs_repair > 0` was enough). It now counts per row: every row must carry a review, at least one `needs_repair`, none `disputed`.
- A store-less identity moved by a cross-business merge was reported as "held, business unknown" and also moved. Its business now comes from the merge lineage, and a moved identity is never also listed as held.
- An identity used by a thread participant or a consent row of another business would have been moved, creating a new crossing. It is now held and reported as ambiguous (`repair_ambiguous_identity`).
- A "superseded" identity (the target already holds that key live) had its conflict closed although nothing resolved it. It now stays open for a person.
- Ambiguous rows were flagged across every business on any apply, and an ambiguous split was flagged as a `merge_pointer` on the customer. Now only rows of the applied business are flagged, each under its own kind.
- Customer `updated_at` was in the fingerprint, so an unrelated touch (a recount by an earlier split) refused the next split. The fingerprint now carries what decides the split.
- The recount's verdict was computed but ignored. A mismatch now fails verification.
- Nothing stopped two repairs at once (a duplicate batch row would have thrown). A PostgreSQL advisory lock now refuses the second one.
- A batch row was written even when nothing could be applied. The first run now refuses, writing nothing, when a split is unreviewed or joins a business the person may not read.
- Consent: an outstanding restriction recorded under the wrong customer would no longer have applied to the right customer. It is now preserved (see point 9).
- The verifier failed a batch when the source customer still had rows of a third business (another batch's work). It now checks the source's holds only when nothing else still crosses.

## Per A3 point: what was built and which test proves it

All tests are in `apps/hq/tests/Feature/Identity/IdentityRepairTest.php` (16 tests). The fixture `plantProductionCase()` plants docs/121 §5 in the old shape and runs the W1 migration, so the quarantined `shop_order` and `shop_user` rows and their conflict rows are exactly what production will hold.

1. Dry run by default. `hq:identity:repair` writes nothing without `--apply`. Test: "reproduces the production case ... writing nothing" (full-row snapshot of 17 tables before and after the planner, the command, `--recount` and `--json`).
2. Splits, counts, lineage, ambiguous cases before writing. The command prints each split (from, to, new or existing, merge lineage, row ids, identities moved and copied, conflicts, held items, review state, unreviewed rows). The same test asserts the exact plan; "quarantines and reports a row ..." asserts the ambiguous listing.
3. Separate authorization. A batch runs only through the human-only tool, after every row is reviewed. Test: "refuses an unreviewed batch without writing even the batch row, then applies the reviewed one from the screen".
4. Find or create the customer of the row's business, copy the business-scoped identity, re-point only that business's rows. Same test (a new Bionic customer with only the phone; order #16 and the two store ids under it, live in Bionic; a Bionic phone identity copied; the LaLaDia order and phone stay). "reverses a historical merge" proves the found case (the absorbed customer).
5. Lineage and audit. A `customer_merge_reversals` row per split, a `customer_identity_repairs` row per batch, audit rows `customer.repair.started`, `customer.created`, `customer.repair.applied`, `customer.repair.verified` and the gateway's `tool.customers_quarantine_repair`, all as the person. Tests: the apply test and the merge test.
6. Idempotent, restartable, protected against concurrent change. Tests: "is idempotent" (a second apply changes no row; the next dry run proposes nothing), "is restartable after a mid-batch failure" (a database trigger fails the second split; the resume skips the first and applies the second), "refuses a concurrent change and a second repair running at the same time" (advisory lock held by another connection; a row changed after the dry run), "refuses a split whose rows change while its batch is being applied".
7. The original merge row is never touched; the reversal is in `customer_merge_reversals`. Test: "reverses a historical merge with lineage" (merge row equal before and after; UPDATE and DELETE on the reversal refused by the database and the model; the merge is never proposed again).
8. Business-less memories withheld until assigned. Every business-less memory under a source customer is listed on its reversal and withheld from Customer 360 until a person with the repair permission assigns a business the evidence names. Test: "withholds business-less memories ...".
9. Consent. No consent row is moved, edited or converted. A grant is never carried. An outstanding restriction (a do-not-contact or withdrawal that is still the latest word at its scope) is preserved by APPENDING the same restriction to the target customer's ledger, naming the original event as evidence. Every consent row is listed for a person to re-record, restrictions first. Test: "never moves or converts consent".
10. Verified before complete. The verifier checks every moved row's owner and business, that nothing of the target business is left under the source, that the detector no longer reports the moved rows, the Customer 360 reads of both customers, and the recount; only then is the batch `verified`. Tests: the apply test and "detects participants and messages ... then reads business-scoped after the repair" (360, inbox and Support context).

The last two paragraphs of A3. Detection now covers participants and messages (item 39), read-only; suppression holds thread rows of another business before any flag exists (same test). A row whose business cannot be established is quarantined and reported, never guessed: "quarantines and reports a row whose business it cannot establish" and "holds an identity used by rows of two businesses".

Item 36: "is the human-only gateway tool" (agent refused permanently, no permission refused, a restricted person refused before anything is written, HTTP 403 for a brand manager, owner and operator only through `*`). Item 37: "recounts by business and verifies every count against the source rows". docs/122 Q3: "withholds on the follow-ups screen a phone the Customer 360 page withholds".

Mutation checks (code broken, test seen failing, code restored, `git diff` clean): removing the planner's skip of a reversed merge fails the merge test (idempotency); removing the applier's `already_verified` return fails the idempotency test; letting two candidate customers through fails the ambiguity test; removing the two-business identity check fails its test.

## Migration, permission, pins

- Migration `2026_10_15_600000_create_identity_repair_tables` (additive): `customer_identity_repairs`, `customer_merge_reversals` (append-only triggers), the quarantine `kind` CHECK widened with `participant`, `message`, `identity_conflict`. Its `down()` refuses when a reversal or a flag of a new kind exists.
- Permission `hq.customers.quarantine.repair`, additive in `RolesAndPermissionsSeeder`; owner and operator through `*`, no other role. Release: `php artisan db:seed --class=RolesAndPermissionsSeeder`.
- Pins: `config/hq.php` registers the tool; `GatewayCoverageStructureTest` (the effectful list; `->applyRepair(` and `RepairApplier` reachable only from the tool; the screen and the command as reviewed gateway callers); `PolicyEngineTest` (the permanently human-only list, sorted: `consent.lift_restriction`, `customers.quarantine.repair`, `outcomes.attribute`); `ToolNamespaceTest`; docs/105 §1.2 row 11.
- Routes: `POST customers/quarantine/repair` and `POST customers/quarantine/memories/{memory}/assign`, both behind the repair permission.

## Gate

Run on this branch, 16 Oct 2026:

- Full suite `DB_DATABASE=hq_test_h php artisan test --compact --parallel --processes=3`: 2,140 passed, 0 failed, 32,800 assertions (16 of them new, in `IdentityRepairTest`; two existing route pins extended for the two new POST routes: `PostureScreenTest`, `Customer360Test`).
- Pint `--test` passed; `npx tsc --noEmit` clean; `npm run build` (Vite) built.
- `BanglaDictionaryTest` green with the new screen strings; no em dash in any file written here.
- Migration applied by every test run (RefreshDatabase on PostgreSQL 17); the production fixture also rolls the W1 migration back and forward around it.

## The production dry run, for Joy, AFTER the release

Nothing here runs before the release, and nothing mutates without Joy's word.

1. Dry run, read only:
   `docker compose -f deploy/docker-compose.yml --env-file deploy/.env exec -T app php artisan hq:identity:repair --recount`
2. Expected for the known case (the two store ids and the keys will be production's own):
   - `Batch for business [bionic] (#3), key <64 hex>: 1 split(s)`
   - `from customer #1 (laladia) to a NEW customer of [bionic]`
   - `lineage: no merge lineage (an unscoped lookup attached the rows)`
   - `rows: 1 order(s) (#16), 0 conversation(s) , 0 participant(s), 0 message(s), 0 memory(ies) with a business`
   - `identities: 2 back live under the target (#<shop_order>, #<shop_user>), 0 superseded by a live row (stay held, conflict stays open), 1 copied (phone), 2 conflict(s) resolved`
   - `new customer: phone from the order, nothing else (no name or e-mail of the other business is copied)`
   - `review: NOT YET ... not reviewed 3; flags none yet (run hq:identity:cross-business --flag)`
   - `0 ambiguous case(s)`, `0 identity(ies) stay quarantined`, `0 conflict(s) stay open`, `0 consent row(s)`; business-less memories under customer #1, if any, are listed as withheld.
   - The recount table shows customer #1 with orders stored 2 and from rows 1 (the stale figure the pre-fix upserter left); that is what the repair corrects.
   - `Dry run: nothing written.`
3. Only on Joy's word: `docker compose -f deploy/docker-compose.yml --env-file deploy/.env exec -T app php artisan hq:identity:cross-business --flag` (append-only flags), then review the three rows (order #16, the `shop_order` id, the `shop_user` id) as Needs repair on `/customers/quarantine`.
4. Run step 1 again: the split now says `AUTHORIZED`. Only on Joy's explicit authorization, press "Apply this repair batch" on the screen, or run `docker compose -f deploy/docker-compose.yml --env-file deploy/.env exec -T app php artisan hq:identity:repair --apply --key=<key> --by=<Joy's email> --reason="<why>"`. Expected: `Batch <key>: verified`, one reversal, verification ok.

## Deviations

- The review gate is stricter than the ruling's minimum: every row of a split must carry a review, not just one `needs_repair`.
- Restrictions are preserved by appending the same restriction to the target's ledger (never a grant), in addition to the human re-record list. The ruling allows "preserve or fail-closed"; appending is the preserve branch and keeps history append-only.
- After a repair, every business-less memory under the source customer is withheld until assigned, not only those created before a merge. Before a repair, docs/117's merge cutoff still applies.
- Memory assignment is a permissioned, audited controller action (`HeldMemoryAssigner`), not a second gateway tool: it sets one column on one held row and changes no ownership.

## Open questions (new)

1. A "superseded" identity (two rows hold one key in one business) and a refused re-point both leave an open conflict that only a person can settle, and no screen settles a conflict yet. Not in the production case. Build a conflict-resolution action, or leave it to a later wave?
