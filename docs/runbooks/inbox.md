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

## When something looks wrong

- A thread from a Page HQ does not hold never appears: the event dead-letters with the reason on
  the Events screen. Add the Page as a platform account, then replay the event.
- A message counted twice is not possible: the webhook dedupes on the body, the core on the
  provider's message id. If you see two, they had two ids.
- A customer shown as "stranger" has no phone or order linking them yet. A merge with a known
  customer is your decision on the Customers screen; memory follows the merge.
