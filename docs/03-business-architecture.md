# 03 - Business Architecture

## Domain model (ADR-010)

Seven concepts, kept as separate entities because collapsing them would block the multi-seller Econic platform later.

| Entity | Meaning | Examples today |
|---|---|---|
| **Business** | a legal or operating unit that owns things and is accountable for money | Bor de Guna, LaLaDia, Bionic, Azmion (ERP rows 1-4); Econic (no ERP row yet, Q7) |
| **Brand** | a market-facing identity with persona, visual rules and platform presence; owned by exactly one Business | LLD, BNC (ERP brand codes); Econic, Azmion, Bôr dé Güna as marketing brands |
| **Store** | a commerce storefront operated by a Business, reached through one commerce adapter | laladia.com, bionic.garden, econic.store (Woo, legacy), the future Econic platform, ERP counter/POS |
| **Product owner** | the Business whose catalogue a product belongs to (cost, FIFO, manufacturing live here) | Bor de Guna owns every current product via the ERP; a store lists products from one or several owners |
| **Seller** | the entity of record for an order line on a multi-seller store; equals the product owner on single-owner stores | future Econic platform selling LaLaDia and Bionic products alongside its own |
| **Channel** | where a customer interaction or order originates | web, Messenger, Instagram, WhatsApp, phone, POS, landing page |
| **Platform account** | an external account tied to a Brand with its own credentials and capabilities | Facebook Page, Instagram account, WABA, ad account, GTM container, YouTube channel |

Relationships: Business 1-n Brand; Business 1-n Store; Store n-m Business as product owners (`store_product_owners`); Store 1-n Seller (a seller is a Business or a future third party); Brand 1-n Platform account; Order belongs to a Store, has a Channel, and its lines each carry a Seller and a Product owner. So "Store B sells products from Business A and Business B" is a row in `store_product_owners`, not a redesign.

## Entities and who owns them

| Concept | Owner system | HQ's relationship |
|---|---|---|
| Business | ERP `businesses` (LaLaDia, Bionic, Azmion, Bor de Guna) | mirrors the list; Econic has **no ERP business row** today (Q7) |
| Store | each commerce system is its own store record in HQ, reached through its adapter (doc 15) | HQ owns the store registry and capability metadata, never the store's data |
| Brand | ERP `brands` (LLD, BNC) for products; HQ needs brand as a marketing/content entity | HQ `brands` keyed to ERP brand codes, extended with AI persona, visual guidelines, platform accounts |
| Product / variant / price / availability | the Store's commerce system for the sellable catalogue; ERP `products` (product owner) for cost, FIFO, manufacturing | HQ reads both through adapters; never writes catalogue; availability follows the status-based business policy in doc 15 |
| Order | the Store (online) or ERP Sales/POS (counter, dealer) | HQ reads all; creates orders only through the store adapter; every line carries seller and product owner |
| Customer | Shops (buyers), ERP CRM (leads, dealers, tickets), bot Postgres (chat identities) | HQ owns **identity resolution** and the cross-system profile; each source keeps its record |
| Employee, payroll, attendance | ERP HRM | read-only summaries; HR actions are HUMAN_ONLY |
| Finance | ERP Accounting | read-only; journal *proposals* only |
| Campaign / ad | Platforms (spend, delivery); HQ `marketing_experiments` (intent, decisions) | HQ is truth for *why*; platforms are truth for *what happened* |
| Content item | HQ content factory | HQ owns drafts, approvals, schedule; platforms own post ids |
| Conversation | HQ canonical thread fed by Messenger, Instagram, WhatsApp, web chat | HQ owns state machine and handoff |
| Media asset | HQ asset library (metadata) + object storage (bytes) | HQ owns |
| Policy, approval, audit | HQ | HQ owns |

## Roles

| Role | Human examples | Sees in HQ |
|---|---|---|
| Owner / Executive | C M Moin | everything; final approver for HUMAN_ONLY classes |
| Operator / Admin | Joy | configuration, agents, skills, policies, integrations |
| Brand manager | per-brand marketer | that brand's content, campaigns, conversations, approvals |
| Support agent | customer service | inbox, takeover, customer profile, order tools |
| Finance viewer | accountant | reports and cost dashboards, read-only |
| AI agents | see doc 08 | each a role with its own permission set, never wider than the human who enabled it |

## Core business flows

1. **Daily briefing**: ERP (cash, receivables, stock alerts, attendance) + shops (orders, cancellations, revenue) + ads (spend, CPA) + conversations (waiting humans) -> one message at 09:00 Asia/Dhaka.
2. **Message to order**: message -> identity resolved -> AI replies with live product data -> draft order -> customer confirms -> `create_order` through the shop checkout API -> confirmation -> courier -> delivery event -> follow-up.
3. **Content to publish**: idea -> brand persona -> copy + image/video -> QA -> approval -> scheduled job -> adapter publishes -> metrics -> learning stored.
4. **Campaign lifecycle**: experiment (objective, audience, offer, creative, budget, hypothesis, KPI) -> adapter creates -> monitoring -> anomaly -> recommendation -> approval within guardrails -> change -> evaluation -> decision recorded.
5. **Stock protection**: `inventory.low` from shop or ERP -> pause ads for that product (policy-gated) -> purchase recommendation -> notify.
6. **Profit attribution**: spend + order + payment + delivery + COD cancellation + product cost (ERP) -> contribution per campaign, product, brand.

## Guardrails that come from the business

- COD cancellation is ~33% of orders; every revenue figure must state gross vs delivered.
- LaLaDia and Bionic share one SSLCommerz merchant and one Pathao account; attribute by order prefix (`LLD`/`BNC`), never "the shop".
- Landing pages define their items manually; nothing auto-adds products.
- Availability shown to customers follows product status, not stock quantity, until the business changes that policy (doc 15).
- Employee and payroll data are visible only to holders of the ERP permission; HQ inherits the map, never widens it.
- Testimonials, reviews and customer quotes are never generated.
