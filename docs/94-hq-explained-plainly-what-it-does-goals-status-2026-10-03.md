# 94 - HQ explained plainly: what it does, your goals, where it stands (3 October 2026)

**Written for Joy, 3 October 2026:** "based on your given docs i read, did not get what i wanted. what do we can do using this HQ project? My goals and plan about the project. and the project status, complete full picture so i can fully understand, no short cut."

**How to read it.**
- Parts 1 to 3: what HQ is, and what it can do for each person and each kind of work.
- Part 4: your goals, as you stated them.
- Part 5: the honest status.
- Part 6: what stands between today and real use.
- Part 7: where I think the plan has drifted.
- Part 8: what I need from you.

No code words are used without an explanation. Every fact comes from the repo (`docs/00`, `docs/68`, `docs/82`, `docs/89`, `docs/92`) or from git, checked on 3 October.

---

## Part 1. Why HQ exists: the problem it solves

Bor de Guna is not one business. It is several, each with its own system:

| Business | What it is | Where its data lives |
|---|---|---|
| Bor de Guna ERP | Stock, purchase, manufacturing, accounts, staff, payroll, POS sales, CRM | The ERP (VPS) |
| LaLaDia | Online food shop (hilsa pickle, oils, ghee...) | laladia.com shop system |
| Bionic Garden | Online wellness shop | bionic.garden (a fork of LaLaDia) |
| Econic | Electronics shop | WordPress / WooCommerce |
| Azmion | Technical services brand | Static site |
| Facebook / Instagram / WhatsApp | Where customers talk to us | Meta (Business Suite, n8n bot) |
| Meta ads | Where marketing money goes | Ads Manager |

**The pain today.** To answer one simple question about the whole group, someone has to open four or five systems and add things up by hand. Some example questions:
- "How did we do yesterday?"
- "What is running out?"
- "Which ad actually made money?"
- "Who hasn't been answered on Messenger?"

Customer messages are answered by people typing the same replies again and again. Orders that come in on chat are re-typed into the shop admin by hand. Nobody sees the whole company in one place.

**HQ's job** is to be the one office above all these systems. It has three halves:

1. **The eyes (watching).** It reads every system and shows the whole group on one screen, every morning, in Bangla or English.
2. **The hands (doing).** AI assistants do routine work:
   - answer customers;
   - take orders;
   - watch ads;
   - draft posts;
   - flag problems.
3. **The rules (control).** Nothing the AI does reaches a customer, money or a record unless a named person allowed it. Every action is written down: who asked, who approved, what happened.

**What HQ is not:**
- **Not a replacement for the ERP or the shops.** The ERP still owns stock, money and staff. The shops still own orders.
- **Not a store of its own data.** HQ only reads those systems and asks them to act; it keeps dated copies of what it read.

---

## Part 2. What you will be able to do with HQ, by person

### The boss (C M Moin), every morning
- Opens one page at 9 AM, or gets it on the phone. It shows:
  - yesterday's orders on LaLaDia and Bionic, split into delivered and cancelled COD;
  - ERP sales;
  - what is running low;
  - what is waiting for his approval;
  - anything wrong (a shop not reporting, a backup failed).
- Asks in plain words, in Bangla or English, and gets an answer from live records with the source of every number. For example:
  - "গতকাল LaLaDia তে কয়টা অর্ডার এসেছে?"
  - "This Eid week vs last Eid week?"
  - "Which Bionic orders are unpaid after two days?"
- Approves things on one screen: an order, a post, a campaign change. He sees exactly what he is approving.
- Later: "Which campaigns made real profit?" The answer comes from spend, delivered orders, COD cancellations and product cost, not Meta's ROAS number.

### You (Joy), the operator
- You run the system:
  - the AI assistants;
  - the connections (tokens);
  - the rules;
  - the releases.
- You hold every approval right, so nothing is ever blocked for lack of an approver.
- You decide when an assistant gets more freedom, based on its measured track record.
- Telegram alerts tell you when something breaks: a shop token rejected, the scheduler silent, a backup failed.

### Support staff (later)
- One inbox for Messenger, Instagram DMs, Facebook comments and WhatsApp.
- The AI proposes a reply from live price and stock data. Staff send it, edit it or throw it away.
- Angry customers, refunds and complaints go straight to a person with the full history.

### Brand managers (later)
- Their brand's posts, campaigns and conversations only.

### The accountant (later)
- Read-only reports and cost dashboards. HQ never posts or pays anything.

### Customers
- Never log in to HQ. They just get faster, correct answers on Messenger and WhatsApp, and later on the phone.

---

## Part 3. What HQ can do, by area of work (with today's state)

**Legend**
- ✅ **Working now** on production.
- 🔒 **Built but switched off**: it waits for a safety exam or an outside approval.
- 📝 **Designed, not built.**
- 💡 **Idea only.** Implied by the vision but not planned; needs your yes.

### 3.1 Watching the company (owner command center)
| What | Example | State |
|---|---|---|
| Orders from all shops in one list | LaLaDia + Bionic orders side by side with payment and delivery state | ✅ (but see Part 5: the shop tokens are rejected) |
| Telegram alerts | "LaLaDia token rejected", "backup failed" | ✅ |
| 5 dashboards + posture, autonomy evidence, briefings pages | AI health, cost, control plane, platform, alerts; activation posture | ✅ |
| Data integrity check | HQ's copy of each order compared with the shop's real record | ✅ |
| Approvals inbox | Every action needing a person, with the exact details | ✅ (only demo actions exist so far) |
| Morning briefing | "LaLaDia 14 orders (3 COD cancelled), Bionic 6; mustard oil low; 2 approvals waiting" | 🔒 waiting for the shadow week |
| Ask HQ in Bangla or English | "how much hilsa pickle did we sell this month vs last month?" | 🔒 |
| Scheduling in words | "every morning at 8 check LaLaDia orders" → a job you confirm | 🔒 |
| Weekly and monthly reports | Weekly sales per brand, month-end pack, COD cancellations by district | 📝 |
| "What changed since I last looked" | New approvals, big orders, stock-outs since the boss's last visit | 💡 |
| Phone-friendly HQ | The boss reads and approves on mobile | 💡 |

### 3.2 Customer service (the biggest daily win)
| What | Example | State |
|---|---|---|
| One inbox for all channels | Messenger, IG DM, FB comments, WhatsApp in one place | 🔒 |
| AI suggests the reply | "hilsa pickle er dam koto?" → reply with the real price and delivery charge, in the customer's own script | 🔒 (Support agent's exam: 42 of 43 passed) |
| Instant hand-over to a person | Refund, complaint, "I want a human" → the thread moves to a person with its history | 🔒 (⚠ corrected 3 Oct by docs/95: **no Telegram ping on hand-over exists yet**; only the inbox badge) |
| WhatsApp channel | Same as above on WhatsApp | 🔒 (needs a WhatsApp number + Meta verification) |
| Facebook comment handling | Answer questions, move order intent to DM, escalate complaints, hide spam | 📝 |
| "Where is my order?" | Finds the order by phone number, reads the courier state | 📝 (needs courier API) |
| Remembers returning customers | "prefers delivery after 6 PM", "writes in Banglish" | 🔒 |
| Web chat on the shop sites | Chat bubble on laladia.com / bionic.garden | 📝 no date |
| Never invents | No price, stock or promise that the system did not return | Rule, always on |

### 3.3 Sales and orders
| What | Example | State |
|---|---|---|
| Order taken in chat | Customer says "2 ta nibo" → AI builds an order → the ERP checks price, stock, delivery, address → a person approves → it becomes a real order | 🔒 (needs a second approver, and an ERP write token) |
| Quote, status, cancel | Price an order, read it back, cancel before delivery | 🔒 |
| Payment link + courier booking | bKash/Nagad link, Pathao/Steadfast/RedX booking | 📝 (needs a gateway + courier API; this is ERP work) |
| After-delivery follow-up | Thank-you, review request, 30-day reorder nudge (only with consent) | 📝 |
| COD risk flag | Warn before dispatch: "this phone cancelled twice before" | 💡 |
| Dealer quotations | Draft a dealer quote from ERP price groups | 💡 |

### 3.4 Stock, supply and production
| What | Example | State |
|---|---|---|
| Low stock watch | "mustard oil 5 L: 3 left" | ✅ events arrive; 🔒 in the briefing |
| Shop vs ERP stock mismatch | Flag when the shop and the ERP disagree | 📝 |
| Reorder suggestions | What to buy, and how much, from sales speed | 📝 |
| Pause ads when stock runs out | Ad stops when the product is out, resumes on restock | 📝 |
| Expiry / batch watch, production yield watch, Eid / hilsa-season planning | | 💡 |

### 3.5 Money (read-only; HQ never moves money)
| What | Example | State |
|---|---|---|
| Cash position | BRAC, bKash and the till, each with its balance | 💡 |
| Who owes us / who we owe | Ageing lists | 💡 |
| Odd-looking spending | An expense far above normal, a supplier paid twice | 💡 |
| Month-end pack for the accountant | P&L, balance sheet, open items | 💡 |
| Payroll readiness | Missing attendance, unrecovered advances before the cycle closes | 💡 |

### 3.6 Marketing and ads
| What | Example | State |
|---|---|---|
| Ad results beside real sales | Spend, clicks, orders, delivered orders, cost per delivered order, per brand, daily | 🔒 read side built |
| Real profit per campaign | Delivered revenue − product cost − shipping − fees − ad spend | 🔒 (needs ERP cost prices; most are blank) |
| Budget guard | Monthly budget per brand vs actual spend | 🔒 |
| Waste flags | "This ad set spent 3 days with no order" | 📝 |
| Prepare a campaign, paused, for approval | | 📝 |
| Raise budget / switch a campaign on | Only a person, ever | Rule |

### 3.7 Content and research
| What | Example | State |
|---|---|---|
| Post and caption drafts from real product facts | Bangla captions built only from ERP weight, ingredients and price | 🔒 |
| Claims check | Each draft checked against what may be said about mustard oil, honey, pickles | 🔒 (the policy itself is yours to write) |
| Schedule and publish a post | "Post this to LaLaDia Facebook tomorrow at 5 PM" → approval → posted → link back | 🔒 (needs Page tokens + Meta verification) |
| Festival calendar | Eid, Pohela Boishakh, hilsa season, Ramadan plans | 📝 |
| Research with sources | Competitor prices, the hilsa ban dates, every claim cited | 🔒 (needs a search key) |

### 3.8 Voice and video (later phases)
| What | State |
|---|---|
| Bangla voice notes on Messenger/WhatsApp, transcribed and answered | 🔒 partly built |
| COD confirmation calls by AI before dispatch | 📝 (needs a licensed BD phone number) |
| Phone receptionist | 📝 |
| Product videos with a presenter (only with written consent) | 📝 design only |

### 3.9 Staff (HR, read-only)
Attendance, leave and recruitment summaries from the ERP, never decisions. 💡

### 3.10 Growing the group
| What | State |
|---|---|
| Add a new shop or brand, and it appears in HQ without new code | 📝 |
| Econic connection | ❌ none: WooCommerce is "unsupported by design" |
| Switch AI provider (OpenAI ↔ Anthropic) in one command | ✅ done once, on 21 Sep |

### The AI assistants ("agents") planned
| Assistant | Job | Today |
|---|---|---|
| Executive | Morning briefing, priorities, answers across businesses | Built, paused |
| Analytics | Numbers, trends, comparisons | Built, paused |
| Support | Customer chat | Built, paused, exam 42/43 |
| Operations | Stuck orders, delivery problems | Planned |
| Inventory | Stock watch, reorder drafts | Planned |
| Marketing, Advertising | Ad strategy and day-to-day running | Planned |
| Content, Creative | Posts, images, video | Planned |
| Voice | Calls and voice notes | Planned |
| Finance, Procurement, Sales, HR | Read-only watchers and drafts | Idea only, need your yes |

**How an assistant earns freedom.**
- Every assistant starts as a **trainee (A0)**: it only drafts, and a person does everything.
- It moves up only when its measured record meets numbers **you** set, and you sign the step:
  - A1: proposes;
  - A2: each action approved;
  - A3: acts alone inside limits;
  - A4: checked afterwards.
- Full autonomy (A5) can never be reached.
- If a safety rule breaks, HQ switches it off by itself.

**What HQ will never do, at any level:**
- Move money.
- Touch ad billing or limits.
- Invent product facts or reviews.
- Message a customer without consent.
- Take HR decisions.
- Delete its own records.
- Promote itself.

---

## Part 4. Your goals for HQ, in your own words

These are the goals recorded in the repo and memory. **This is where I need you to correct me.**

| Date | Your words | Where |
|---|---|---|
| 5 Sep (vision) | One place to see LaLaDia, Bionic, Azmion, Econic and the ERP together and act on any of them; AI that reads live business truth; automation that survives restarts; memory of how the owner works | `docs/00` |
| 10 Sep | "AI models, vendors, tool protocols, providers and infrastructure must remain replaceable; HQ business/control contracts must remain durable." (built for 10 to 20 years) | `docs/60` |
| 13 Sep | "Phases 3 to 10 are the point of HQ… almost everything we build is Bangla and Bangladesh-facing" | `docs/70` |
| 20 Sep | "we have to speed up, run multiple agent if needs… complete full project ASAP without skipping anything, with perfection" | memory |
| 22 Sep | "take ownership, make own decision, discuss with ChatGPT" | `docs/68` §3.22 |
| 22 Sep | "What it will do when finished it's seems to me shorter version what we needs. i want you to write full plan was more bigger… I will share plan again" | `docs/89` |

**What the docs do NOT have from you:**
- **Your own plan.** You said on 22 Sep you would share it again; it never arrived.
- **A priority order.** Which business pain matters most to you and the boss: customer replies, orders, ads profit, or the owner's daily view?
- **A target date.** "ASAP" is recorded, but no date the boss expects something.
- **What "done" means to the boss.** Which first result would make him say "HQ is worth it"?

Without these, the build has followed the order of the technical roadmap and ChatGPT's rulings, not the order of business value.

---

## Part 5. Where the project stands today (3 October), honestly

### 5.1 The short truth
- **About 57% of the engineering is built.**
- **0% of the AI is helping the business yet.** Every assistant is switched off on purpose until it passes its exams.
- **What works today** is the read-only control room: order copies, alerts, dashboards, integrity checks.
- **Even that is half-blind right now.** Both shop admin tokens have been rejected since 22 Sep, and the shops' live messages to HQ have been blocked by the hosting network since 13 Sep. HQ catches up only through a nightly check, and that check needs the tokens.
- **Nothing has been committed since 27 Sep** (6 days).

### 5.2 Timeline, first to last
| When | What happened |
|---|---|
| 5 Sep | 33 design documents written; foundation built (login, 2FA, roles, vault, audit, scheduler, backups) |
| 6-8 Sep | Connected to the ERP, LaLaDia and Bionic (read-only). Order copies, customer identity, nightly checks |
| 8-9 Sep | The control layer: one door for every AI action, approvals, rules, AI router with cost tracking |
| 10 Sep | First two assistants (Executive, Analytics) built. First safety exams failed (4/12, then 24/27) |
| 12-15 Sep | Exams 3 to 10. Each failure smaller; exam 10 = 79/80 |
| 15 Sep | Exam 11 stopped: the Anthropic credit ran out mid-exam |
| 16-17 Sep | Ask HQ, scheduling in words, alerts, dashboards built (switched off) |
| 21 Sep | You switched HQ to OpenAI. Exam 11 retried: FAILED 80/83 (the AI answered "send a WhatsApp" with a morning briefing) |
| 22 Sep | Exam 12: FAILED 82/83 |
| 22-26 Sep | Seven parallel build streams: Messenger/WhatsApp senders, orders into the ERP, ads read side, research and content, voice, bilingual screens |
| 26 Sep | **Exam 13 PASSED** (the Executive is safe). Support's own exam: FAILED 28/43 (a plumbing fault) |
| 27 Sep | Support exam attempts 2, 3, 4: 40 → 41 → **42 of 43**, safety 80/80. Executive argument check **PASSED 12/12**. You released `531d59b` |
| 28 Sep → today | Nothing built. The planned next step (`docs/92`) was not started |

### 5.3 The one remaining exam failure
When a customer mixes Bangla and English, for example "Order LLD-... এখনো আসেনি, please check", the AI replies correctly, but in romanised Bangla ("Dukkhoito, order-er obostha..."). The rule says Bangla script.

The fix (`docs/92`, decided by ChatGPT):
1. HQ itself decides the reply's script from a rule you can change.
2. It checks every reply against that rule.
3. It gives the AI one chance to correct itself, then hands the customer to a person.

**Your choice, any time:** mixed-language customers get Bangla script (today's rule) or romanised Bangla.

### 5.4 Steps to the first assistant actually working (the Executive, as a trainee)
1. ✅ Exam 13 passed.
2. ✅ Executive argument check passed.
3. ⏳ Build the language fix → you release it → Support exam attempt 5 must pass completely.
4. ⏳ You open the **7-day shadow week** by name. The Executive runs as a trainee, its work is checked twice a day, and it switches off automatically if anything breaks.
5. ⏳ After 7 days you set the numbers an assistant must meet to step up.

**Realistic:** 1 to 2 days of engineering plus your release, then 7 calendar days. **The owner's morning briefing could be working in about 10 days** if we restart now.

### 5.5 Production versions
| System | Version |
|---|---|
| HQ | `531d59b` (you, 27 Sep), 1,682 tests |
| AI provider | OpenAI (gpt-5.6), Anthropic off |
| AI spend all-time | about $24 to $30; internal cap $50/month |
| Server | Shared Hostinger KVM 2 (HQ + ERP + n8n + others on one small box) |
| Automatic testing (CI) | Not run since the free minutes returned on 1 Oct: **overdue** |

### 5.6 Percent built per phase
| Phase | What it gives the business | Built | Working for the business |
|---|---|---|---|
| 0-1 | Foundation + reading ERP and shops | 100% | Yes (read-only; shop reads blind now) |
| 2 | Owner's briefing, Ask HQ, approvals | ~97% | No: shadow week first |
| 3 | AI customer replies | ~85% | No: Meta verification, App Review, WhatsApp number |
| 4 | AI takes orders | ~70% | No: second approver, gateway, courier |
| 5 | Ads profit and waste watch | ~35% | No |
| 6 | Content and research | ~40% | No |
| 7 | Voice | ~25% | No |
| 8 | Video presenter | ~5% | No |
| 9-10 | Earned autonomy | ~20% | By design, later |

---

## Part 6. What blocks real use, and who owns it

**The code is not the slowest part.** Outside approvals are. Most of them have not been started, and nothing in the repo says Meta Business Verification was submitted.

| # | Item | Unlocks | Wait | Owner |
|---|---|---|---|---|
| 1 | New LaLaDia + Bionic admin tokens in HQ | HQ can read the shops again | 5 min | You |
| 2 | Fix the shop → HQ network block (hPanel firewall or Hostinger support) | Live order events instead of nightly | Days | You |
| 3 | Release the language fix, then open the shadow week | Owner's morning briefing | ~10 days | Me, then you |
| 4 | **Meta Business Verification** | Real customers can reach the AI; publishing; ads data | Up to 14 business days | You / boss |
| 5 | Meta App Review (Messenger, Instagram) | Customer AI on Messenger/IG | Days to weeks after #4 | You |
| 6 | New SIM for WhatsApp Cloud API | Customer AI on WhatsApp | Days | You |
| 7 | A second order approver named | Any AI order can be approved | Decision | Boss |
| 8 | Payment gateway, courier API | Pay online, "where is my order" | 1-4 weeks | Boss |
| 9 | SMS masking (BTRC) | Order updates by SMS | 1-3 weeks | Boss |
| 10 | Server upgrade (KVM 4, $12.99/mo) | Room for customer AI + a test copy | Hours | Boss |
| 11 | ERP cost prices filled in | Real profit per campaign | Data work | Staff |
| 12 | Claims policy per product | Content drafting | Decision | You / boss |
| 13 | Autonomy numbers | Assistants step up | Decision | You |

**Running cost once live** (estimates, OpenAI):
- Owner asking 10 questions a day: about $9/month.
- AI answering 100 customer messages a day: about $90/month.
- 300 a day: about $280/month.

---

## Part 7. Where I think the plan has drifted (my honest view; you decide)

1. **Safety has eaten the speed.** 13 safety exams and 4 support exams in 17 days were right for a system that will talk to customers. But you asked for "ASAP", and after 28 days the boss has still received nothing he can use. **The owner's morning briefing (read-only, no customer risk) should go live first and fast.** It is the cheapest proof that HQ is worth it.
2. **The order of building follows the architecture, not the business.** Phases 5 to 8 (ads, content, voice, video) were built in parallel before Phase 2 delivered anything. Meanwhile the money and stock watchers (Part 3.5), which need no Meta approval at all, are still "idea only".
3. **Customer AI duplicates what already runs elsewhere.** Today LaLaDia's inbox runs on Meta Business Suite automations and the Bionic bot on n8n. HQ's Support agent replaces them only after Meta verification, which nobody has started. If customer AI is your top goal, **Meta verification is the critical path, not code.**
4. **The read side is broken and nobody is watching it.** Shop tokens have been rejected for 11 days and the network block has lasted 20 days. HQ's own alert should have raised this. Nothing in HQ's docs records the network block.
5. **Your plan is missing.** Without it, ChatGPT and I chose the order. That is how you ended up reading a document that didn't answer what you wanted.

---

## Part 8. What I need from you to continue properly

1. **Correct Parts 2 to 4:** what is wrong, what is missing, what you or the boss want that isn't here.
2. **Your priority order.** Rank these 1 to 5:
   - (a) the owner's daily briefing and Ask HQ;
   - (b) AI customer replies;
   - (c) AI order taking;
   - (d) ads profit and waste watch;
   - (e) money, stock and staff watchers.
3. **A target:** what should the boss see working, and by when?
4. **Your plan**, if you have one written, in any shape, Bangla or English.
5. **Two quick unblockers:** the new shop tokens, and whether Meta Business Verification has been submitted.

Once I have these, I will re-order the roadmap around them and restart the build. The next engineering step either way is the language fix (`docs/92`), plus running the overdue CI.
