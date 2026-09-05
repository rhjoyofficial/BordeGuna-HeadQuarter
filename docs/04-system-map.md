# 04 - System Map

## Today (verified 5 Sep 2026)

```
 Internet
   |
   |  15.235.188.158  Nikolahost shared cPanel (PHP 8.3.33, MariaDB 10.11, web server UNKNOWN)
   |    laladia.com        Laravel shop (Blade + React admin), DB sangramn_laladia_db, mail on cPanel
   |    bionic.garden      Laravel shop fork, DB sangramn_bgarden, mail on cPanel
   |    econic.store       WordPress 7.1 + WooCommerce 10.9.1 (cPanel account UNKNOWN)
   |    theazmion.com      static React SPA
   |    bordeguna.com      static React SPA
   |
   |  200.97.162.40  Hostinger KVM2 srv1808022 (Ubuntu 24.04, Docker 29, Traefik on host network)
   |    erp.srv1808022.hstgr.cloud      Bor De Guna ERP  (app/web/mysql8/worker/scheduler/backup)
   |    n8n-vb99.srv1808022.hstgr.cloud n8n 2.29 (sqlite) -> Postgres 17 db n8n_chat_memory
   |    127.0.0.1:3010                   content-render (Node)
   |    zeno.srv1808022.hstgr.cloud      Zeno POS demo (Joy's own product)
   |    safor.srv1808022.hstgr.cloud     static client preview
   |    (native) Hermes Agent gateway -> Telegram
   |
   External: Meta Graph (Pages, Messenger, CAPI, Ads), Pathao/Steadfast/RedX/CarryBee, SSLCommerz,
             MimSMS, GTM/GA4, Anthropic, OpenAI, HeyGen, Telegram, GitHub
```

Flows that exist today:
- Shop -> Meta CAPI (purchase, delivered); browser -> GTM -> pixel + GA4.
- Shop -> Pathao API (book, sync every 15 min); Pathao -> shop webhook (callback URL currently owned by bizmation).
- Shop -> MimSMS (OTP, order notices). Shop -> outbound webhooks (order.created...) with zero subscribers today.
- Messenger -> n8n bot -> Anthropic + bionic.garden assistant API -> Messenger; escalations -> Telegram.
- ERP scheduler -> morning briefing to staff; ERP assistant -> Anthropic.
- **No flow exists between the ERP and the shops. No customer channel reaches the ERP.**

## Target (end of Phase 5)

```
 hq.bordeguna.com  (HQ web + API)  ----- Telegram command channel
      |  Horizon workers, scheduler, event bus (Redis), Postgres 17 + pgvector, object storage
      |
      +--> ERP connector  --> erp.*  /api/hq/v1  (new, read-first)
      |      -> laladia.com, bionic.garden  /api/v1 + /api/v1/assistant + admin API (token)
      |      <- webhooks: order.created, order.status_changed, shipment.delivered, payment.completed
      +--> Commerce Platform Interface
      |      LaLaDiaCommerceAdapter, BionicCommerceAdapter, WooCommerceAdapter (legacy, econic.store),
      |      FutureEconicCommerceAdapter (rebuilt Econic platform), FutureMultiVendorCommerceAdapter
      +--> Meta adapter   --> Pages, Messenger, Instagram, Ads, CAPI    <-- Messenger/IG/comment webhooks
      +--> Google adapter --> GA4 Data API, Ads (later), Merchant feed (later)
      +--> TikTok adapter (later), LinkedIn/YouTube publishing (later)
      +--> Model router   --> OpenAI, Anthropic (+ OpenRouter/Gemini optional)
      +--> Voice gateway (Node, Phase 7) --> telephony provider
```

Hosting: HQ deploys to the **current** VPS (ADR-012) after swap, limits and monitoring are in place. The Nikolahost exit (doc 33) and a second commerce VPS remain documented options, triggered by measured capacity, not assumed.

## Boundaries that must hold

- HQ never opens a database connection to the ERP, the shops or WordPress. APIs only. (The ERP's private bridge already enforces this physically.)
- Credentials for every external platform live in HQ's encrypted `integration_credentials`, never in prompts, and leave n8n once the matching HQ adapter is live.
- The AI has no path to any system except `app/Tools/*`.
