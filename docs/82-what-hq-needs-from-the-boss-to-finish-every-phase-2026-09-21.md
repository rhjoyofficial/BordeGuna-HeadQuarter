# 82 - What HQ needs from the boss to finish every phase (21 September 2026)

**For Joy to take to his boss.** It replaces `docs/70` sections A to J as the list to act on; `docs/70` M stays
the record of what HQ could check. Prices are the ones read on 17 September for the Bangla boss report
(`docs/reports/2026-09-17-hq-boss-report-bn.pdf`); confirm each at the moment of buying. Nothing here is
bought or applied for by HQ or by Claude: every item needs the business's documents, card or signature.

## 1. Where HQ stands today, in one paragraph per phase

- **Phases 0, 1 and 2.5: done.**
- **Phase 2 (command center): built and released inert on 21 September** (release `87d8658`). The last step
  is one safety gate on OpenAI (Gate 11, about $2 to $4), then a seven-day observation week for the Executive
  agent. The week cannot be shortened: it is calendar time by design.
- **Phase 3 (customer AI, Messenger, Instagram, WhatsApp): the core is built and switched off.** It cannot
  answer a real customer until Meta verifies the business and approves the messaging permissions (weeks).
- **Phase 4 (AI orders): the read side is built** (products, prices, stock, customer checks). Taking an order
  waits for the design decision and for a payment gateway and a courier account.
- **Phases 5 to 8 (ads, content, voice, avatar): not started.** Each needs an outside account first (below).
- **Phases 9 and 10 (measured autonomy): nothing to buy.** They are earned from the evidence the earlier phases
  record, and each step up is an owner decision.

## 2. Done today, nothing to buy

- **OpenAI API account** (organisation "Bor de Guna", login bordeguna@gmail.com): $21 of credit, auto-reload
  off, monthly limit $100, alerts at $80 and $100. HQ now runs on it. ChatGPT Plus does not pay for the API;
  this account does.
- **HQ's own Telegram bot** `@bordeguna_hq_bot` for every HQ alert.
- **Hermes removed** from the server.

## 3. Needed now, because they set the pace (this week)

| # | What | Cost | Why |
| --- | --- | --- | --- |
| N1 | **Claude Max 20x plan** for the Claude Code account (if it is still on Max 5x) | $200 a month (Max 5x is $100) | Building runs into the plan's usage window during long days. The 20x plan is the pace Joy asked for |
| N2 | **OpenAI credit as it is used** (keep auto-reload off) | $21 now covers the gate and the observation week. Plan about $50 a month once the owner asks HQ questions daily, and more when customer AI goes live (section 6) | Every AI answer is paid per use |
| N3 | **Server upgrade** Hostinger KVM 2 (2 CPU, 8 GB) to **KVM 4** (4 CPU, 16 GB) | $12.99 a month on a 24-month term, $28.99 on renewal | The server runs HQ, the ERP, n8n and the shops on 2 cores. Customer AI and a test copy of HQ need the room |
| N4 | **GitHub Actions budget** (optional) | About $15 a month with a stop at the limit | Automatic testing is paused until 1 October because the free minutes ran out. Without it, every change is verified on Joy's PC |
| N5 | **Cloudflare, free plan**, in front of bordeguna.com | Free | Protection for the public webhook customers' messages will arrive on. Needs the domain's nameservers moved (Joy or whoever holds the domain) |
| N6 | **Cloudflare R2 storage** (one bucket) | Free to 10 GB, then $0.015 per GB a month | Customer photos and voice notes, and a backup copy off the server (today every backup sits on the same server) |

## 4. SLOW items: start the applications this week, they take weeks

These decide when Phase 3 and 4 can go live, not the code. Most need the same papers: **trade licence, TIN, a
utility bill or bank statement in the business name, the business phone, a bank account in the business name.**

| # | What | Lead time | Cost | Needed for |
| --- | --- | --- | --- | --- |
| S1 | **Meta Business Verification** of the business portfolio | 1 to 4 weeks | No fee seen (the paid "Meta Verified" badge is a different thing) | Phase 3: without it only admins can message the app |
| S2 | **Meta Developer app** with Messenger, Instagram Messaging and Webhooks, then **App Review** for the messaging permissions | 1 day to create; review days to weeks after S1 | Free | Phase 3: answering real customers |
| S3 | **WhatsApp Business (Cloud API)** with a **new SIM** that is on no WhatsApp app | After S1 | Customer-started replies free until 30 Sep; from 1 Oct about $0.0037 a message (first 1,000 a month free per number); marketing messages about $0.07 to $0.08 | Phase 3: the channel Bangladeshi customers use most |
| S4 | **Dual-currency card** (dollar-endorsed) from the business bank | Days | The bank's yearly fee | Paying Meta, OpenAI and other dollar bills in the business name |
| S5 | **SMS with a masking name** (BulkSMSBD, Alpha SMS, GreenWeb or SSL Wireless) | BTRC approval, 1 to 3 weeks | Masked SMS 0.45 to 0.64 taka each; BulkSMSBD masking fee 500 taka, minimum purchase 3,000 taka | Phase 3 and 4: OTP, order updates, campaigns |
| S6 | **A payment gateway**: SSLCommerz or aamarPay (all methods in one), or bKash and Nagad merchant accounts directly | 1 to 4 weeks | SSLCommerz 25,500 taka setup, 2.5% a payment; aamarPay about 2% (bKash, Nagad) to 2.55% (cards); bKash about 1.5% to 2% (ask for a written quote) | Phase 4: customers paying for an order the AI took |
| S7 | **Courier API** (whichever courier the shops already use: Pathao, Steadfast or RedX) | Days | No API fee; delivery about 60 to 70 taka in Dhaka, 110 to 150 outside, COD charge 0.5% to 1% | Phase 4: telling the customer where the order is |

## 5. Later, about an hour each, when that phase starts

| Phase | What | Cost |
| --- | --- | --- |
| 5, ads | Meta Marketing API on the same Meta app (after S1); Google Ads API only if search ads are wanted | API free; ad spend separate |
| 5, ads | The LaLaDia hilsa campaign's two empty fields (advertiser and payer) in Ads Manager | Minutes, no cost |
| 6, content | Images: the OpenAI account above already covers it (`gpt-image-1`) | About $0.011 to $0.167 an image by quality |
| 6, content | Video (Google Veo or Kling), optional | Veo $0.05 to $0.40 a second |
| 6, content | Web search for the research engine: Brave or Tavily | About 1,000 searches a month free, then about $5 per 1,000 |
| 7, voice | Speech to text in Bangladeshi Bangla: Google Speech-to-Text | About $0.016 a minute |
| 7, voice | A natural Bangla voice: ElevenLabs (or Azure's Bangla voices, cheaper) | ElevenLabs $22 to $99 a month |
| 7, voice | A Bangladeshi phone number from a BTRC-licensed provider (SLOW; only when voice moves to phone calls) | Provider quote |
| 8, avatar | HeyGen (or D-ID), and a YouTube channel under the business Google account | HeyGen about $3 a minute of video; YouTube free |

## 6. What running it costs (model calls on OpenAI, estimated)

| Use | About |
| --- | --- |
| Gate 11 once | $2 to $4 |
| The Executive's seven-day observation week | $3 to $8 |
| The owner asking HQ 10 questions a day | $9 a month |
| Customer AI answering 100 messages a day | $90 a month |
| Customer AI answering 300 messages a day | $280 a month |

The customer figures are from the Anthropic estimate of 17 September; OpenAI's working model is priced the
same for input and a little higher for output, so treat them as the same order. The first weeks of customer AI
run in shadow (answers drafted, not sent), which costs the same per message.

## 7. Decisions, not money

1. **After Gate 11 passes:** open the Executive's seven-day observation week (Joy, by name).
2. **Phase 4 design:** approve or change the order-taking design (`docs/79`, ADR-046) before any order is
   written into the ERP.
3. **Autonomy numbers:** the pass rates and sample sizes an agent must show before it may act without asking
   (`docs/81` C.4). HQ ships none; they are the owner's.
4. **Phase 8:** whose face and voice the avatar uses, with written consent.
5. **The accountant:** how wallet and gateway receipts are recorded (and VAT on them) before AI orders take money.

## 8. The order to do them in

1. **Today:** N1 if needed; N5 and N6 (free).
2. **This week, all at once:** S1 and S2 (Meta), buy the SIM for S3, S4 (card), S5 (SMS masking), S6 and S7
   (gateway and courier applications). They run in parallel; each is weeks of waiting on someone else.
3. **Before customer AI goes live:** N3 (server) and a test copy of HQ on it.
4. **When phases 5 to 8 start:** the section 5 accounts, an hour each.
