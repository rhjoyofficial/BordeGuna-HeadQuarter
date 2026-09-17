# HQ's AI and the Anthropic bill: what to do, and why

A note that can be forwarded as it is. Read on the Anthropic billing page on 17 September 2026.

## In one paragraph

HQ's AI agents run on the Anthropic API, which is paid for separately from any Claude subscription:
you load credit in advance, and every AI call takes a little of it. Today every AI call is refused, for
two reasons. The prepaid credit has run out (the balance is minus $0.06), and this month's spending
limit of $20 has already been reached ($20.15 spent; it resets on 1 October). Until both are fixed, HQ
cannot run its next safety test (gate 11), and nothing else in the plan can move forward. Any other AI
feature on this account, including the ERP's assistant, is stopped too.

## What the page shows (17 September)

| Item | Value | Meaning |
| --- | --- | --- |
| Account | C M Moin's Individual Org | The organisation that owns both API keys |
| Credit balance | minus $0.06, unpaid | The prepaid credit is used up; a few cents are owed |
| Auto-reload | off | Credit is never added automatically; nothing is charged without someone clicking |
| Monthly spend limit | $20, 100% used ($20.15), resets 1 October | A safety ceiling on the account. When it is reached, calls stop even if credit remains |
| Past bills | $20 credit grant (7 July, expires 8 July 2027); $11.72 monthly invoice (13 September, paid) | What has been paid so far |
| API keys | `bordeguna-erp` ($0.31 used) and `hermes-hostinger-vps-temp-rakib` in the Hermes-Hostinger-VPS workspace ($19.84 used) | HQ uses the second one. Both keys draw on the same balance and the same limit |

A card is saved on the account, so buying credit does not need new card details.

## What to do (about 3 minutes)

On platform.claude.com, Settings, **Billing**:

1. **Buy credits: $25.** Click "Buy credits", enter 25, confirm. This also clears the $0.06 owed.
2. **Raise the monthly spend limit to $50.** Under "Spend limits", click "Adjust limit", set 50, save.
   Without this step the API stays blocked until 1 October, even with new credit, because this
   month's $20 is already spent.
3. Leave **auto-reload off**. Credit is then only ever added by a person.
4. Optional: under "Email notifications", add alerts at $30 and $45, so nobody is surprised.

## Why these amounts

| What will run next | Rough cost |
| --- | --- |
| Gate 11, the Executive agent's safety test, run once | about $2.60 |
| P3-G2, the support agent's safety test on practice conversations | about $2.60 |
| The Executive's seven-day shadow week, if gate 11 passes (six questions a day, answers held for review) | about $3 to $8 |
| Total | about $8 to $13 |

$25 covers that with room for one retry. The $50 limit is a ceiling, not a charge: it only allows up to
$50 of use in a month, and nothing is spent unless the credit is there. HQ also has its own internal
warning at $50 a month.

## What happens after

1. Tell Claude Code "credit added". It gives you one line to type that checks the account from the
   server; the answer should start with `{"http":200`.
2. Gate 11 runs once (about 20 minutes). The agent must pass every case, with nothing left unmeasured.
3. If it passes, the new HQ release is deployed (backup first), and the Executive's seven-day shadow
   week starts: the agent answers three fixed questions twice a day, and every answer is held for
   review. Nothing is sent to customers and no business data is changed. Then the support agent's test
   runs on practice conversations.
4. If it does not pass, nothing is switched on, and the result goes to ChatGPT for the next decision.

## Later, not urgent

HQ uses a key whose name ends in `temp-rakib`, which marks it as temporary. A dedicated key for
HQ in its own workspace would show HQ's spending separately and let the temporary key be retired. That
is a small change on the Credentials screen, whenever convenient.
