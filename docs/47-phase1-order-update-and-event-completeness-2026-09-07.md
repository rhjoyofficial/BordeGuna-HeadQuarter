# 47 - Order update and event completeness (7 September 2026)

> **STATUS: COMPLETE AND VERIFIED ON PRODUCTION.** HQ `184d133`, LaLaDia `53cb7015`, Bionic
> `4b346adf`, all live. A real `order.updated` was carried end to end and the integrity checker went
> from 1 discrepancy to **0**. Section H is what remains, and none of it is this milestone.

---

## A. Root cause: the shop never says an order changed

`docs/46 §M.1` recorded the symptom - `LLD-20260906-JRQXXJLN` holding lines that summed to 999
against a subtotal of 1239 - and attributed it to reconciliation being unable to see order items.
That was true but incomplete. Reading the shop settled it:

**`OrderEditService::applyEdit()` dispatches no event at all.**

The shop has seven webhook events. `order.created` fires once and describes the order as placed.
`order.status_changed` describes a status move and carries no lines. Between them there is nothing
for the case that happens most: an admin edits an order after it was placed. So HQ was not merely
unable to *repair* stale lines - it was never told they had gone stale.

The two repair routes both fail on their own terms:

- **Reconciliation** reads `/api/v1/admin/orders`, and `OrderListQuery` does `withCount('items')`
  - items are counted, never loaded - so the list carries totals without lines. It can correct an
  order's money and never its contents.
- **The admin show endpoint** does return items, and calls `$order->markAdminViewed()`, which stamps
  `admin_viewed_at` on the shop's own order. A read that writes to the source system is not a read,
  and using it would have HQ silently marking real orders as seen in Joy's workspace.

Hence a new event. It is the only way HQ can hold current lines without writing to the shop.

---

## B. The contract, taken from the shop rather than invented

Verified against the running code, not designed from HQ's wishes:

| question | answer from the shop |
| --- | --- |
| how is an update detected | `OrderEditService::applyEdit()`, the single path behind `PUT /orders/{order}` |
| what does it return | the fresh `Order` with `items`, `zone`, `shippingAddress` |
| are full items available | yes - `order_items` carries `sku_snapshot`, `product_name_snapshot`, `quantity`, `unit_price`, `total_price`, `variant_id`, `combo_id` |
| totals available | yes - `subtotal`, `discount_total`, `shipping_cost`, `grand_total` on the order |
| how is channel represented | `orders.source`, written `checkout` / `landing` / `admin` |
| how is district represented | **`order_addresses.district` and `.thana`**, added 2 Sep 2026 for courier routing - distinct from the `city`/`area` the customer typed |
| is it emitted for all edits | it will be: the event is dispatched inside `applyEdit`, so every caller gets it |

⚠ The district finding matters. HQ had been mapping `shipping_address.district ?? .city`, and the
shop has *both*, meaning different things: `city`/`area` is what the customer typed, `district`/
`thana` is what the order actually ships by. The new payload sends all four, and HQ's existing
fallback order already prefers the courier pair.

### B.1 Bionic

Bionic is the same codebase - the two shops are API-identical and Bionic receives shop changes by
`git merge upstream/main`. It therefore needs the same change and does not yet have it. Section H.

---

## C. The event

`order.updated`, carrying **full current state, not a delta**.

A delta would be smaller and would be wrong: deltas only reconstruct truth if every one of them
arrives, exactly once, in order, and webhook delivery guarantees none of the three. A full-state
payload is correct on its own - the receiver replaces what it holds, and a missed or duplicated
delivery costs nothing.

Payload: `order_number`, `order_status`, `payment_status`, `payment_method`, **`source`**,
`subtotal`, `discount_total`, `shipping_cost`, `grand_total`, `coupon_code`, customer name/phone/
email, `shipping_zone`, **`shipping_address` object** (`address_line`, `area`, `city`, `district`,
`thana`, `postal_code`), `items_count`, `items[]`, `placed_at`, `updated_at`.

Each item: `sku`, `name`, `variant_id`, `combo_id`, `quantity`, **`unit_price`**, **`total`** - the
same key names the admin API uses, so a consumer need not know which of the shop's two mouths it is
listening to. (`order.created` still sends `price`; HQ tolerates both since `e2f18c5`.)

`shipping_address` is **null** when the order has no address, rather than an object of nulls, so
"no address" and "an address with empty fields" stay different facts.

### C.1 `order.created` gains the two fields it never had

Same release: `source` and the same `shipping_address` object are added to
`DispatchOrderCreatedWebhook`. The flat `address` string stays, so nothing already reading it
breaks. This is what closes the gap where a live order had NULL channel and district in HQ until a
reconciliation pass happened to run.

---

## D. Envelope and security: unchanged, deliberately

`order.updated` goes through `SendWebhookJob` → `WebhookService` like every other event, so it
inherits the delivery id, `occurred_at`, schema version, store routing, HMAC over the raw body, the
timestamp window, dedupe on the delivery id, the existing queue with its retry/backoff, HQ's inbox
and the audit trail. **No second signing mechanism, no new authentication path, no secret handling
changed.** The only additions are one entry in `AdminWebhookController::EVENTS` and one in HQ's
`ACCEPTED_EVENTS`.

---

## E. HQ side - released

Commits `8fd23b0`, `1804a7f`, `7d73803`. CI **113 passed / 598 assertions**.

**`order.updated` routes to the same handler as `order.created`**, because both are full state.

**Event ordering.** Delivery order is not business event order: a retried or delayed `order.updated`
can arrive after a newer one. `synced_at` cannot arbitrate - it records when HQ *wrote*, not when the
shop *meant*. So `facts_orders` gains **`last_event_at`**, the `occurred_at` of the newest full-state
event applied, and an older full-state payload is refused rather than allowed to overwrite good data.

Reconciliation passes **no** timestamp and is always applied: it has just read the shop's current
truth, so it cannot be stale by definition. That asymmetry is the whole ordering strategy.

Status events keep their existing `STATUS_RANK` guard, so a late `order.status_changed` cannot pull
a delivered order backwards. The two mechanisms are independent and both required.

**Omitted vs null vs zero.** `??` treats an absent key and an explicit null identically, and in a
full-state payload those mean opposite things. A new `pick()` helper distinguishes them:

| payload | meaning | HQ does |
| --- | --- | --- |
| key absent | the shop is not talking about this field | keeps what it holds |
| key present, `null` | the shop asserts emptiness | stores null |
| key present, `0` | zero is a value | stores 0 |

Applied to `source`, `payment_status`, `payment_method`, `district`, `thana`, `campaign_ref`. Money
keeps `?? 0` because a null amount is meaningless.

### E.1 HQ tests - eight, from the shop's shape

`tests/Feature/Facts/OrderUpdatedTest.php`. Written from the listener's payload (`items[].price`,
`quantity`, a `shipping_address` object), not from HQ's mapper - the discipline that was missing when
every line was stored at 0.00.

- **reproduces the exact production discrepancy** (lines 999 vs subtotal 1239) and proves
  `order.updated` clears it, with the integrity checker reporting 0 discrepancies afterwards;
- carries `source` and `district`, which `order.created` could not;
- idempotent - the same update twice changes nothing and duplicates no customer or identity;
- **refuses an older full-state event** so a delayed delivery cannot undo a newer one;
- accepts reconciliation with no timestamp even after a newer event;
- distinguishes omitted from explicit-null from zero;
- accepted through the signed endpoint, and a redelivery answers `duplicate: true` with one event;
- creates the fact when `order.updated` arrives for an order HQ never saw created.

---

## F. Shop side - built, tested and pushed on both shops

The first build of this lived in a scratch clone that no longer exists, so it was rebuilt from the
contract in section B against the shops' own trees, both of which are now idle and clean.

**LaLaDia `main` = `53cb7015`, 1377 passed / 1 skipped. Bionic `relaunch` = `4b346adf`, 1349 passed
/ 29 skipped** - authored in LaLaDia and merged down, which is the multi-shop discipline working.

| file | change |
| --- | --- |
| `app/Events/OrderUpdated.php` | new, `ShouldDispatchAfterCommit` |
| `app/Listeners/DispatchOrderUpdatedWebhook.php` | new, full-state payload |
| `app/Domains/Order/Services/OrderEditService.php` | dispatches `OrderUpdated` inside `applyEdit`'s transaction |
| `app/Domains/Webhook/Controllers/AdminWebhookController.php` | `order.updated` added to `EVENTS` |
| `app/Listeners/DispatchOrderCreatedWebhook.php` | adds `source` and the `shipping_address` object |
| `app/Domains/Webhook/Support/OrderWebhookPayload.php` | new - the one place the wire shape is built |
| `tests/Feature/OrderUpdatedWebhookTest.php` | new, 7 tests / 37 assertions |

`OrderWebhookPayload` exists because `order.created` and `order.updated` describe the same thing at
two moments. Two copies of that shape would drift, and a consumer that has to know which of the
shop's two mouths it is listening to is a consumer that will eventually be told two different
stories about one order.

Three of the seven tests were **watched failing** against the unmodified shop - the EVENTS entry
removed, the dispatch commented out, `source` deleted from the created payload - so they are known
to bite rather than assumed to.

The dispatch sits in the service rather than the controller so every caller of `applyEdit` gets it,
including any future one, and inside the transaction because the event is
`ShouldDispatchAfterCommit` - nothing is delivered if the edit rolls back.

Shop tests assert the payload the listener **builds**, because that payload is a contract with
another system and the contract is what breaks. That is the half of the 0.00 lesson which belongs in
the shop's repository rather than HQ's.

---

## G. Historical dead-event recovery - complete, and provably so

The six dead events were three status steps per order:

| order | steps | occurred |
| --- | --- | --- |
| `LLD-20260902-HBCJ2E5DI8` | processing → shipped → delivered | 7 Sep 09:11:05-06 |
| `LLD-20260902-U2RYHL1K92` | processing → shipped → delivered | 7 Sep 09:11:06-07 |

Both orders were placed **2 September**, before HQ had a subscriber, so no fact existed and
`applyStatusEvent` parked them until the retries ran out.

Recovered earlier today: a 200-hour reconciliation created the missing facts (and seven others), then
`hq:events:replay --type=order.status_changed --hours=30 --apply` replayed 16 events, none failed.

**The evidence that it worked is not merely that both facts now read `delivered`** - reconciliation
alone could have produced that. It is that each fact's `last_event_id` is now **its own final
`delivered` event id**: `4f1e1ed7…` for HBCJ2E5DI8 and `3f9d8134…` for U2RYHL1K92. Those exact events
were applied. **No status change remains missing, and no further replay is needed.**

✅ **Closed the same day (`b24582d`, merged as `257c0f0`).** The six rows read `dead`, which means
"this effect never landed" - false the moment a replay lands it. A successful replay now promotes
`dead` and `failed` rows to `processed` and stamps `processed_at`, while `last_error` and `attempts`
are kept verbatim: the row still records that delivery failed and why, it simply no longer claims
the failure is outstanding. An already-`processed` event is untouched, because replaying one is a
repair rather than a resolution, and a replay that throws changes no status at all. Two tests, both
**watched failing on CI against the unfixed command** before the fix was written.

⚠ The six historical rows are not retro-fixed by this: they were replayed before the change existed.
Marking them by hand would be typing a status rather than earning one. They stay `dead` and inert,
and this section is the record of why.

---

## G.1 ⚠⚠ The step that would have made the whole release do nothing

**Deploying the emitter does not create the subscription.** `webhooks` rows are per event, and both
shops carried exactly six HQ rows - `order.created`, `order.status_changed`, `order.payment_updated`,
`shipment.status_updated`, `customer.registered`, `inventory.low`. **There was no `order.updated`
row on either shop**, so every edit would have built a correct payload, queued a correct job, and
sent it precisely nowhere - with no error anywhere, because a webhook with no subscriber is not a
failure, it is a no-op.

Added on both by **replicating the existing HQ `order.created` row** and changing only the event
name, so the URL and the shared secret are the same bytes rather than a retyped guess (LaLaDia
id 9, Bionic id 7, both active, secret equality asserted in the same statement that created them).

**The general rule: a new event needs three things, not two - the emitter, the receiver's accept
list, and a subscription row per shop. Two of the three fail silently.**

---

## G.2 The end-to-end proof, on production

Rather than edit a real customer's order to test a feature, the shop was asked to **announce** the
state it already held for `LLD-20260906-JRQXXJLN` - the same listener payload, the same signed job,
touching nothing.

The shop's own answer: `lines=1239 subtotal=1239 count=2 source=landing`. **HQ had been holding one
line worth 999 for an order the shop says has two.** After delivery:

| field | HQ before | HQ after |
| --- | --- | --- |
| lines | 1 line, 999.00 | **2 lines, 1239.00** |
| source | *(null)* | **landing** |
| district / thana | *(null)* | **Narayanganj / Sonargaon** |
| `last_event_at` | *(null)* | set |

`hq:facts:check`: **16 ok, 0 discrepancies** - from 1. The event row reads `order.updated` /
`processed`. Both shops logged **zero errors**, and both storefronts render.

⭐ The district and thana are the section B finding paying for itself: HQ had never held them for
this order, because `order.created` did not send them and reconciliation reads the customer's
`city`/`area` rather than the courier pair.

---

## H. What is NOT done, and why

**Nothing in this milestone.** What follows is adjacent and belongs to somebody else's decision.

**The old blocker is gone.** This section previously said neither shop was deployed. Both now are,
on Joy's explicit go: LaLaDia by `~/laladia-src` + `git archive` (maintenance mode, ~40 seconds,
permissions verified 750/755/644 afterwards), Bionic by `git merge --ff-only` in place with its ads
diff asserted empty first. **"Nothing to migrate" on both**, so rollback is `git checkout` of the
previous commit rather than a restore. Pre-deploy backups taken and `gzip -t` verified:
`lld-code-pre-0907c.tar.gz` 148M, `bnc-code-pre-0907c.tar.gz` 170M.

**Not yet observed in the wild: an `order.updated` produced by an actual admin edit.** §G.2 proved
every link of the chain except the trigger, and the trigger is covered by three tests watched
failing. The first real edit will confirm it; nothing needs doing to make that happen.

**A leftover was cleaned up and a secret was not.** `hq-check.php`, a nine-line tinker script from
this morning's credential work whose own usage line said to remove it, was still sitting in the
Bionic repo root and is now deleted. ⛔ **`~/hq-bionic-credentials.txt` (mode 600) still exists.**
The three credentials it holds are already in HQ and active, so the file has served its purpose -
**Joy should delete it.** Not deleted here, because destroying somebody's secrets file is not a
tidy-up.

**A separate gap found while testing, and deliberately left alone.**
`AdminOrderCreationService` writes `address_line`, `area`, `city` and `postal_code` and **drops the
`district` and `thana` it is posted** - the admin order form sends them and the service ignores
them. So an order taken over the phone reaches the courier with no routing pair, which is precisely
what the 31 August work made the storefront checkout require. It is a real defect in the shop's own
admin form, it is not what this milestone is about, and fixing it changes a screen. Recorded here
and in the shop's commit message rather than smuggled into an event release.
