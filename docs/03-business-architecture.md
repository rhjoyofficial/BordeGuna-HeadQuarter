# 03 - Business Architecture

## Entities and who owns them

| Concept | Owner system | HQ's relationship |
|---|---|---|
| Business (operating unit) | ERP `businesses` (LaLaDia, Bionic, Azmion, Bor de Guna) | mirrors the list; Econic has **no ERP business row** today (decision needed) |
| Brand | ERP `brands` (LLD, BNC) for products; HQ needs brand as a marketing/content entity | HQ `brands` keyed to ERP brand codes, extended with AI persona, visual guidelines, platform accounts |
| Product / variant / price / stock | Shops for sellable catalogue; ERP `products` for cost, FIFO, manufacturing | HQ reads both; never writes catalogue |
| Order | Shops (online), ERP Sales/POS (counter, dealer) | HQ reads all; creates online orders only through the shop checkout API |
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
- Employee and payroll data are visible only to holders of the ERP permission; HQ inherits the map, never widens it.
- Testimonials, reviews and customer quotes are never generated.
