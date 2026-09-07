# ADR-010: Business, Brand, Store, Product owner, Seller, Channel and Platform account are separate entities

**Status:** accepted. NARROWED by ADR-015 on 6 Sep 2026: the seller concept is dormant (Econic is a multi-brand store, not a marketplace) and one legal company means no intercompany logic.

## Context
The first draft mirrored the ERP's `businesses` and `brands` and treated "the shop" implicitly. A future store selling products of several businesses, or several sellers, would have forced a redesign.

## Decision
HQ models seven entities (doc 03, doc 05): `businesses`, `brands` (owned by one business), `stores` (a storefront operated by a business, reached through one commerce adapter), `store_product_owners` (which businesses' products a store sells), `sellers` (entity of record per order line on multi-seller stores), `channels` (origin of an interaction or order) and `platform_accounts` (external accounts per brand with capability metadata). Orders belong to a store, carry a channel, and each line carries seller and product owner.

## Alternatives
- One `businesses` table with flags: simplest, blocks multi-seller Econic; rejected.
- Adopt the ERP's brand-as-product-dimension only: correct for cost accounting, insufficient for storefront and marketing identity; kept as the mapping for brand codes.

## Consequences
Slightly more joins in read models; "Store B sells products from Business A and B" is data, not a migration. ERP `businesses` and `brands` remain the masters for the entities they define; HQ mirrors ids.
