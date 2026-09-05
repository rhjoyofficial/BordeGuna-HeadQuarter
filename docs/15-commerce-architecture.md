# 15 - Commerce Architecture

## Truth today

| Brand | Catalogue, prices, stock, orders | Cost (COGS) | Notes |
|---|---|---|---|
| LaLaDia | LaLaDia Laravel shop (MariaDB on cPanel) | ERP `products` (153 rows, brand LLD) | shop stock is the sellable truth; ERP stock is the accounting truth; they are not synchronised |
| Bionic | Bionic fork | ERP (brand BNC) | same split |
| Econic | WooCommerce | none in ERP | UNKNOWN everything |
| Dealer / counter | ERP Sales + POS | ERP | 0 orders so far |

HQ does not resolve the shop-vs-ERP stock split; it exposes both and flags disagreement. Reconciling them is an ERP/shop project, listed in doc 31.

## Commerce API layer (what the AI and HQ use)

`app/Commerce/` in HQ defines platform-neutral DTOs and a `CommerceGateway` interface: `Product`, `Variant`, `Price`, `Promotion`, `Combo`, `StockLevel`, `Customer`, `Address`, `Order`, `OrderLine`, `Shipment`, `PaymentState`, `Return`. Three implementations:

| Gateway | Reads | Writes | Auth |
|---|---|---|---|
| `LaravelShopGateway` (LaLaDia, Bionic) | `/api/v1/assistant/*`, `/api/v1/geo/*`, admin orders/customers/shipments | `/api/v1/checkout` (customer-shaped order), `/api/v1/landing/{slug}/checkout`, admin order status/notes | assistant key + Sanctum admin token issued to an `hq` admin user with a minimal role |
| `WooGateway` (Econic) | `/wp-json/wc/v3/products`, `/orders`, `/customers` | `/orders` (create/update) | consumer key/secret |
| `ErpSalesGateway` | ERP `/api/hq/v1` customers, stock, cost, sales orders | ticket create, purchase recommendation | Sanctum token |

Rules: typed inputs validated before the call; idempotency key on every write (the shop checkout already tolerates repeats via cart/session semantics; HQ adds an explicit key header and the shop should honour it, an additive change); prices are never sent by HQ, the shop computes them (`/checkout/preview` first, then `/checkout`); stock is checked at draft time and again at confirmation.

## Order taking through AI

```
customer intent -> search_products -> present options (live price, pack sizes, free-delivery rule)
 -> draft (lines, district/thana from the geo API, payment method) -> /checkout/preview -> quote back
 -> customer says yes -> policy check (value threshold, first-time customer) -> /checkout
 -> order ref returned -> customer told -> order.created event -> ERP/HQ facts
```
Landing-page-only products use the landing checkout endpoint; the item list there is manual by rule.

## Returns, refunds, modifications

Read via shop admin API; any change is APPROVAL_REQUIRED (doc 12); refunds HUMAN_ONLY by default because money moves through SSLCommerz or cash.

## Shipping and couriers

Stay in the shop (Pathao live; Steadfast/RedX/CarryBee drivers exist, CarryBee unverified). HQ reads shipment state and receives `shipment.delivered`. HQ never books a parcel directly in v1.

## Payments

SSLCommerz (one live merchant for both shops), COD, partial advance. HQ reads payment state only. The shared merchant means HQ must always carry `business_id` derived from the order prefix.

## Econic decision

Two options, both documented in doc 31: (a) integrate WooCommerce through `WooGateway` and keep WordPress; (b) replatform Econic onto the LaLaDia multi-shop product as the third fork (the platform was designed for exactly this). Option (b) removes a whole technology from the estate but costs a catalogue migration and SEO care. Recommendation: **discover first** (access, order volume, plugins), decide within Phase 1.
