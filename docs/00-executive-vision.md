# 00 - Executive Vision

## One sentence

BordeGuna-HeadQuarter (HQ) is the single website from which the whole company is run: it watches every business, tells the owner what needs attention, lets humans and AI agents act through controlled tools, and records why every action happened.

## What HQ is

- **The control surface.** One login, one place to see LaLaDia, Bionic Garden, Azmion, Econic and the ERP together, and to act on any of them.
- **The intelligence layer.** AI agents that read live business truth (products, stock, prices, orders, customers, campaigns, ledgers) and produce recommendations, drafts, replies, schedules, and controlled actions.
- **The automation layer.** A durable scheduler and workflow engine that turns "post this tomorrow at 5 PM" or "every morning brief me" into jobs that survive restarts, retry, and leave an execution history.
- **The memory.** Structured preferences, approved SOPs, decisions, brand knowledge, customer history, and the outcome of past actions, so the AI becomes more aligned with how the owner works without retraining a model.

## What HQ is not

- **Not a second ERP.** Employees, finance, accounting, inventory truth, procurement and vendors stay in the Bor De Guna ERP (live, 8 modules, 3,081 tests). HQ calls it; it does not copy it.
- **Not a second shop.** Products, variants, prices, stock, combos, promotions and orders for LaLaDia and Bionic stay in the shop platform (the LaLaDia codebase and its Bionic fork). Econic's stay in WooCommerce until a replatform decision is taken.
- **Not an unrestricted agent.** No AI action reaches a customer, a campaign budget, a payment or a record without passing the policy, permission and approval layer, and every action is audited.

## The businesses today (verified 5 Sep 2026)

| Brand | Domain | What it is | System | Host |
|---|---|---|---|---|
| Bor De Guna | bordeguna.com | Parent company site | Static React/Vite SPA | Nikolahost shared cPanel |
| LaLaDia | laladia.com | Food e-commerce | Laravel 12 shop platform (the "product") | Nikolahost shared cPanel |
| Bionic Garden | bionic.garden | Wellness e-commerce | Fork of the LaLaDia platform | Nikolahost shared cPanel |
| Azmion | theazmion.com | Technical/agency brand | Static React/Vite SPA | Nikolahost shared cPanel |
| Econic | econic.store | Consumer electronics shop | WordPress 7.1 + WooCommerce 10.9.1 | Nikolahost shared cPanel |
| Bor De Guna ERP | erp.srv1808022.hstgr.cloud | ERP + HRM + Accounting + Sales/POS + CRM | Laravel 12 + Inertia/React, Docker | Hostinger VPS |
| Automation | n8n-vb99.srv1808022.hstgr.cloud | Bionic Messenger bot, content pipeline | n8n 2.29.7 + Postgres 17 | Hostinger VPS |

## Success looks like

The owner types, in one place:

- "Tell me what needs my attention today." and gets a briefing built from ERP, shops, campaigns and conversations.
- "Post this image to LaLaDia Facebook tomorrow at 5 PM." and a durable job is created, approved, executed and reported.
- "Reply to simple customer messages, escalate angry ones to me." and the customer AI does exactly that, with a human-takeover path that resumes cleanly.
- "Check Hilsa Pickle stock." and the answer comes from the live shop API, not from a model's memory.
- "Which campaigns generated real profit?" and the answer is built from ad spend, orders, COD cancellations and product cost, not from platform ROAS.

## Guiding principles

1. **Truth stays where it is.** HQ integrates; it does not migrate business data into itself.
2. **Controlled autonomy.** AUTO / APPROVAL_REQUIRED / HUMAN_ONLY per action type, configurable, audited.
3. **Platform-neutral cores.** Marketing, publishing, messaging and model access all sit behind adapters so no single vendor API shapes the business layer.
4. **Cheap by default, premium when justified.** A model router picks the smallest model that does the job and tracks cost per conversation, order, customer, content item, campaign and brand.
5. **Verify by running it.** Every phase ends with the feature exercised against real systems, not only a green test suite. This rule has already paid for itself many times in the existing codebases.
6. **Understandable by an AI coding agent.** Clear structure, ADRs, checklists, runbooks. Claude Code is the primary implementer; the repository is written for it.
