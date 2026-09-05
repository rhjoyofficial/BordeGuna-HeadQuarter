# ADR-013: Behavior Alignment Layer instead of model training

**Status:** accepted (Joy, 5 Sep 2026)

## Context
The requirement "the AI learns how I work and decide" could be read as fine-tuning. Fine-tuning is expensive, slow to iterate, hard to audit, provider-locked and unnecessary at this scale.

## Decision
No fine-tuning or training in Phase 0 (and none planned through Phase 9). "Learns how I work" is implemented as the **Behavior Alignment Layer**: structured user preferences (`owner_preferences`), decision memory (`decision_records`: proposal, final, delta, reason, outcome), SOPs and policies as retrievable `knowledge_documents`, approved examples, approved/rejected actions, edits to AI drafts, human handoff feedback and marked outcomes, outcome evaluation (`evaluations`, weekly replay), and agent memory (doc 09). Context assembly retrieves the closest past decisions and examples for every run.

The layer is designed so a fine-tuned or provider-customised model could later be added as one more component fed from the same stores, if evaluation ever shows a gap retrieval cannot close.

## Alternatives
- Fine-tune per agent on transcripts: rejected for cost, auditability and lock-in at this volume.
- Prompt-only "personality": rejected; it does not learn.

## Consequences
Every human decision in HQ must be captured as data from day one (Phase 0 includes the `decision_records` and `owner_preferences` tables even though no agent exists yet). Weekly evaluation is a scheduled job.
