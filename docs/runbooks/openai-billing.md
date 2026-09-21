# HQ's AI on OpenAI: the account, the key, and how HQ switches to it

A note that can be forwarded as it is. Written on 21 September 2026 from OpenAI's own documentation
(developers.openai.com, read that day). The screens on platform.openai.com may be worded slightly
differently when you open them; the steps are the same.

## In one paragraph

On 21 September you decided HQ should use OpenAI's API for now, and Anthropic's again later. OpenAI's
API is paid for separately from any ChatGPT subscription: **ChatGPT Plus or Pro does not pay for API
calls.** You load prepaid credit on platform.openai.com, and every AI call takes a little of it. HQ is
ready for it (`docs/81` C.7): once the next release is deployed, you store one key on HQ's Credentials
screen, and HQ moves its AI calls to OpenAI only when a named person runs one audited command. Nothing
switches on by itself.

## What to do on platform.openai.com (about 10 minutes)

1. **Sign in** at platform.openai.com (the same login as ChatGPT works). If it asks, create an
   organisation; name it after the business, for example "Bor de Guna".
2. **Billing: add $25 of prepaid credit.** Settings, Organization, **Billing**: add a payment method,
   then add $25 to the credit balance. Leave **auto recharge off**, so money is only ever added by a
   person.
3. **Limits: a monthly spend limit of $50, and an alert.** Settings, Organization, **Limits**: under
   Spend, "Edit spend limit", enter 50, turn on "Enforce a hard limit", save. Add a spend alert at $30
   so you hear before the limit stops anything. The limit is a ceiling, not a charge.
4. **A project for HQ.** Settings, **Projects**, create a project named **BordeGuna HQ**. A separate
   project keeps HQ's usage, limits and keys apart from anything else on the account.
5. **A key for that project.** Inside the BordeGuna HQ project, **API keys**, "Create new secret key".
   Give it the name `bordeguna-hq`. If the page offers an expiry date, a year is fine; we rotate it
   before then. **Copy the key when it is shown: it is shown once.** It starts with `sk-`.

Do not paste the key into a chat, an email or a file. It goes to one place only, the next section.

## Where the key goes: HQ's Credentials screen (you, not Claude)

1. Open https://hq.bordeguna.com/credentials and **hard-reload the page first** (Ctrl+Shift+R). A page
   left open for a while has an expired form, and saving it only looks like it worked.
2. Add a credential with exactly these values:
   - **provider**: `openai_api_key`
   - **account ref**: `hq`
   - **type**: `api_key`
   - **secret**: the key you copied
3. Save, and **check that the new row appears in the list** on that page. If it does not, reload and try
   again; nothing else counts as done.

Storing the key switches nothing on. HQ keeps using nothing until the steps below.

## What happens after (the commands are Claude's to prepare and yours to type)

After the release that carries `docs/81` is deployed:

1. The OpenAI model rows are added, switched off (one seeder line).
2. `hq:ai:provider openai` shows, without changing anything, exactly which rows it would switch on and
   off. Then `hq:ai:provider openai --apply --by=<your HQ email> --reason="..."` moves HQ's AI to OpenAI,
   recorded under your name. It refuses if the key is missing or a shadow week is running.
3. `hq:credentials:health --verify-model` sends one tiny call with your key and records the answer: the
   OpenAI row should read `verified`. If it reads `provider_blocked_by_credit`, the account needs credit
   or a higher limit (the key is fine); if `credential_invalid`, the key was not copied in full.
4. Gate 11 attempt 2 runs once, now on OpenAI. Its runner checks the key again, proves that OpenAI
   handles HQ's structured replies, then measures the Executive agent. If every case passes, the seven
   day shadow week can start on your word; if not, everything stays off.

## Why these amounts

The last gates cost about $2 each on Anthropic's comparable model. OpenAI's working-tier model
(`gpt-5.6-terra`) charges the same for input and a little more for output ($2 and $12 per million
tokens, against $2 and $10), and it also spends some "reasoning" tokens, so a gate run is estimated at
**about $2 to $4**. The shadow week, if it happens, is a few dollars more. $25 covers a gate, a retry
and the shadow week with room to spare; the $50 limit only caps a month, it charges nothing.

| What | Price per million tokens (21 September) |
| --- | --- |
| GPT-5.6 Luna (small, cheap tasks) | $0.20 in, $1.20 out |
| GPT-5.6 Terra (the agents' working tier) | $2.00 in, $12.00 out |
| GPT-5.6 Sol (the strongest, rarely used) | $4.00 in, $20.00 out (a promotional price, OpenAI says at least until 21 November 2026) |

## Going back to Anthropic later

When you want Anthropic again, say so. The Anthropic key stays stored and its driver stays in HQ; going
back is the same audited command the other way (`hq:ai:provider anthropic --apply ...`), after its
account has credit. A gate measures one model, so the first time back on Anthropic needs its own
complete gate before any shadow week runs on it.

## What errors mean

| What HQ records | What it means | What to do |
| --- | --- | --- |
| `provider_blocked_by_credit` | The account has no credit, or a spend or usage limit was reached. The key is fine | Add credit or raise the limit on platform.openai.com |
| `credential_invalid` | OpenAI does not accept the key | Create a new key and store it again (hard-reload first) |
| `provider_unauthorized` | The key is fine but something forbids the call (a project IP allow-list, or a region) | Check the project's settings |
| `provider_unavailable` | OpenAI was busy or down | Nothing; try again later |
