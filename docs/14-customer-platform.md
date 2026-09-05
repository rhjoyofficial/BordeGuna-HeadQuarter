# 14 - Customer Platform

## Unified identity

One `customers` row per real person per business, with many `customer_identities` (channel + external id). Sources today: shop customers/orders (phone-keyed), ERP CRM customers/leads (`phone_normalized` already exists), bot Postgres `customers`, Messenger PSIDs, Instagram IGSIDs, WhatsApp numbers, Woo customers, email.

### Resolution rules (deterministic first, probabilistic second)

1. **Phone** normalised with the ERP's `PhoneIdentity` rule (`01XXXXXXXXX` canonical; accepts `+880`, `880`, spaced/hyphenated, missing leading zero). Exact match = same customer, confidence 1.0. Phone is the strongest key in Bangladesh e-commerce.
2. **Email** exact (lower-cased) = confidence 0.9.
3. **Channel id already linked** (PSID/IGSID/WA) = 1.0.
4. **Name + district + recent order** within 30 days = 0.6, flagged for review; never merged automatically.
5. A PSID with no phone stays its own customer until the person gives a phone (order flow captures it), then merges.
6. Merges are recorded and reversible (`customer_merges` with the previous split).

⚠ Phone is never unique-constrained: one household, one number is common (already decided in the ERP, C-CRM-12/13). Match, warn, never block.

## Profile (read model)

identity, contacts, addresses (from orders), orders across shops and ERP, products purchased, AOV, LTV, delivered vs cancelled ratio, preferences (language: Bangla/English, delivery window), complaints and tickets (ERP), conversation history (HQ), campaign attribution (first/last touch), consent (marketing SMS/WhatsApp/Messenger), structured + episodic + semantic memory (doc 09).

Rebuildable from sources by `hq:customers:rebuild --business=`.

## Conversation state machine

```
AI_ACTIVE -> AI_WAITING (asked the customer something)     -> AI_ACTIVE
AI_ACTIVE -> HUMAN_REQUIRED (escalation)                   -> HUMAN_ACTIVE (a human claimed it)
HUMAN_ACTIVE -> HUMAN_RESOLVED (human marks done)          -> AI_RESUMED (human presses "Resume AI") -> AI_ACTIVE
any -> CLOSED (inactivity 72 h or explicit)                CLOSED -> AI_ACTIVE (customer writes again)
```

Escalation triggers: customer asks for a human; anger/complaint classifier; refund/cancellation intent; order above the auto threshold; policy `deny`; the model's own confidence flag; three unanswered turns. On escalation HQ pauses AI replies, writes the reason, notifies the assigned human (Telegram + inbox) with the transcript, the customer profile and the relevant order/product facts. While HUMAN_ACTIVE, AI still drafts suggested replies (never sends). Resume hands the AI the full transcript including the human's messages.

The n8n bot already implements a simpler version of this (`human_takeover`, escalate/resume tools). HQ replaces it channel by channel; the escalation semantics stay the same so operators are not retrained.

## Channels

| Channel | Inbound | Outbound | Prerequisite |
|---|---|---|---|
| Messenger | Meta webhook `messages` | Send API | App Review for `pages_messaging` (currently Dev Mode), privacy policy URL per Page |
| Instagram DM | `instagram_manage_messages` | Send API | IG professional account linked to each Page; App Review |
| Facebook comments | `feed` webhook | comment reply / private reply | `pages_manage_engagement`, App Review |
| WhatsApp | Cloud API webhook | template + session messages | WhatsApp Business account per brand (End Brackets portfolio holds a registration; Bor De Guna needs its own); template approval |
| Web chat | HQ widget on shops (later) | same engine | none |
| Phone | voice gateway (doc 19) | | Phase 7 |
| SMS | none (one-way) | MimSMS | exists |

## Customer AI rules

Live commerce tools only for any product, price, stock, combo, promotion or order fact. No invented product information. Bangla and English, mirroring the customer. Never quotes delivery dates the courier has not confirmed. Order taking follows `take_order` (doc 10) and `create_order` policy (doc 12). Collects the minimum: name, phone, district/thana, address, items, payment method.
