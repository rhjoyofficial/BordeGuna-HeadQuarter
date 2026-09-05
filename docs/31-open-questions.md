# 31 - Open Questions

Decisions only Joy or the boss (C M Moin) can take. Nothing below has been assumed in the design; where a default is stated, it is the recommendation, not a decision.

## Answered (5 Sep 2026, Joy)

| # | Decision |
|---|---|
| Q1 | answered above | |
| Q3 | answered above | |
| Q6 | answered above; remaining sub-question: is the rebuilt Econic platform a new fork of the LaLaDia product or a new codebase, and when? Does HQ integrate legacy Woo in the meantime (needs REST keys and the cPanel account)? | fork the LaLaDia product if its model fits multi-seller; integrate Woo only if Econic has real order volume today |
| AI training | **No fine-tuning or training in Phase 0**; the Behavior Alignment Layer carries "learns how I work" (ADR-013). |


## Before Phase 0

| # | Question | Recommendation |
|---|---|---|
| Q1 | Which GitHub account hosts `BordeGuna-HeadQuarter`: personal `rhjoyofficial` 
| Q2 | Domain for HQ: `hq.bordeguna.com`? | yes | For now skip the hq.bordeguna.com, when we transwer the domain then we will change it.
| Q3 | Capacity: will use this same one for now, when needs will upgrade.
| Q4 | Who owns the AI provider accounts and cards: HQ needs its own OpenAI and Anthropic keys with spend caps, in whose org? | Bor De Guna org when needs ask i will add them so can be used, this can be changeable from the UI anytime |
| Q5 | Budget caps: total AI spend/month, media generation/day, ad-spend guardrails (doc 12 defaults) | as much as needs, no barrier for now. |

## Business scope

| # | Question | Recommendation |
|---|---|---|
| Q6 | Econic: integrate WooCommerce as-is, or replatform onto the LaLaDia multi-shop product as the third fork? | We will fully rework on it, we will make it custom desing, main goal here we will use laladia and bionic both site product, also others product as well, work like multi-vendor site. |
| Q7 | Does Econic become an ERP business row and a brand code (e.g. `ECN`)? Azmion has no products; does it stay a business without a brand? | NO to Econic; Azmion stays IT software service-only |
| Q8 | Which brands get customer AI first? | Both |
| Q9 | Shop stock vs ERP stock: which is truth for "in stock" in customer replies, and will they be synchronised? | Only if product status inactive, then only show unavailble, until always in stock, will not depend on the stock |
| Q10 | Cost prices are blank in the ERP catalogue, so profit attribution is impossible until filled. Who fills them and when? | We will right before you start the task. |
| Q11 | Pathao callback URL is owned by bizmation. Repoint to the shop/HQ or leave polling? | How Bizmation can be remove, we have to then use for us. |

## Meta and platforms

| # | Question | Recommendation |
|---|---|---|
| Q12 | Portfolio consolidation: Bor De Guna assets (Pages, datasets, the BDT ad account currently inside Joy's End Brackets portfolio, WhatsApp) should live in the Bor De Guna portfolio `3967588683468735`. Who executes, when? Bionic Page transfer is blocked by its dataset link. | New Plan come, bionic and laladia all meta related access will be transfer or add newly to another official account, not by mine. |
| Q13 | One Meta app for all Bor De Guna Pages (recommended) and who owns it | This full work will be done from Boss new profile will give. newly all |
| Q14 | Privacy policy and terms pages per brand domain (required for App Review) | write once, host on each domain |
| Q15 | Which brands have Instagram professional accounts linked to their Pages? | UNKNOWN, will check and if not then will create not a problem. |
| Q16 | WhatsApp Business for Bor De Guna brands: new WABA or reuse End Brackets' registration? | new, in the Bor De Guna portfolio |
| Q17 | Google: create Google Ads, Merchant Center and Search Console under a Bor De Guna Google account? YouTube channel exists? | All exits, will share when needs, ask |
| Q18 | TikTok and X: in scope for year one? | TikTok yes after a product feed exists; X no |

## Voice and media

| # | Question | Recommendation |
|---|---|---|
| Q19 | Telephony provider with Bangladeshi numbers and SIP (BTRC-licensed IP telephony) vs foreign caller id | research local SIP providers only, no foriegn. |
| Q20 | Is Bangla realtime speech quality acceptable? Needs a test before any customer call | test in Phase 7 week 1 |
| Q21 | Whose avatar and voice, if anyone's real; consent contract | synthetic presenter by default , for real my boss face and voice, will avaible all options. |

## Operations

| # | Question | Recommendation |
|---|---|---|
| Q22 | Who are the approvers per domain (doc 13) and their SLAs | Joy for operations/ads, brand managers for content, boss for HUMAN_ONLY, me joy will get all cause I needs to maintain all so nothing will blank for me. |
| Q23 | Hermes Agent: retire, or keep as Joy's personal Telegram brain outside HQ? | I wanna think fully new, skipping all the existing, will be best what need, I have time and all to start fresh, not a problem. |
| Q24 | The 39-day-old open human takeover in the Bionic bot: resume or close? | can fully remove them all, cause all new needs, make your skill so if in future they needs , you can build them. |
| Q25 | Mail for laladia.com / bionic.garden lives on Nikolahost cPanel; where does mail go after the hosting exit (doc 33)? | Zoho Mail or Google Workspace; will decide before DNS moves |
| Q26 | CarryBee credentials in OneDrive plaintext: move and reissue | do it this week, all we will use. |
