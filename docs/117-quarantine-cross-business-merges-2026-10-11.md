# 117 - Quarantine of pre-existing cross-business merges (11 October 2026)

Status: PLAN, then BUILT on branch `p3/s2-quarantine-cross-business-merges` (worktree `hq-wt/i`, from `main` `a8a6410`). Not merged, not deployed, nothing activated.
Asked by: `docs/110-chatgpt-ruling-2026-10-10.txt` §5 (m), answering `docs/109` §8 question 2:

> Flag and quarantine questionable cross-business merge rows from affected Customer 360 reads. Do not blindly undo rows. Flagging alone is insufficient if information could leak across businesses. Suppress questionable relationships until a human-reviewed, audited correction preserves lineage.

## 1. What a merge did before the guard (the blast radius)

`IdentityResolver::merge($kept, $absorbed)` before `docs/109` §6 accepted two customers of different businesses. In one transaction it:

1. re-pointed every `customer_identities` row of the absorbed customer to the kept one (ids recorded in `customer_merges.moved_identity_ids`); identities carry no `business_id`, only an optional `source_store_id`;
2. re-pointed every `facts_orders` row (`customer_id`); each order keeps its own `business_id`;
3. re-pointed every `memories` row (`customer_id`); a memory's `business_id` may be null, and a colliding live structured memory of the absorbed row was closed (`valid_to`);
4. set `absorbed.merged_into_id = kept.id`;
5. rewrote the kept row's `orders_count` and `lifetime_value` from ALL its order copies (so the kept customer's figures now include the other business's orders) and filled the kept row's `primary_phone_canonical` and `email` from the absorbed row when the kept row had none;
6. wrote a `customer_merges` row and closed the absorbed row's pending candidates.

It did NOT move conversations, consent events or the consent projection: those keep their own `customer_id` and `business_id`.

Separately (not a merge): `IdentityResolver::byIdentity()` looks phone, email and channel identities up without a business filter, so a conversation, an order or an identity can hang under a customer of another business without any merge. Detection covers those rows too (they are the same leak), labelled "no merge lineage".

## 2. Detection (read-only service + command)

`App\Identity\Quarantine\CrossBusinessDetector::detect(): list<Finding>` - SELECT only. Rules:

| kind | rule | reason code |
|---|---|---|
| merge | `customer_merges` row whose kept and absorbed customers have different `business_id` | `cross_business_merge` |
| merge_pointer | `customers.merged_into_id` pointing at a customer of another business (with or without a merge row) | `cross_business_merged_into` |
| identity | identity id listed in `moved_identity_ids` of a cross-business merge and still under its kept customer | `identity_moved_by_cross_business_merge` |
| identity | identity whose `source_store_id` belongs to a business other than its customer's | `identity_store_other_business` |
| order | `facts_orders.business_id` differs from its customer's business | `order_other_business` |
| conversation | `conversations.business_id` differs from its customer's business | `conversation_other_business` |
| memory | `memories.business_id` set and different from its customer's business | `memory_other_business` |
| memory | memory with `business_id` null, under the kept customer of a cross-business merge, created at or before that merge (it may have come from the absorbed row; the merge recorded no memory ids) | `memory_unscoped_under_cross_business_merge` |
| consent_event | `consent_events.business_id` differs from its customer's business | `consent_other_business` |
| consent_state | `consent_state.business_id` differs from its customer's business | `consent_other_business` |

Each finding carries the subject table and id, the customer it hangs under and that customer's business, the row's own business when it has one, and the cross-business merge it is attributed to when lineage says so (`merge_id`, else null = "no merge lineage").

Command: `php artisan hq:identity:cross-business --dry-run` (the default) lists findings and writes nothing. `--flag` writes quarantine flag rows for findings not yet flagged (idempotent) and audits each one.

## 3. Quarantine records (additive, append-only)

Two new tables, both append-only (Postgres trigger refuses UPDATE and DELETE, model guards too); no existing row is ever modified, deleted or re-pointed; the original `customer_merges` row is never touched.

- `cross_business_quarantines`: `id, kind, subject_table, subject_id, customer_id, customer_business_id, row_business_id (null), merge_id (null), reason, detail jsonb, detected_at, flagged_by (null = system), created_at`; unique `(subject_table, subject_id, reason)` so flagging twice adds nothing.
- `cross_business_quarantine_reviews`: `id, quarantine_id, decision, note, reviewed_by, reviewed_at, created_at`. A later review is a new row; the latest row is the current decision.

Audit: `identity.quarantine.flagged` per flag row (system actor from the command), `identity.quarantine.reviewed` per review (the person).

## 4. Suppression (in the reader, structural, not dependent on flags)

Flagging alone is insufficient (ruling), so suppression does not wait for the command: `App\Identity\Quarantine\CrossBusinessSuppression` computes, from the live rows and with SELECTs only, which relationships of a customer cross the business boundary, and `Customer360Reader` and `CustomerController::index` apply it. Until a ruled repair exists, nothing lifts suppression, not even a review decision (ruling docs/103 §11 forbids cross-business merges, so no review can make one legitimate on the page).

1. Subject: a merge is followed only within one business. A link to a customer of another business stops at the requested row: the absorbed row's own page shows its own business only.
2. Merges: a cross-business merge row is removed from `observed.merges` and shown only as a held item in a new `quarantine` section (merge id, side kept/absorbed, date, latest review decision). The other customer's id, name and business never leave the server.
3. Identities and identity links: those moved by a cross-business merge and those whose source store belongs to another business are not shown.
4. Memory and beliefs: memories of another business or another business's brand are not shown; null-business memories created at or before a cross-business merge on the kept side are withheld.
5. Phone and email: withheld when equal to the absorbed customer's value under a cross-business merge (the merge may have copied them; HQ cannot tell).
6. Orders count and lifetime value: on a customer touched by a cross-business merge, derived from this business's order copies only (SELECT), never the stored figures the merge rewrote.
7. Orders, conversations, consent: already bound to the customer AND its business (docs/109); unchanged.
8. Customer list: phone, email, count and value masked the same way; an absorbed row whose merge crossed a business is listed in its own business (it no longer disappears behind a merge it should never have had); merge candidates are shown only for pairs of one business.

The section `quarantine` says what kinds are withheld (`phone`, `email`, `aggregates`, `identities`, `memories`) and nothing about how many or whose.

## 5. Human review (audited, permissioned)

- Permission `hq.customers.quarantine.review` (new, additive in `RolesAndPermissionsSeeder`; owner and operator through `*`).
- `GET /customers/quarantine` (behind `hq.customers.quarantine.review`): the flag rows the viewer's business scope allows (both businesses of a row must be readable), with the latest review.
- `POST /customers/quarantine/{quarantine}/review`: decision one of `confirmed_wrong` (the relationship should not exist), `needs_repair` (it should be repaired by the future un-merge), `disputed` (the reviewer believes it is legitimate; the note must explain; it stays suppressed and goes to a ruling, since §11 forbids it). A note is required. Writes one review row and one audit row; changes nothing else.

## 6. Repair design (NOT built; for a ruling)

An un-merge that preserves lineage would, in one transaction and per reviewed `needs_repair` merge: create no new customer (the absorbed row still exists); re-point `moved_identity_ids` still under the kept row back to the absorbed row; re-point orders whose `business_id` is the absorbed row's business back to it; re-point memories whose business is the absorbed business (null-business ones need a person's choice per row); clear `absorbed.merged_into_id`; recompute both rows' figures from their own business's copies; leave the kept row's possibly copied phone/email for a person to correct; set `customer_merges.undone_at` (the only column the original design reserved for this; whether setting it counts as "modifying the original row" is for the ruling, the alternative is a separate `customer_merge_reversals` row); write a repair record linking the quarantine rows, the review, and the before/after ids; audit `customer.unmerged`. Questions: who may run it (a second permission or a two-person rule), whether it needs an approval, and how null-business memories are assigned.

## 7. Tests

Planted historical merge (guard bypassed by writing the rows the old merge wrote) is detected with each rule; Customer 360 and the list for business A show nothing of B; flag writes are audited and idempotent; detection and reads write nothing (table snapshot); production-like data with no cross-business rows yields zero findings; review route permission, scope and audit; append-only refusal.

## 8. Non-goals

No automatic undo, no deletion, no re-pointing, no activation, no change to production.
