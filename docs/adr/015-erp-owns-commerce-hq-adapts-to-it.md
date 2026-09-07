# ADR-015: The ERP owns commerce; HQ reaches it through the platform interface

**Status:** accepted (Joy, 6 Sep 2026, decision D1). **Amends ADR-009 and narrows ADR-010.**

## Context

ADR-009 was written on 5 September, before the boss's website brief existed. It
said Econic would be rebuilt as a custom commerce platform, wider than a single
brand shop, and it named a `FutureEconicCommerceAdapter` and a
`FutureMultiVendorCommerceAdapter` as implementations of the `CommercePlatform`
interface.

On 6 September Joy recorded the brief and the decisions that follow from it. Two
of them contradict the assumptions ADR-009 rested on:

- **D1:** the ERP owns website management, and HQ stays the intelligence layer.
  The future custom commerce platform is not a separate Econic application; it
  is the ERP's website module.
- **Econic is not a marketplace.** Only Bôr dé Güna publishes; nobody else logs
  in. There is no seller, no onboarding, no commission and no payout.

Left as it stood, ADR-009 would have HQ and the ERP each believing they own the
future of commerce, which is exactly the kind of disagreement an ADR exists to
prevent.

## Decision

1. **The `CommercePlatform` interface stays.** It was the right abstraction and
   it is what makes this amendment cheap. No HQ code outside an adapter
   references a platform's shapes.
2. **The future platform behind that interface is the ERP website module**, not
   a separate Econic application. The adapter to build, when the first store
   moves, is an **`ErpCommerceAdapter`**.
3. **`FutureMultiVendorCommerceAdapter` is withdrawn.** There is no multi-vendor
   platform to adapt to. If a genuine third-party seller ever exists, that is a
   new decision and a new design.
4. **`WooCommerceAdapter` remains** as the legacy adapter for econic.store until
   Econic moves, which is Phase 5 and last.
5. **`LaLaDiaCommerceAdapter` and `BionicCommerceAdapter` become transitional.**
   Each retires when its store cuts over to the ERP platform, and its retirement
   is part of that cutover's definition of done, not a later tidy-up.
6. **ADR-010's entity model is narrowed, not replaced.** Business, brand and
   store stay as separate concepts. The **seller** concept is dormant: nothing
   populates it and nothing branches on it. One legal company also means HQ
   builds no intercompany logic.

## Alternatives

- **Leave ADR-009 alone and let the ERP work proceed.** Rejected: two
  repositories asserting ownership of the same role is how the group ends up
  with two admin panels, which the brief forbids.
- **Withdraw ADR-009 entirely.** Rejected: its core, one interface with
  platform-neutral DTOs, is what makes the change a swap of adapters instead of
  a rewrite. It earned its place.

## Consequences

- HQ's tools (`search_products`, `get_product`, `get_variant`, `get_price`,
  `get_availability`, `create_order` and the rest) do not change. They were
  written against the interface, which was the point.
- ⚠ `get_availability` must express the ERP's per-channel availability policy
  (ERP ADR-004): status-based for web, strict for POS, with backorder and
  preorder as first-class answers. A boolean "in stock" is not sufficient and
  would misreport every made-to-order product.
- ⚠ `create_order` on the ERP adapter creates a real ERP sales order that posts
  to the ledger. Its idempotency guarantees (ADR-011, at-least-once plus
  reconciliation) matter more here than they did against a shop API, because a
  duplicate now reaches the books.
- HQ's read models stay read models. The ERP remains the system of record for
  catalogue, price, customer and order, which is ADR-007 unchanged.
