# The inbox

Where every customer message on Messenger, Instagram DMs and Facebook comments lands once the
Meta inbound flag is on (`docs/72`, `docs/78` B.1). Until that flag is on, the inbox shows only the
simulated threads a person kept on purpose (`hq:conversation:simulate --keep`, refused on production).

## What a thread is

One person, one channel, one account. On Messenger and Instagram that is the person and the Page or
account; for a comment it is one person's comments on one post. The same person on two Pages is two
threads and one customer.

Every customer message is **untrusted** and labelled so. Nothing a customer writes changes a state,
a role, a permission or the agent's authority. A message dressed as a "system notice" is a customer
message.

## The states, and who owns the thread

| State | Owner | How it gets there |
| --- | --- | --- |
| `human_required` | a person | every message while the support agent is paused; money, a complaint, a damaged item, a request for a person, a second message while the agent was waiting |
| `ai_active` | the agent | a plain question while the agent is active |
| `ai_waiting` | the agent | the agent proposed a reply and waits |
| `human_active` | a person | you clicked "take over" |
| `human_resolved` | nobody yet | you clicked "resolve" |
| `ai_resumed` | the agent | the customer wrote again after a resolve, with the agent active |
| `closed` | nobody | you closed it; the next message opens a new thread |

The escalation words (refund, complaint, wrong item, "a person", and their Bangla and Banglish forms)
are checked in code before the agent sees anything. The agent may escalate more; it cannot escalate less.

## What you can do on a thread

- **Take over**: the thread is yours; the agent will not touch it.
- **Reply**: through the gateway, under your name. Until gate P3-G4 no send provider exists, so a
  reply is refused and kept as an internal note if you tick that box.
- **Internal note**: seen by colleagues only, never by the customer.
- **Resolve**, **close**, **assign** to a colleague, **switch the agent off for this thread**.
- **A proposal the agent made** shows as a card: send as it is, send edited, discard. Each is
  recorded as a decision beside what was proposed; that record is the alignment corpus.
- **Remember a fact** (after resolve or close, on any thread with a customer): a keyed fact
  (delivery notes, preferred language, preferred contact time, about this person) replaces the
  previous value for its key; "something that happened" is kept as it was and never edited. Memory
  is shown with its source and date, and the agent is told the same thing you see: memory is not
  truth, the order facts win. "Forget" closes a fact; the row stays on the record.
- **Shadow comparison** (during gate P3-G3): the agent's proposal beside what the n8n bot actually
  sent, with four verdicts: same, better, worse, different. A proposal nothing answered within a
  day is marked unmeasured by the sweep.

## The hand-over ping (docs/95 stream W2a)

The moment a thread enters `human_required`, HQ tells a person on every notification channel it has:
the Telegram bot and chat from `ALERT_TELEGRAM_BOT_TOKEN` / `ALERT_TELEGRAM_CHAT_ID` (the same ones the
alerts use), and the in-app record (an audit row `notification.raised`). The message carries the
business and brand, the channel, a **reason code**, and the link `/inbox/{uuid}`. It never carries
what the customer wrote, their name or their phone: the words are read in HQ, behind the permission.

| Reason code | What happened |
| --- | --- |
| `asks_for_person` | the customer asked for a person (the core's escalation words, before any agent) |
| `money_or_complaint` | money, a refund, a complaint, a damaged item (the core, before any agent) |
| `second_inbound_while_waiting` | the customer wrote again while the agent was waiting on them |
| `agent_paused` | the support agent is paused, so the thread opened needing a person |
| `ai_disabled` | a person switched the agent off for this thread |
| `agent_cannot_serve` | the agent was woken and could not serve: a silent hand-over the runtime decided, a refusal, a failure, or no reply proposed |

One ping per thread per **entry** into the state, never per message: a second message on a thread
that already needs a person sends nothing; a thread you resolved that comes back needing a person
sends again. The ping waits for the database commit, so a simulated replay that is rolled back sends
nothing. Switch it off without a deploy with `hq.inbox.handover_notify = false` (config/hq.php);
the thread still shows in the inbox and the badge.

On the thread page the same reason is the amber card **Why a person is needed** (the code, the
sentence, who moved it and when), above the **Recent orders** card: the known customer's last five
orders from HQ's fact table (ref, store, status, payment, placed, gross). A stranger shows "No known
orders".

## When the agent is woken (inert today)

`conversation.message_received` is handled by `SupportWakeUpHandler`. It wakes the support agent for
a message ONLY when every one of these holds, read fresh from the database: `channels.meta.inbound`
on, `ai.runtime` on, the `support` row `active` with an enabling user, the thread `ai_active` or
`ai_resumed` and not switched off. In production today the first is off, so nothing is woken and
the reason is on the audit log as `conversation.wake_up_skipped`. When it does wake, the run is on
the `ai` queue, bound to the thread, trigger `conversation`, one run per message; at A0 the reply is
a proposal card on this page and nothing is sent. A run that proposes nothing hands the thread to a
person as `agent_cannot_serve`. Detail: `docs/96-live-wake-up-record.md`.

## When something looks wrong

- A thread from a Page HQ does not hold never appears: the event dead-letters with the reason on
  the Events screen. Add the Page as a platform account, then replay the event.
- A message counted twice is not possible: the webhook dedupes on the body, the core on the
  provider's message id. If you see two, they had two ids.
- A customer shown as "stranger" has no phone or order linking them yet. A merge with a known
  customer is your decision on the Customers screen; memory follows the merge.
