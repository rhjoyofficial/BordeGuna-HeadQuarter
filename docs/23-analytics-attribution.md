# 23 - Analytics and Attribution

## Goal

Answer "which campaigns generated real profit" with **contribution profit**, not platform ROAS.

```
Ad -> click -> website -> landing page -> (conversation) -> order -> payment -> delivery -> revenue -> gross profit -> contribution profit
```

## Data joins

| Step | Source | Key |
|---|---|---|
| Spend, impressions, clicks | Meta insights (later Google/TikTok) per campaign/adset/ad per day | campaign ids stored on `marketing_experiments` |
| Click -> site | GTM/GA4 (`utm_*`, `fbclid`), shop `_fbc`/`_fbp` cookies already captured on orders | order.campaign hints |
| Landing views, add to cart, checkout | GA4 events (view_item, add_to_cart, begin_checkout, purchase exist on the shops) and Meta pixel | session/order |
| Conversation -> order | HQ conversation id attached to `create_order` idempotency key and stored on the order note | order ref |
| Order, payment, delivery, cancellation | shop webhooks -> `facts_orders` | order ref + business |
| COGS | ERP `products` cost via `/api/hq/v1/products/{sku}/cost` (blank today for most rows: **the catalogue has no cost prices**, an ERP data task) | sku |
| Shipping cost, payment fees | shop shipment (courier charge) + SSLCommerz fee schedule | order ref |

Contribution per order = delivered revenue - COGS - shipping cost - payment fee - allocated ad spend (last-touch by default, with first-touch and conversation-assisted views available).

## Read models

`facts_orders`, `facts_ad_spend`, `facts_sessions` (GA4 Data API daily pull), `facts_conversations`. Rebuildable. Aggregations per brand/campaign/product/day in materialised views refreshed nightly and on demand.

## Reports (v1)

Daily business briefing numbers; campaign contribution table; product profitability; cohort of customers by first campaign; COD cancellation by district and source; landing-page click-to-view leak; AI cost per unit (doc 26).

## Honesty rules baked into the reports

Every revenue figure shows gross vs delivered; every ROAS figure is paired with delivered CPA; sample sizes are printed (a one-day campaign is noise, and reports say so); data as-of timestamps on every table.

## Later

A warehouse (ClickHouse or BigQuery) only if daily row volume makes Postgres aggregations slow; not expected in the first year.
