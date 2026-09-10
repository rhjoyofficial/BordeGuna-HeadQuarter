# ADR-032: Agents decide what should happen; deterministic infrastructure decides how it progresses

**Status:** accepted (10 Sep 2026). Already true of the built system; written down so it stays true.

## Context

There is a tempting shape for an agentic system in which the model holds the process: it remembers
what step the refund is on, decides when to retry, notices a deadline, and picks up where it left off
after a failure. It demonstrates well and it is the wrong architecture for a business that must still
be running in ten years.

A model's context is not durable state. It is lost on a crash, it is not transactional, it cannot be
queried, it has no deadline, it cannot be deduplicated, and it is not the same after a provider
upgrade. A business process that lives inside it is a business process whose reliability is a
property of a vendor's context window.

The concrete question that settles it: **a refund is half-processed when the model provider is
swapped.** With durable state in the scheduler, the process continues on the new provider without
noticing. With durable state in the model, the process is gone and nobody can say what stage it
reached.

## Decision

**Agents decide *what* should happen. Durable workflow and scheduler infrastructure decides *how* it
progresses.**

Retries, backoff, timing, deduplication, state transitions, deadlines, escalation and compensation
belong to `scheduled_jobs`, `job_runs`, the event inbox/outbox and the approval lifecycle - all of
which are rows in Postgres with a transaction around them.

Three consequences follow:

- **No durable business state lives in a prompt, a conversation, or a model's memory.** A run may
  carry working context; the process may not.
- **A checkpointed process resumes without the agent.** M3 already works this way: a call requiring
  approval parks, the worker is released, and the run resumes from the approval decision rather than
  from a model remembering it was waiting.
- **The architecture must remain valid if the model provider changes mid-process.** That is the test
  to apply to any proposed workflow design, and it is a yes-or-no question.

⚠ The inverse is equally binding: **deterministic infrastructure does not make business decisions.**
The scheduler does not decide whether a refund is warranted. Merging the two in either direction
produces a system where nobody can say which component decided something.

## Alternatives

- **Let the agent loop drive long-running processes.** Rejected above.
- **Adopt a workflow engine (Temporal and similar).** Not rejected on merit - genuinely good, and
  possibly right later. Rejected for now on ADR-012's reasoning: it is another stateful service on a
  2 vCPU box that already runs the ERP, and `scheduled_jobs` plus Horizon already provide durable
  retries, backoff and dedup for the processes that exist. Revisit on evidence, not on ambition.
- **Write it in a design document rather than an ADR.** Rejected. It is the kind of rule that gets
  eroded one convenient exception at a time, and an ADR is what an exception has to argue with.

## Consequences

Processes survive a model change, a provider outage, a deploy and a crash, because they were never
inside the model.

⚠ It costs expressiveness. Some things an agent could "just handle" in one loop become a job, a state
column and a transition. That is the price of a process that can be queried, resumed and audited, and
for a business operating system it is worth paying every time.
