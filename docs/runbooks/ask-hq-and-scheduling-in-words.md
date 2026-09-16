# Ask HQ and scheduling in words

Both are built and switched off (`docs/78` C.3, `docs/48` K and L). This page says what each does,
what it will not do, and what switching it on involves. Switching either on in production is a
decision taken after the Executive's shadow week, not a step in this runbook.

## What is switched on where

| Switch | Turns on | Also needs |
| --- | --- | --- |
| Flag `ask_hq` | The Ask HQ page and its sidebar item | `ai.runtime` on, and an active agent with an enabling person, before anything is asked |
| Flag `scheduler.nl` | The "Describe a job" page on the scheduler | `ai.runtime` on before a sentence is read |

Flags are on HQ, **Feature flags**. With a flag on and the runtime off, the page opens, records what
you typed, and says plainly that nothing was asked.

## Ask HQ

- **Who can use it:** the owner and operator roles (`hq.ask.use`), and only someone who sees every
  business and holds every permission the chosen agent's tools need. The agent works with its
  enabling person's reach, so HQ will not lend that reach to anyone narrower; the page says why.
- **What it takes:** your words, the Executive or the Analytics agent, and optionally a business.
  Nothing else; there is no way to name a tool, a model or an autonomy level from the page.
- **What you get:** the agent's answer, marked **Shadow** while the agent is at A0 (a proposal for a
  person to review; nothing was done because of it), the tool calls it made, and a link to the full
  run on HQ, Agents. The page refreshes by itself while an answer is on its way.
- **If it fails:** a paused agent or one with no enabling person says so on the answer; a runtime
  switched off before the answer arrived says that too.

## Scheduling in words

1. HQ, Scheduler, **Describe a job**. Write what should run and when, in Bangla or English, for
   example "every morning at 8 check the order facts for LaLaDia".
2. **Read it.** HQ asks the model for a proposal, then checks every part against what it holds: the
   tool exists, its arguments are right, the business and store are real and yours, the time is in
   the future, the timezone is real. Anything unclear comes back as a question; change the sentence
   and read it again.
3. The preview shows the job in words, its first run in its own timezone, and whether it will wait
   for approval. **Create this job** makes exactly what is shown (the preview lasts 15 minutes and
   works once, for you only).
4. A job that runs a tool able to change something (R3 or higher) is created waiting. Open it on the
   scheduler and **Approve job** or **Reject**. Until it is approved, neither the scheduler nor "Run
   now" will run it.

Jobs made this way say `nl` as their origin and keep your sentence. Jobs triggered by an event cannot
be made from a sentence yet, because nothing starts such jobs; HQ asks for a clock schedule instead.
