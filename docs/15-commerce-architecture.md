# 15 - Commerce Architecture

## Commerce Platform Interface (ADR-009)

HQ never talks to a shop directly. Every commerce system sits behind one interface, `CommercePlatform`, with platform-neutral DTOs in `app/Commerce/`: `Store`, `Product`, `Variant`, `Price`, `Promotion`, `Combo`, `Availability`, `Customer`, `Address`, `Order`, `OrderLine`, `Shipment`, `PaymentState`, `Return`. WooCommerce is **one adapter**, not the definition of Econic.

| Adapter | System | Status | Reads | Writes | Auth |
|---|---|---|---|---|---|
| `LaLaDiaCommerceAdapter` | LaLaDia Laravel shop | build in Phase 1 | `/api/v1/assistant/*`, `/api/v1/geo/*`, admin orders/customers/shipments | `/api/v1/checkout`, landing checkout, admin order status/notes | assistant key + Sanctum admin token for an `hq` admin user with a minimal role |
| `BionicCommerceAdapter` | Bionic fork (same API surface) | Phase 1 | same | same | same, own credentials |
| `WooCommerceAdapter` | econic.store today | **temporary / legacy**, Phase 1 if access is obtained | `/wp-json/wc/v3` products, orders, customers | orders | consumer key/secret |
| `FutureEconicCommerceAdapter` | the rebuilt Econic commerce platform | when that platform exists | its API | its API | TBD |
| `FutureMultiVendorCommerceAdapter` | a store selling products of several businesses/sellers | future | | | |
| `ErpSalesGateway` | ERP `/api/hq/v1` (cost, ERP stock, counter/dealer orders) | Phase 1 | customers, stock, cost, sales orders | ticket, purchase recommendation | Sanctum token |

Interface contract: typed inputs validated before the call; **an idempotency key on every write**, passed to the platform where it honours one (the Laravel shops should honour an `Idempotency-Key` header, an additive change); prices are never computed by HQ (`preview` first, then `create`); every call recorded in `connector_calls`; `capabilities()` declares what the adapter supports so the UI and the AI never offer an operation the store cannot do.

## Econic direction

Econic will be **rebuilt as a custom commerce platform** whose scope is wider than a single-brand shop: Econic products, LaLaDia and Bionic products, other Bor De Guna products, and in future several sellers or business entities with custom catalogue, UX and workflows. WooCommerce is the legacy system HQ integrates with until then. Nothing in HQ may assume WooCommerce shapes; everything goes through the interface above. Whether the rebuilt platform is a new fork of the LaLaDia product or a new codebase is an open question (doc 31) that does not affect HQ.

## Domain model the adapters map onto (ADR-010)

Business owns Brands and Products; a Store is a commerce storefront operated by a Business; a Store sells products from one or many Businesses (product owner); a Seller is the entity of record for a line on a multi-vendor store; Channels (web, Messenger, Instagram, WhatsApp, phone, POS) are where orders originate; PlatformAccounts are the external accounts (Pages, ad accounts, WABAs) tied to a Brand. Full model in doc 03.

## Truth today

| Store | Catalogue, prices, stock, orders | Cost (COGS) | Notes |
|---|---|---|---|
| laladia.com | LaLaDia shop | ERP `products` (brand LLD) | shop stock is the sellable view; ERP stock is the accounting view; not synchronised |
| bionic.garden | Bionic fork | ERP (brand BNC) | same split |
| econic.store | WooCommerce | none in ERP | access, volume, gateways UNKNOWN |
| counter / dealer | ERP Sales + POS | ERP | 0 orders so far |

HQ does not resolve the shop-vs-ERP stock split; it exposes both views and flags disagreement.

## Availability semantics (business policy, changeable)

The current business rule is deliberate and unusual: **customer-facing availability is governed by product status, not by stock quantity.** An inactive or discontinued product is unavailable. An active product is treated as orderable. Stock quantity does not block a customer reply or an order unless the commerce system itself reports the product or variant unavailable. `get_availability` therefore returns `{orderable: bool, reason: status|platform_unavailable, stock_hint?: int}` and the AI uses `orderable`, never the raw quantity, to decide what to offer. Low stock still raises `inventory.low` for the inventory agent. This is a policy row (`commerce.availability_rule = status_based`) so it can be changed to `stock_based` per store later without touching agent code.

## Order taking through AI

```
intent -> search_products -> get_product / get_variant / get_price / get_availability (live)
 -> draft (lines, district/thana from the geo API, payment method) -> preview -> quote back
 -> customer confirms -> policy (value threshold, first-time customer) -> create_order (idempotency key = conversation + draft hash)
 -> order ref -> customer told -> order.created event -> facts + follow-up workflow
```
Landing-page-only products use the landing checkout; the item list there is manual by rule.

## Returns, refunds, modifications, shipping, payments

Read through the adapter; `update_order`/`cancel_order` are APPROVAL_REQUIRED; refunds HUMAN_ONLY (money moves through SSLCommerz or cash). Couriers stay in the shop (Pathao live; CarryBee unverified); HQ reads shipment state and never books parcels in v1. Payments read-only; the shared SSLCommerz merchant means `business_id` is always derived from the order prefix (`LLD`/`BNC`).
