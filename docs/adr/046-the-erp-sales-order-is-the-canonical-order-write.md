# ADR-046: The ERP Sales Order is the canonical order write; `take_order` is one business capability, and shops are projections

**Status:** proposed, **not implemented** (16 Sep 2026). Written for ChatGPT's instruction of
16 September ("Continue at full speed while Gate 11 waits", points 6 and 7, verbatim in `docs/78`
§A) and for Joy's direction of 15 September that once the ERP's website modules exist the shops
operate through the ERP and HQ integrates only with the ERP, the per-shop adapters and tokens being
a bridge. The design is `docs/79`. Nothing in this ADR registers a tool, adds an endpoint, issues an
ability or writes an order.

## Context

Three decisions stand behind this one. ADR-007: HQ holds read models and is never the master for
orders. ADR-015 (Joy, 6 September, D1): the ERP owns commerce, the future platform behind the
`CommercePlatform` interface is the ERP website module, and `LaLaDiaCommerceAdapter` and
`BionicCommerceAdapter` are transitional, each retiring at its store's cutover. ADR-029: an agent
reasons about a business capability, never a vendor endpoint, and the implementation is resolved
behind the Tool Gateway.

What exists (read on 16 September, `docs/79` §B). HQ's `CommercePlatform` interface carries
`previewOrder`, `createOrder` and `reconcileOrder`, implemented by `LaravelShopAdapter` against the
standalone shops' checkout and called by no tool; `config/hq.php` registers no order write, and no
agent-executable write with an external effect exists (ADR-031). HQ's `ErpConnector` has one write,
`openTicket`; the ERP's `/api/hq/v1` has no sales-order endpoint, and `HqApiToken` issues read
abilities only. The ERP itself has the order: `SalesOrder` (business, customer, store, channel,
`order_number`, `shop_reference`, frozen prices, delivery charge, coupon, promise window, payment
method, approval), written only through `CreateSalesOrder`, and already produced by its website
module's `PlaceWebsiteOrder` from a basket, idempotent on the cart, priced by the one engine, and
pending approval before goods may leave. Those tables held zero rows on 12 September; no shop has
cut over.

The roadmap's Phase 4 row (`docs/30`) says "`take_order` skill through the shop checkout API with
value-gated approval". Left as it stands, HQ would build one order write per shop, each with its
own idempotency rule, its own reconciliation and its own failure shapes, and each rewritten at that
shop's cutover; and an agent would know which shop's checkout it was calling, which is exactly the
vendor knowledge ADR-029 forbids. Three shops are about to change their implementation. That is the
moment the abstraction is cheap.

## Decision

1. **The ERP Sales Order is the canonical order write.** An order HQ takes is a row in the ERP's
   `sales_orders`, created through the ERP's own `CreateSalesOrder` path, and nowhere else. HQ keeps
   a fact of it (`facts_orders`), which is a copy with an age, never the order.
2. **`take_order` is one business capability**, registered as `commerce.take_order` with a
   `ToolDescriptor` in deployed code: R4 (an external side effect correctable without loss: a
   pending order that moves no stock and posts no journal), a write, approval-required by
   `RiskTier::baseline()` under Joy's V.1 decision with no value threshold, `agentExecutable: false`
   until its own gate and never `permanentlyHumanOnly`, consent not required (an operator-initiated
   order is not a customer message), idempotent on a logical key derived from the business, store,
   conversation and the canonical structured order, compensable by `commerce.cancel_sales_order`,
   `dataClass` personal, one invocation per business. `docs/79` §D is the declaration.
3. **Shops are projections.** A shop shows an order; it does not own one. Until a store lives on the
   ERP module, the capability does not admit that store (`supportedStores`), so the tool is inert by
   construction rather than by a flag; after cutover the shop renders the ERP's order by its
   `shop_reference`.
4. **No per-shop order-write adapter is ever built.** `LaravelShopAdapter::previewOrder`,
   `createOrder` and `reconcileOrder` are never wrapped by a tool, never scheduled, and are deleted
   at each shop's cutover. `take_order_via_laladia`, `take_order_via_bionic` and any name of that
   shape are refused as a class and as a bridge, for one launch as much as forever.
5. **Provider neutrality is kept (ADR-029).** The agent reasons about `take_order` through the
   Capability Registry and the Tool Gateway. Behind the gateway the implementation is an
   `ErpSalesOrderCapability` interface in HQ's own vocabulary (`quote`, `create`, `status`,
   `cancel`, `modify`, `reconcile`); its first implementation is a connector over the ERP's HQ API,
   and an internal service or a protocol adapter would implement the same interface. No agent,
   prompt or tool catalogue names which.
6. **The read side stays where it is until each cutover.** Catalogue, variant, availability and
   order reads through `LaravelShopAdapter`, the shop webhooks into `facts_orders`, and
   `FactsReconciler` remain, and are replaced by the `ErpCommerceAdapter` ADR-015 names when a store
   moves.
7. **Verification-first and at-least-once are part of the definition, not options.** A create is
   `succeeded` only after the order is read back from the ERP and its totals equal the quote
   (ADR-031); an unread effect is `uncertain`; an uncertain call is reconciled by logical key before
   any retry, and stays uncertain with a person told when it cannot be (ADR-011). The ERP prices
   everything again and refuses a write whose quote it cannot match, as `PlaceWebsiteOrder` does.
8. **Money never moves through it.** The payment method is a recorded choice (`cod` or an online
   payment the customer completes with the gateway); receipts, refunds and returns stay human-only
   (ADR-025), and a manual price override has no field in the structured order.
9. **The ERP endpoint is a contract, not an assumption.** The ERP repository implements it
   (`docs/79` §F: create, quote, status, list, cancel under `/api/hq/v1/{business}/sales-orders`,
   with an `Idempotency-Key` header, replay, and 409 on a reused key with a different payload), and
   HQ tokens gain `sales.orders.record` and `sales.orders.cancel` only through a separate, explicit
   write allow-list. Until it exists, `docs/79` marks every endpoint proposed.

## Alternatives

**Build `take_order` through the shop checkout, as the roadmap said.** Rejected. It is a working
path today and an orphan at the first cutover, it makes three idempotency rules where the ERP has
one, and it teaches the agent a vendor.

**Give HQ its own order table and push to the ERP later.** Rejected by ADR-007 and by the ledger: an
order one system away from the books is the shape LaLaDia's full-value purchase for an unpaid order
took (`PlaceWebsiteOrder`'s own account of it).

**Wait for the first store to cut over before designing the capability.** Rejected for the reason
ADR-029 gives: the abstraction is free before the first implementation and expensive after the
third, and this is the moment with none.

**One generic `create_order` on every `CommercePlatform` adapter, the ERP one among three.**
Rejected. It keeps the write per platform and makes "which platform" a choice something upstream
has to make, which is the vendor decision ADR-029 refuses to let anything upstream make.

## Consequences

- Adding the ERP capability is one interface, one connector, four descriptors and one ERP contract;
  no agent business logic learns a shop, and a shop's cutover changes the read side only.
- The first R4 tool in HQ arrives with the ADR-031 sequence written into it, so it becomes the
  reference the next write capability copies.
- Until a store lives on the ERP module the capability can serve nobody, and that is the honest
  state: HQ still cannot take an order, and it will not be able to through a shop's checkout either.
- Two human approvals stand for a while: HQ's, that HQ may write, and the ERP's, that goods may
  leave. Collapsing them is an autonomy decision with evidence behind it, not a convenience.
- `docs/30`'s Phase 4 row and `docs/12`'s `order.auto_create_max_bdt` are superseded on the checkout
  path and the value threshold; `docs/15`'s order-taking flow keeps its shape with the ERP in the
  place of the shop.

## Not decided here

Whether cash on delivery is the default the assistant offers; who approves on the HQ side and
whether the ERP's approval remains a second step; whether the ERP's geography dataset is the sole
address authority and what happens to a thana it lacks; whether any discount other than an ERP
coupon may reach an order through HQ; how an HQ-assisted order is labelled in `SalesChannel`; when
the first store cuts over; and whether `take_order` ever executes without approval, which only
measured `execution` and `outcome` evidence can answer (ADR-030, ADR-034). `docs/79` §H lists them
for Joy and ChatGPT.
