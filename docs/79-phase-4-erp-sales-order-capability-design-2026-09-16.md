# 79 - Phase 4 design: the ERP Sales Order capability, and `take_order` as one business capability (16 September 2026)

**Status: DESIGN, stream D of the full-speed milestone (`docs/78` §B.4), written on ChatGPT's
instruction of 16 September (points 6 and 7, verbatim in `docs/78` §A). Design only. No tool is
registered, no ERP endpoint exists, no order is written by HQ, and nothing here touches a prompt,
the runner, the detectors or the gateway's decisions, so the executive stamp and gate 11 are
untouched. The decision it rests on is ADR-046 (proposed).**

**Read first:** `docs/78` §A points 6 and 7 (the instruction), `docs/60` (the invariants and the
boundaries every design must keep), `docs/15` (the Commerce Platform Interface and the availability
policy), `docs/14` (identity and the customer AI rules), `docs/10` and `docs/12` (the `take_order`
skill and the `create_order` policy as first written), ADR-029, ADR-031, ADR-034, ADR-011, ADR-015.

An analogy first. A shop window shows what a business sells; the order book is where a promise to a
customer is written down. Today the group has three shop windows and each keeps its own order book,
and HQ reads all three. Joy's direction is one order book, the ERP, with every shop window showing
what is in it. HQ then learns one way of writing an order, `take_order`, and never learns one per
window.

## A. The instruction and Joy's direction

ChatGPT's point 6 (`docs/78` §A): the ERP is the canonical system of record for future order
creation once its website modules exist; the capability is `take_order`, never
`take_order_via_LaLaDia_checkout` or `take_order_via_Bionic_checkout`; the flow is conversation,
then order intent and a structured order, then validation, then the ERP Sales Order capability, then
the ERP, then a shop or channel projection; seventeen named items must be covered; no autonomous
order writes yet; an ADR and a split of the Commerce Platform Interface into read-side pieces that
stay and order-write responsibilities that move. Point 7: provider neutrality is kept; the model
reasons about a business capability, the implementation stays behind the Capability Registry and
Tool Gateway, and no agent knows whether it is a direct ERP API, an internal service, an MCP adapter
or another protocol.

Joy, 15 September: once the ERP's website and e-commerce modules exist, the shops (LaLaDia, Bionic,
Econic) operate through the ERP, and HQ integrates only with the ERP. The per-shop adapters and
their tokens are a bridge. That is ADR-015's decision D1 (6 September) carried to its end: the
adapters were declared transitional then, and they now have a named successor.

Two lines of the roadmap are superseded. `docs/30`'s Phase 4 row says "`take_order` skill through
the shop checkout API with value-gated approval". Not through the shop checkout, by this document;
and not value-gated, by Joy's V.1 decision that every R4 and R5 action requires approval with no
value threshold (`RiskTier::baseline()` in `apps/hq/app/Gateway/Descriptor/RiskTier.php`).
`docs/12`'s `order.auto_create_max_bdt` rule and `docs/10`'s "policy: value threshold" belong to the
day autonomy is measured, not to this design.

## B. What exists today

### B.1 The read side, from the shops

HQ's Commerce Platform Interface is `App\Commerce\Contracts\CommercePlatform`
(`apps/hq/app/Commerce/Contracts/CommercePlatform.php`): `searchProducts`, `getProduct`,
`getVariant`, `getCombos`, `getAvailability`, `previewOrder`, `createOrder`, `getOrder`,
`findOrdersByPhone`, `listRecentOrders`, `reconcileOrder`, `probe`. Its docblock carries the rules
that matter here: prices are always computed by the store, every write carries an idempotency key,
and an uncertain outcome is reconciled before a retry. One adapter implements it,
`LaravelShopAdapter` (`apps/hq/app/Commerce/Adapters/LaravelShopAdapter.php`), for the LaLaDia shop
and its Bionic fork; `CommerceRegistry::SUPPORTED_ADAPTERS` is `['laladia', 'bionic']`, and the
registry builds an adapter per call with whichever of the two credentials (`shop_assistant_key`,
`shop_admin_token`) the gateway opened in the vault. `Capabilities::laravelShop()` records what the
shop can do: search, product, variant, combos, geo, preview, `create_order`, `idempotent_create`
through the shop's `checkout_token`; no availability endpoint, no promotions endpoint, no
`update_order`; order lookup and listing only with the admin token.

The DTOs the adapter speaks: `Product` (id, slug, name, isActive, status, basePrice, variants),
`Variant` (id, title, a nullable `sku`, price, finalPrice, stock, availableStock, isActive),
`Availability` (variantId, `orderable`, `reason`, stockHint, productStatus), `OrderDraft` (customer,
address, lines, paymentMethod `cod`, couponCode, notes, source `hq`, conversationRef, landingSlug),
`DraftLine` (quantity, variantId, comboId), `DraftCustomer` (name, phone, email), `DraftAddress`
(line, district, thana, area, city, zoneId), `OrderQuote`, `OrderResult` (`confirmed`, `uncertain`,
`refused`), `IdempotencyKey` (a sha256 over named parts, 64 characters for the shop's `min:32`
checkout-token rule) and `Order`. Failures are five typed exceptions: `Refused`, `Unavailable`,
`Uncertain`, `Unsupported`, `ValidationFailed`.

What is registered as a tool is narrower than the interface. `apps/hq/config/hq.php` registers, for
commerce, `commerce.list_orders` (R2), `commerce.reconcile_facts` (R3, HQ read models only) and
`commerce.check_fact_integrity` (R1), plus `facts.query_orders` and `facts.snapshot_freshness`.
`search_products`, `get_product`, `get_variant` and `get_availability` are planned in `docs/48` D.6
and exist as adapter methods, but no descriptor wraps them today. `previewOrder`, `createOrder` and
`reconcileOrder` exist as adapter code and are called by nothing under `app/Tools`.

### B.2 The read side, from the ERP

`App\Erp\ErpConnector` (`apps/hq/app/Erp/ErpConnector.php`) is one connector per ERP business and
token, opened through `ErpRegistry` from the `erp_api_token` credential. It reads `businesses`,
`overview`, `stock`, `stock/low`, `products`, `products/{sku}`, `customers` (with a phone filter),
`customers/{id}`, `tickets`, `approvals/pending`, `reports` and `reports/{key}/run`, all under
`/api/hq/v1/{business}/`, and it has exactly one write, `openTicket`, which sends an
`idempotency_key` the ERP stores as `external_ref` and replays. `ErpContract` refuses a payload with
no `data` key rather than storing an empty answer as good news.

On the ERP side (`D:\WORK\BorDeGuna\bordeguna-erp`, `routes/api.php`), the HQ API is exactly that
list, guarded by `HqApiContext` (the token must hold `hq:business:{id}`; the tenant scope is
entered from the token, never from the caller). `HqApiToken`
(`app/Console/Commands/HqApiToken.php`) issues tokens only with abilities from `READ_ABILITIES`, a
read-only list plus `crm.tickets.manage`. There is no sales-order endpoint for HQ and no ability that
would let one be called. That is the honest starting point: HQ cannot write an order to the ERP
today, by construction, and the gap is on both sides.

### B.3 What the ERP already has for orders

The ERP has a real sales order. `App\Modules\Sales\Models\SalesOrder`
(`app/Modules/Sales/Models/SalesOrder.php`) carries `business_id`, `customer_id`, a nullable
`warehouse_id`, `store_id`, `channel`, `order_number` (`SO-FY2026-0001`, sequential per business),
`shop_reference` (the customer-facing reference, unique across the group), `ordered_at`,
`expected_date`, `delivery_charge`, `order_discount`, `coupon_code`, `delivery_promise_min_hours`,
`delivery_promise_max_hours`, `payment_method`, `status`, `note`, `created_by`. Lines
(`SalesOrderLine`) carry `product_id`, `quantity_ordered` and `quantity_delivered` (decimal 15,4),
`unit_price` frozen at order time, `line_discount`, `price_note` (why it is this price) and
`bundle_label`. The destination is its own row, `SalesOrderAddress`: recipient name and phone,
district, thana, area, a derived postcode, address line, landmark. Statuses are `open`,
`partially_delivered`, `delivered`, `closed`, `cancelled` (`SalesOrderStatus`), beside an approval
request (`Approvable`): goods leave only against an approved order (`isDeliverable()`). The model's
own docblock is the rule that makes this design safe: a sales order moves no stock and posts no
journal, ever.

`CreateSalesOrder` (`app/Modules/Sales/Actions/CreateSalesOrder.php`) is the one way an order is
written: it refuses an inactive customer, a product of another business, a service product, an
empty order, a negative or oversized discount; prices every line through `ResolveSellingPrice` (or
records a manual override, marked as such); freezes the delivery charge, coupon, promise window and
payment method from a `SalesOrderOrigin`; and submits for approval, self-approving when the creator
holds `sales.orders.approve`. `CancelSalesOrder` cancels only an `open` order with nothing
delivered, behind `sales.orders.cancel`. `ApproveSalesOrder` writes `expected_date` from the promise
at confirmation.

The ERP also has a website module in code. `App\Modules\Commerce` holds stores (`Store` with `key`,
`order_prefix`, `price_group_id`, `availability_policy`, the promise window, and `StoreStatus`
draft, live, archived), published products, carts, combos, coupons, shipping zones, SSLCommerz
payments, and `PlaceWebsiteOrder` (`app/Modules/Commerce/Actions/PlaceWebsiteOrder.php`), which
turns a basket into a real sales order through `CreateSalesOrder`: idempotent on
`carts.sales_order_id`, re-checking every line at the last moment, quoting delivery through
`DeliveryQuote`, validating the destination through `SalesOrderDestination`, identifying the
customer by normalised phone, deciding the coupon inside the transaction with the row locked, and
refusing the order if the engine's total differs from what the basket showed. The migration
`2026_09_12_130000` records that on production these tables held zero rows on 12 September. Whether
any ERP store is live on production is not something either repository can show; what HQ's own
`stores` rows show (`adapter` `laladia` or `bionic`, `base_url` the standalone shop) is that HQ
still talks to the standalone shops. So the accurate statement is: the ERP's website module exists
in code, no shop has cut over to it, and the standalone shops still own their orders.

### B.4 The write side in HQ

There is no agent-executable write tool with an external effect (ADR-031: "specified, not
implemented"). The only write an agent may name is `messaging.send_reply`
(`apps/hq/app/Tools/Conversation/SendReplyTool.php`, R3, customer-facing, consent-gated, inert
because `PlatformRegistry` holds no provider). `ApprovalDemoTool` is the R4 tool that proved the
approval path on production with one audit row. A named write at A0 is recorded as a proposal and
runs nothing (`AgentRunner::hasEffect()`); a tool with `agentExecutable: false` is not even in the
agent's catalogue (`AgentToolset::keysFor`), and the gateway refuses it before any approval
(`PolicyEngine` step 2). This is the machinery `take_order` inherits without change.

## C. The canonical capability

One counter, one order book. `take_order` is one business capability. It is never per shop, never
per channel, never per protocol. The agent reasons about it; HQ resolves what stands behind it.

### C.0 The vocabulary

- **OrderIntent**: what a conversation or an operator understood. Product mentions as the customer
  said them (a name, a slug, a SKU), quantities as said, the phone as typed, the address as typed, a
  payment wish, a coupon code as typed, the conversation and the store the thread belongs to. It
  carries provenance `customer` when it came from a thread (ADR-024) and it is never executed. It is
  content.
- **StructuredOrder**: the intent resolved and validated against the ERP: every line an ERP product
  (`erp_product_id`, `sku`) with a quantity in the product's own unit; the customer resolved to an
  ERP customer id or marked new; the destination validated; the phone canonical; the quote the ERP
  returned (goods, line discounts, order discount, delivery charge, agreed total, quoted at); the
  payment method; the store; the logical key. It is built by an HQ `OrderValidator` from the intent,
  ERP reads and one `quote()` call, and it is the payload an approval binds (ADR-018: the stored,
  validated payload is what executes).
- **ErpSalesOrderCapability**: an interface in HQ's own vocabulary, provider neutral:
  `quote(StructuredOrderDraft): OrderQuote`; `create(StructuredOrder, LogicalKey): OrderWriteResult`
  (`confirmed`, `uncertain` or `refused`, with the ERP order number and shop reference);
  `status(OrderRef): OrderStatus`; `cancel(OrderRef, reason, LogicalKey): CancelResult`;
  `modify(OrderRef, StructuredOrder, LogicalKey): ModifyResult`; `reconcile(LogicalKey): ?OrderRef`.
  The first implementation is an `ErpSalesOrderConnector` over the ERP's HQ API; an internal service
  or a protocol adapter would implement the same interface (ADR-029). No tool, prompt or agent names
  the implementation.

The seventeen items follow, each in the ERP's own terms where the ERP already has the answer.

### C.1 Product identity

An ERP product is `products.sku` within a business (`Product::$fillable` in
`app/Modules/Inventory/Models/Product.php`: `sku`, `barcode`, `name`, `type`, `is_active`,
`availability_override`, `lead_time_days`), read by HQ through `products/{sku}`. The StructuredOrder
names a line by `erp_product_id` and `sku`, both, and the ERP must find the same row by both or
refuse. A product must be active and stocked (`CreateSalesOrder` refuses a service: "cannot be
ordered. Invoice it directly"), and, for an order on a store, published there
(`CartRules::refusalFor` checks `store_products.published_at` and a price in force). HQ never orders
by name: a name is a search, a SKU is an identity.

### C.2 Variant and SKU identity

The ERP has no variant concept; a search for the word under `app/Modules/Inventory` and
`app/Modules/Commerce` finds only catalogue-migration helpers. The shops have product plus variant,
and the `Variant` DTO's `sku` is nullable. Until a shop cuts over, a shop variant is a projection
whose `sku` must resolve to an ERP `sku`; a variant with no SKU cannot be ordered through the
capability, and the validator says so rather than guessing. Bundles are the ERP's `StoreCombo`:
`PlaceWebsiteOrder` explodes a bundle into its parts, each a real line at the engine's price with
its share of the saving as `line_discount` and a `bundle_label`. The StructuredOrder therefore names
a bundle by its store combo reference and a quantity, and never computes the split; the ERP does.

### C.3 Quantity

`quantity_ordered` is decimal(15,4) in the product's unit of measure (the products endpoint returns
the unit abbreviation). The StructuredOrder carries a positive decimal string in that unit and HQ
converts nothing. A website-channel order keeps the shop's ceiling (`CartRules::MAX_QUANTITY` is
99). `CreateSalesOrder` drops a zero-quantity line; the validator refuses it earlier with a sentence.

### C.4 Pricing source

One engine, `ResolveSellingPrice` (`app/Modules/Sales/Actions/ResolveSellingPrice.php`): the most
specific rule in force wins (a price for this customer, then for their group, then the standard
price; within a tier the highest quantity break reached; RETAIL when no group), and every future
kind of price is a row, not a branch. A website order is priced as the channel, at the store's price
group and not the buyer's (`SalesOrderOrigin::website` sets `pricesAsChannel: true`; ERP ADR-005).
HQ computes no price: `quote()` returns the ERP's figures and `create()` must land exactly on them,
the way `PlaceWebsiteOrder` refuses with "A price changed while you were ordering" when the engine's
total differs from the basket's. The manual `unit_price` override that `CreateSalesOrder` accepts is
a permanent human action and has no field in the StructuredOrder: final pricing is on the HUMAN_ONLY
list (ADR-025).

### C.5 Customer identity

Two customer tables, one rule. HQ's `customers.primary_phone_canonical` and `customer_identities`
(whose channels include `erp_customer`) are a read model; the ERP's `customers.phone_normalized` is
matched, never made unique (`PhoneIdentity`: "a household shares a number").
`PlaceWebsiteOrder::identify` is the rule the capability keeps: match an active, non-system customer
of the business by normalised phone; never rewrite an existing customer's name, address or price
group; create a new one as Retail with no price group; never the seeded walk-in row. The
StructuredOrder carries the HQ customer id when a thread has one, the ERP customer id when HQ
already links one, and always name, phone and an optional email; the ERP resolves at write time and
answers with the customer id it used; HQ links that id as an `erp_customer` identity. Merging two
people stays a human decision (`docs/14`).

### C.6 Delivery address

The destination is a Sales fact, not a website one (`sales_order_addresses`): recipient name and
phone, district and thana both required, area optional, postcode derived server-side and never
posted, address line, landmark. `SalesOrderDestination::fromInput` is the one validator, against
`App\Support\BdGeo` (64 districts, thanas contained in their district, areas in their thana, aliases
such as Chittagong to Chattogram). HQ's `DraftAddress` maps onto it; its `zoneId` and `city` are
shop-specific and do not travel. The recipient may differ from the buyer (a gift). HQ never invents
a postcode. Which dataset is the authority is a business question (§H).

### C.7 Phone validation

Bangladesh mobile numbers are eleven digits starting `01`. HQ's `PhoneCanonical::normalize`
(`apps/hq/app/Identity/PhoneCanonical.php`) accepts `+880 1711-000000`, `8801711000000`,
`01711000000` and a ten-digit `1711000000` that lost its zero, and returns `01XXXXXXXXX` only when
it matches `^01[3-9]\d{8}$`; the ERP's `PhoneIdentity::normalize` produces the same canonical form.
The StructuredOrder carries both `phone_typed`, kept verbatim (the ERP never rewrites what a human
typed), and `phone_canonical`, which must validate under `PhoneCanonical` before any ERP call; the
recipient phone follows the same rule. A number that fails is a refusal with a sentence, not a
guess. A number shared by a household matches and never blocks.

### C.8 Shipping

The shop prices delivery from the district alone: `DeliveryQuote::for(store, district)` returns the
mapped zone's charge, else the store's default zone, and a store with neither cannot quote and
refuses the order ("We cannot work out a delivery charge"). The charge is frozen on
`sales_orders.delivery_charge`, the promise window on the order, and `expected_date` is written at
approval. HQ never quotes shipping: `quote()` returns the charge and the promise sentence
(`Store::promiseSentence`), the agent may repeat that sentence and nothing more (`docs/14`: never a
date the courier has not confirmed), and couriers stay outside HQ (`docs/15`: HQ never books
parcels).

### C.9 Discounts and coupons

A coupon is an order-level discount posting to 4210 (`sales_orders.order_discount`, with
`coupon_code` kept as a word); a bundle's saving is a line discount. `CouponPricing::quote` is the
only authority: minimum spend against the whole basket, scope, per-customer and total limits counted
from orders (a cancelled order does not use a coupon up), a gift as a real line, free delivery.
`PlaceWebsiteOrder` locks the coupon row inside the transaction and refuses the order if the code no
longer applies. The StructuredOrder carries the code as typed and nothing else: no discount amount,
no percentage. There is no ad-hoc discount through HQ; a negotiated price is a person's ERP action.

### C.10 Payment and COD

The payment method is a recorded choice, not a payment: `PlaceWebsiteOrder` "does not take a
payment", and only `cod` and `sslcommerz` exist (Joy, 13 September, in that class). For an order
taken in conversation, v1 is COD; an online payment is a link the customer follows, and such an
order waits for its payment before anybody is asked to confirm it (`awaitingPayment`, cancelled if
unpaid after 24 hours). HQ moves no money, records no receipt and issues no refund (permanent
HUMAN_ONLY, ADR-025). Whether COD is the default the assistant offers is a business question (§H).

### C.11 Order totals

Composed from the stored agreement, never re-resolved: `SalesOrder::goodsTotal()` sums the lines net
of line discounts, each rounded to the paisa first; `agreedTotal()` is goods less the order discount
plus the delivery charge. HQ shows the ERP's numbers and computes none. On read-back, HQ's fact must
equal the ERP's totals, and `FactsIntegrityChecker` (`apps/hq/app/Facts/FactsIntegrityChecker.php`)
already holds the arithmetic (line quantity times unit price equals line total; lines sum to
subtotal; subtotal plus shipping less discount equals gross) that an HQ-written order is checked
against.

### C.12 Stock and availability confirmation

The policy is status-based (`docs/15`, ERP ADR-004): one stock number, an availability policy per
channel (`AvailabilityPolicy::Strict` for the till, `StatusBased` for the web), a product override
that only narrows, and `Availability::decide` answering `sellable`, a reason and `madeToOrder`,
reserving nothing. `quote()` returns that verdict per line; the agent uses `sellable`, never a
quantity (`docs/14`), and a made-to-order line is said as "made to order" with the store's one
promise. In v1 an HQ order always names a store, so the store's policy applies; `CreateSalesOrder`
itself checks no availability, because a sales order is a promise. Low stock remains an inventory
signal (`erp.low_stock`), not an order refusal.

### C.13 Idempotency

The logical key is `IdempotencyKey::for(business, store, conversation_uuid, hash of the
StructuredOrder)` (`docs/15`: conversation plus draft hash). The descriptor declares
`idempotencyKeyFrom`, and `IdempotencyKeyFactory` derives the gateway key from those paths within
the run's identity unless the caller supplies the logical key explicitly, which always replays. The
connector sends the key as an `Idempotency-Key` header and as `idempotency_key` in the body, the
shape the ERP's ticket endpoint already stores as `external_ref` and replays with `replayed: true`.
The ERP must store the key uniquely per business and return the same order for the same key, as
`carts.sales_order_id` already does for a basket. ADR-011 governs the rest: at-least-once execution;
on `uncertain`, `Tool::reconcile()` looks the order up by logical key before ever creating again;
when lookup is impossible the call stays `uncertain`, a `side_effect.uncertain` event tells a
person, and nothing is re-sent. Nothing in this document says exactly once.

### C.14 Order status

The ERP's statuses (`open`, `partially_delivered`, `delivered`, `closed`, `cancelled`) sit beside
the approval state (pending, approved, rejected) and, for online payment, the waiting state before
an approval request exists. `status()` reads the ERP live, because order status is never memory
(`docs/48` I.2, the reason `ListOrdersTool` exists beside `facts.query_orders`), and
`facts_orders.status` is a copy with an age. The status DTO carries the ERP status, the approval
status, the payment method, `expected_date` and the promise sentence; a customer-facing sentence is
composed from the tool output only.

### C.15 Cancellation and modification

`cancel()` maps to `CancelSalesOrder`: only an `open` order with nothing delivered, behind
`sales.orders.cancel` (Owner and Manager only: "erasing a commitment is a management decision"). It
is the compensating tool of `take_order`, itself R4 and approval-required, and the ERP refuses it
once anything has left, at which point compensation ends and a return begins (`RecordSalesReturn`, a
human action, because money may move). The ERP has no modify action. `modify()` in v1 is cancel
then create under a new logical key that names the one it supersedes, only while the order is open
and nothing is delivered; an order paid online is not modified through HQ, because the difference is
a refund. Both remain proposals for a person until their own gate.

### C.16 Failure and compensation

ADR-031, step by step. Precondition: the quote is fresh, every line sellable and priced, the phone
canonical, the destination valid, the store live. Proposal: the StructuredOrder. Policy: R4, so
approval (V.1). Authorization: the operator's permission, business scope, autonomy. Execution: under
the logical key, once. Postcondition: `status()` read back by order number and by logical key, and
the totals compared to the quote; a 200 with an order number is not the postcondition, the read-back
is. Reconciliation: the `facts_orders` row is written from the read-back, never from the POST
response. Compensation: `cancel()`, under its own approval. Failure classes follow the connector's:
`ValidationFailed` (422: a price changed, a coupon refused, an unknown thana) is a sentence for the
operator and is never retried; `Unavailable` before the request left may be retried; `Uncertain`
after it left is reconciled first; `Unsupported` (401, 403: the token lacks the ability) is a
readiness state, not a refusal (`docs/78` point 13). An unverified effect is `uncertain`, never
`succeeded`.

### C.17 ERP and commerce reconciliation

`facts_orders` (`apps/hq/database/migrations/2026_09_05_200000_create_identity_and_facts_tables.php`,
unique on store and `order_ref`; `source` already lists `hq_ai`; `lines`, totals, `last_event_id`,
`synced_at`) is filled today by the shop webhooks and by `FactsReconciler` re-reading the shop's
admin list, and checked by `FactsIntegrityChecker`. With the ERP canonical, an HQ-written order's
`order_ref` is the ERP `shop_reference` (what the customer quotes), with the ERP `order_number` kept
beside it; `FactsReconciler` gains an ERP path over a proposed listing endpoint (§F); and a new check
closes the loop ADR-031 asks for: every logical key HQ recorded as `confirmed` exists at the ERP
exactly once, every `uncertain` key is resolved or escalated, and every ERP order with an HQ source
has an HQ record. Discrepancies are reported and never repaired, as the checker's docblock insists.

## D. The descriptors, as they would be declared

`commerce.take_order`, version `1.0.0`: `riskTier: RiskTier::R4` (the tier's own definition, "an
external side effect that can be corrected without loss: a draft, a pending order, a ticket", and a
sales order moves no stock and posts no journal); `permission: 'hq.tools.commerce.write'` (seeded in
`RolesAndPermissionsSeeder`, reserved by `ReconcileShopFactsTool`'s docblock "for a tool that
changes something AT the shop"); `auditAction: 'tool.commerce_take_order'`; `scope:
ToolScope::Business`, `scopeFrom: 'business:args.business_slug'`; `readWrite: ReadWrite::Write`;
`agentExecutable: false` (a stage, not a decision: `permanentlyHumanOnly` stays false, and
`hq:tools:sync` prints "not yet"); `credentials: [new CredentialRequirement(ErpRegistry::PROVIDER,
'business.slug')]`; `idempotent: true`, `idempotencyKeyFrom: ['business_slug', 'store_slug',
'conversation_uuid', 'order.hash']`, `replayWithinSeconds: 0`; `timeoutSeconds: 30`; `channel:
Channel::Internal` (the ERP is a system of record; no person is contacted by the call);
`consentRequired: null` (an operator-initiated order is not a customer message, and the constructor
only demands a consent class for an outbound channel); `customerFacing: true` (a parcel and a
cash-on-delivery demand reach a real person); `monetaryImpact: MonetaryImpact::High` (the arguments
decide the amount, so the highest band, as the enum's docblock requires); `reversibility:
Reversibility::Compensable`, `compensatingTool: 'commerce.cancel_sales_order'`; `dataClass:
DataClass::Personal`; `cardinality: ToolCardinality::OnePerBusiness`; `supportedStores`: only stores
whose adapter is the ERP module, which today is none. `redact` names nothing the ERP needs to write
the order, because the stored approval payload is what executes (ADR-018); the model's view of the
result strips the phone to a boolean, as `ListOrdersTool` does. A `TakeOrderPolicy` class may only
make the decision stricter (`PolicyEngine` step 6): a stale quote, a store not live, no phone.

Baseline outcome: `ActionClass::ApprovalRequired`, from `RiskTier::baseline()`, with no threshold.
Approval-required is a stage (ADR-034); nothing in this document proposes ending it.

`commerce.quote_order`, R2, read, `agentExecutable: true` at the Phase 4 gate (reading is not
acting, ADR-027), the same credential, `idempotencyKeyFrom` the draft, `replayWithinSeconds: 60`.
`commerce.get_order_status`, R2, read, one order by reference. `commerce.cancel_sales_order`, R4,
write, `agentExecutable: false`, `reversibility: Reversibility::Irreversible` (a cancelled order is
re-created, never un-cancelled), `monetaryImpact: High`, `dataClass: Personal`, approval-required.
There is no `commerce.modify_sales_order` in v1.

## E. What stays a read-side adapter, and what moves

Stays, until each shop cuts over (ADR-015 point 5): `searchProducts`, `getProduct`, `getVariant`,
`getCombos`, `getAvailability`, `getOrder`, `findOrdersByPhone`, `listRecentOrders` and `probe` on
`LaravelShopAdapter`; the shop webhooks into `facts_orders`; `FactsReconciler` over the admin list;
`commerce.list_orders`; the `Capabilities` flags; the `CommerceRegistry` and its two credential
providers. When a shop moves onto the ERP module, an `ErpCommerceAdapter` satisfies the same read
methods (ADR-015), and the ERP is then read for catalogue, availability and orders alike.

Moves behind the ERP capability, all of it: `previewOrder`, `createOrder`, `reconcileOrder`,
`OrderDraft::toShopPayload` and the `checkout_token`. They stay on the interface only because the
interface predates this decision; no tool ever wraps them, no job ever calls them, and each is
deleted at its shop's cutover. There is no `take_order_via_laladia` and no `take_order_via_bionic`,
not as a bridge, not as a test, not for one launch: a capability that names a vendor is rewritten
every time the vendor changes (ADR-029 on `publish_content`), and three vendors are about to change.

## F. The transition, and the ERP contract

Today the standalone shops own their orders and HQ reads them; the ERP has the order book and the
website module in code, and no shop on it; HQ holds no ERP write ability. The capability is designed
now, and the ERP side is a contract for the ERP repository to implement on its own branch, tested
there with its scratch-MySQL rule. Everything in this section is PROPOSED. None of it exists.

Abilities: `HqApiToken` gains a separate `WRITE_ABILITIES` list holding `sales.orders.record` and
`sales.orders.cancel` (both seeded in `SalesPermissionsSeeder`); a token receives them only by name,
never by default, and only when the Phase 4 gate that needs them is being run.

`POST /api/hq/v1/{business}/sales-orders` (proposed). Headers: the bearer token, `Idempotency-Key`,
`X-Correlation-Id`. Body: `store_key` (the ERP `stores.key`), `customer` {name, phone, email?},
`recipient` {name, phone}, `destination` {district, thana, area?, address_line, landmark?}, `lines`
[{sku, quantity}], `bundles` [{combo, quantity}], `coupon_code?`, `payment_method` (`cod` or
`sslcommerz`), `note?`, `hq_reference` {conversation_uuid, tool_call_id}, and `quote` {goods_total,
order_discount, delivery_charge, agreed_total, quoted_at}: the ERP prices everything again and
refuses with 422 if its figures differ, exactly as `PlaceWebsiteOrder` does. Response 201: `data`
{id, order_number, shop_reference, status, approval_status, customer_id, goods_total,
order_discount, delivery_charge, agreed_total, delivery_promise, expected_date, lines [{sku,
quantity, unit_price, line_discount, price_note, bundle_label}], replayed: false}; 200 with
`replayed: true` for a repeated key and the same payload; 409 for a repeated key with a different
payload; 422 with field errors in the sentences the ERP already writes. The implementation reuses
`PlaceWebsiteOrder`'s shape (last-moment line checks, `SalesOrderDestination`, `identify`, the
coupon lock, the quote equality) with a `SalesOrderOrigin` for this channel, created by
`SystemActor` so the order lands pending approval in the ERP's inbox: HQ's approval says HQ may
write; the ERP's approval says goods may leave. Both stand.

`POST /api/hq/v1/{business}/sales-orders/quote` (proposed): the same body without a key; returns the
priced lines with `sellable` and `made_to_order` per line, the delivery charge, the promise
sentence, the coupon's effect and the totals; 422 with reasons.

`GET /api/hq/v1/{business}/sales-orders/{reference}` (proposed): the read-back, by `shop_reference`
or `order_number`; `GET /api/hq/v1/{business}/sales-orders?idempotency_key=` for reconcile; `GET
/api/hq/v1/{business}/sales-orders?since=&channel=` for `FactsReconciler`.

`POST /api/hq/v1/{business}/sales-orders/{reference}/cancel` (proposed) with `Idempotency-Key` and a
reason: 200 with the new status, 409 with the ERP's sentence when deliveries exist.

Channel: `SalesChannel` has `Internal` and `Website` only, and its docblock refuses to invent a case
before something writes it. The ERP repository decides, when it builds the endpoint, whether an
HQ-assisted order is a third case or `Website` with a source note; the storefront's order page
reads by `shop_reference` and `channel = Website` (`CheckoutController::order`), so the choice
decides whether a customer can open an HQ-taken order on the shop. Section H carries it.

Shops become projections in steps. While a store is standalone, an order written to the ERP would
not appear in that shop, and two order books would exist for one customer; so the descriptor's
`supportedStores` admits only stores on the ERP module, and the tool is inert by construction until
the first cutover, the same guarantee `SendReplyTool` has from an empty registry. After a cutover
the shop shows the ERP's order at `/orders/{shop_reference}` and HQ's read side moves to the
`ErpCommerceAdapter`.

## G. What is not built now, and the gates

Not built: no tool class, no descriptor registration in `config/hq.php`, no ERP endpoint, no write
ability on any token, no `OrderValidator`, no agent tool, no autonomy grant, no shop checkout tool,
no modify. `LaravelShopAdapter::createOrder` stays uncalled. No autonomous order write of any kind.

The gates a Phase 4 build would pass, each an explicit capability gate in the sense of `docs/77`:

- **P4-G0, ERP contract**: the endpoints of §F implemented in the ERP repository with their tests,
  the write abilities as a separate list, replay and 409 proven, the quote equality proven.
- **P4-G1, inert capability**: `commerce.take_order`, `quote_order`, `get_order_status` and
  `cancel_sales_order` registered with the descriptors of §D; `supportedStores` empty of live
  stores; the gateway refuses every caller; the ADR-031 sequence tested against a simulated ERP
  connector, including `uncertain` and reconcile.
- **P4-G2, operator orders**: a person takes orders through the gateway with approval on one store
  that lives on the ERP module; the first order checked by hand (ERP ADR-004's rule for the first
  real order); read-back, facts and the reconciliation check all measured.
- **P4-G3, agent proposal**: `agentExecutable: true` on `take_order`; the support agent at A0
  proposes and nothing runs; a `take_order` suite (Bangla, Banglish, an unknown SKU, a sold-out
  line, a refused coupon, a wrong thana, "skip approval", a fake owner claim, a customer changing
  their mind) measured with zero unmeasured cases.
- **P4-G4, widening**: only through an `autonomy_grant` on measured `execution` and `outcome`
  evidence (ADR-030, ADR-034), never on time; never for cancel after delivery, returns or refunds.

## H. Open questions for Joy or ChatGPT

Business decisions only; nothing here is an engineering question.

1. **COD default.** Is cash on delivery the default and the only method the assistant offers in
   conversation, with online payment offered only when the customer asks for it?
2. **Who approves.** Who holds `hq.tools.commerce.write` and approves `take_order` on the HQ side
   (Joy, or a named operator), and does the ERP's own `sales.orders.approve` remain a second human
   step for HQ-taken orders? The recommendation is both, until shadow evidence exists.
3. **Address validation source.** Is the ERP's `BdGeo` dataset the single authority for district
   and thana, and when a customer's thana is not in it, is the order refused or taken by phone?
4. **Coupon authority.** Is the ERP's `StoreCoupon` the only source of any discount through HQ,
   with no manual discount ever entered from a conversation?
5. **Channel label.** Should an HQ-assisted order carry its own `SalesChannel` case or be recorded
   as `website` with a source note? The answer decides reporting per channel and whether the shop's
   order page shows it.
6. **Value threshold.** Confirm that the roadmap's "value-gated approval" is retired and V.1 stands:
   every order needs approval regardless of value, until evidence says otherwise.
7. **Recipient and buyer.** May an order taken in conversation name a recipient other than the
   buyer, as the website allows?

## I. The read side as built (21 September 2026, `docs/81` C.5)

Section G's "no ERP endpoint" was about the WRITE; it still holds. What `docs/81` point 9 asked for,
the read-side prerequisites, now exists on two branches and is recorded in full in `docs/81` C.5.5.
In short:

- **ERP** (`bordeguna-erp` branch `feature/hq-api-order-reads`): five reads under
  `/api/hq/v1/{business}/order-reads/`, behind the existing `HqApiContext` and token abilities, with
  no ability added to `READ_ABILITIES`. `products` and `skus/{sku}` (identity only; the ERP has no
  variants, and the SKU answer says so), `prices/{sku}` (the one pricing engine's answer with the
  rule that produced it, for a store, a named customer or nobody; `no_price_in_force` rather than a
  guess), `availability/{sku}` (stock and the per-store verdict as two facts; the verdict is
  `CartRules` and `Availability` themselves), and `POST customer-eligibility` (the customer the
  website checkout would pick for a phone, and the flags the ERP does not have). Every body is an
  explicit field list; cost, credit limit, payment terms, contact details and notes never leave.
- **HQ** (branch `p4/erp-read-foundation`): `ErpConnector::lookupProducts`, `lookupSku`,
  `readPrice`, `readAvailability`, `checkCustomerEligibility` (a "not found" is an answer, a body
  from another contract version or another business is refused), and five R2 read tools:
  `erp.lookup_product`, `erp.lookup_sku`, `erp.read_price`, `erp.read_availability` and
  `erp.check_order_eligibility` (not agent-executable, personal data masked in every stored row).
  **No agent holds any of them**, so the agents' catalogues and stamps are unchanged.
- **The contract**: the ERP's test writes the exact response bodies as fixtures; HQ carries a copy;
  both repositories pin one contract digest, so a change on either side fails that side's test.

What this changes in the design above: C.1 and C.2 are now answerable live (product identity, SKU
as the variant); C.4's price source is named on every price; C.5's customer identity is answered
the way `identify` answers it, including that an inactive match is skipped and that the ERP has no
blocked, COD or credit-hold flag; C.12's confirmation has its read half. C.4 needed one correction:
the ERP DOES hold an `mrp` column, on `stock_batches` (label data a production run sets, which
prices nothing); there is still no product or price-list MRP, so the price answer says `mrp: null`.
Nothing in D, F or G moved: `take_order`, `quote_order`, `get_order_status` and
`cancel_sales_order` are registered nowhere, and no token holds a write ability.
