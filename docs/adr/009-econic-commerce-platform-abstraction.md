# ADR-009: Commerce Platform Interface; WooCommerce is a legacy adapter, not Econic's architecture

**Status:** accepted (Joy, 5 Sep 2026). AMENDED by ADR-015 on 6 Sep 2026.

## Context
The first audit found econic.store running WordPress + WooCommerce and the design leaned on a `WooConnector` as Econic's system. Joy corrected this: Econic will be rebuilt as a custom commerce platform, wider than a single-brand shop (Econic, LaLaDia, Bionic and other Bor De Guna products; potentially several sellers or business entities; custom catalogue, UX and workflows).

## Decision
HQ defines one `CommercePlatform` interface with platform-neutral DTOs (doc 15). Implementations: `LaLaDiaCommerceAdapter`, `BionicCommerceAdapter`, `WooCommerceAdapter` (temporary/legacy for econic.store), `FutureEconicCommerceAdapter`, `FutureMultiVendorCommerceAdapter`. No HQ code outside the Woo adapter may reference WooCommerce shapes. Tools (`search_products`, `get_product`, `get_variant`, `get_price`, `get_availability`, `create_order`...) are written against the interface only.

## Alternatives
- Treat Woo as Econic's permanent system: rejected by the product owner.
- Skip Woo entirely until the rebuild: acceptable if Econic has no order volume today (open sub-question in doc 31); the adapter is cheap enough to build if needed.

## Consequences
The Econic rebuild swaps one adapter; HQ, its tools, agents and policies do not change. Domain model must carry store, product owner and seller separately (ADR-010).

---

## Amendment, 6 September 2026 (ADR-015)

Read this ADR with [ADR-015](015-erp-owns-commerce-hq-adapts-to-it.md), which
narrows it on three points:

1. The future custom commerce platform is **the ERP website module**, not a
   separate Econic application. The adapter to build is an ErpCommerceAdapter.
2. **FutureMultiVendorCommerceAdapter is withdrawn.** Econic is a multi-brand
   store, not a marketplace: only Bor de Guna publishes and nobody else logs in.
3. LaLaDiaCommerceAdapter and BionicCommerceAdapter are **transitional**, each
   retiring when its store cuts over.

What this ADR got right and keeps: one CommercePlatform interface with
platform-neutral DTOs, and no HQ code outside an adapter referencing a
platform-specific shape. That is what made the amendment a swap rather than a
rewrite.
