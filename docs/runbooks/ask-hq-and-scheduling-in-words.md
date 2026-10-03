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

- **Who can use it:** the owner and operator roles (`hq.ask.use`). Since ADR-047 the answer is
  narrowed to the person asking: the agent works with what the asker AND its enabling person may
  both do (businesses, brands, tool permissions), never with the enabling person's reach alone. So
  somebody limited to one business may ask, and is answered about that business only. HQ refuses,
  and the page says why, only when the two people have nothing in common for the agent to work with
  (no shared business, or none of the permissions its tools need). Every run records who asked, who
  enabled, and what was narrowed and why (HQ, Agents, the run; and the audit log).
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

Jobs made this way say `nl` as their origin and keep your sentence.

## Jobs that run when something happens

A job of kind `event` has no clock. It names an event type the way HQ does (`order.created`,
`shipment.delivered`, `inventory.low`, `coupon.expired`, `customer.registered`), and the event drain
(`hq:events:process`, every minute) starts it: once an event has reached its handlers, every active,
approved event job of that type gets one run for that event.

- **Once per event.** The run names the event (`job_runs.event_id`), and a unique index refuses a
  second run for the same event and job. A replayed event (`hq:events:replay`), a redelivered webhook or
  a retry after a handler failure creates nothing. Two different events of the same type are two runs.
- **Business scope.** A job with no business takes the event whatever its business. A job of one
  business takes only that business's events. An event of no business reaches only the group-wide jobs.
- **Approval holds.** A job that waits for approval (an R3 or higher tool) does not fire, exactly as
  the clock and "Run now" refuse it. Approving it releases it for the next event, never for the ones
  that passed while it waited. Paused and cancelled jobs do not fire.
- **What the tool sees.** The job's payload, plus `event` (`id`, `type`, `occurred_at`, `business_id`,
  `aggregate_type`, `aggregate_id`, `payload`) when the tool's input schema declares an `event`
  property or accepts extra arguments. A tool that declares neither runs with the payload alone, so the
  gateway never refuses the run for an argument the tool did not ask for.
- **On the scheduler page** such a job shows no next run; its history shows each run with `event` as
  the trigger. The audit log holds one `scheduled_job.event_fired` row per run.

A sentence can make one: "whenever an order is created, check the order facts for LaLaDia". HQ asks
for the event's name if it cannot read one.
